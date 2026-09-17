<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Helpers\Fechas;
use Atankalama\Limpieza\Models\Habitacion;

final class ReportesService
{
    /** @return array<string, mixed> */
    public function kpis(string $desde, string $hasta, string $hotel, ?int $usuarioId = null): array
    {
        return [
            'tiempo_promedio'    => $this->kpiTiempoPromedio($desde, $hasta, $hotel, $usuarioId),
            'tasa_rechazo'       => $this->kpiTasaRechazo($desde, $hasta, $hotel, $usuarioId),
            'eficiencia'         => $this->kpiEficiencia($desde, $hasta, $hotel, $usuarioId),
            'creditos'           => $this->kpiCreditos($desde, $hasta, $hotel, $usuarioId),
            'aprobacion_primera' => $this->kpiAprobacionPrimera($desde, $hasta, $hotel, $usuarioId),
            'productividad'      => $this->kpiProductividad($desde, $hasta, $hotel, $usuarioId),
            'tasa_desmarcados'   => $this->kpiTasaDesmarcados($desde, $hasta, $hotel, $usuarioId),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function trabajadoras(string $desde, string $hasta, string $hotel): array
    {
        $params = Fechas::rangoUtc($desde, $hasta);
        // Incluye a quien solo limpió áreas comunes: sus créditos cuentan (jul-2026),
        // así que debe aparecer en el listado por trabajadora (sus KPIs de piezas
        // saldrán 'sin_datos', lo cual es honesto).
        $hotelCond = $this->hotelCondCreditos($hotel, $params);

        return Database::fetchAll(
            "SELECT DISTINCT ec.usuario_id AS usuario_id, u.nombre
               FROM #__ejecuciones_checklist ec
               JOIN #__usuarios u ON u.id = ec.usuario_id
               JOIN #__habitaciones h ON h.id = ec.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
              WHERE ec.timestamp_inicio >= ? AND ec.timestamp_inicio < ?
                    {$hotelCond}
              ORDER BY u.nombre",
            $params
        );
    }

    /**
     * Resumen mensual: cantidad de habitaciones limpiadas y créditos por trabajador.
     *
     * @return list<array{usuario_id:int, nombre:string, habitaciones:int, creditos:int, creditos_maximos:int}>
     */
    public function resumenMensual(int $anio, int $mes, string $hotel): array
    {
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = date('Y-m-t', strtotime($desde));
        $params = Fechas::rangoUtc($desde, $hasta);
        // Créditos incluyen áreas comunes desde jul-2026 (hotelCondCreditos); el conteo
        // 'habitaciones' sigue siendo SOLO piezas de huésped (filtro dentro del CASE).
        $hotelCond = $this->hotelCondCreditos($hotel, $params);

        // Créditos por persona (marcado_por), solo obligatorios, pesados por ic.creditos. Ver docs/creditos-rework.md.
        //   habitaciones      = piezas de huésped donde la persona obtuvo al menos un crédito.
        //   creditos          = suma de ic.creditos de obligatorios marcados y no desmarcados, de ejecuciones no rechazadas (piezas + espacios).
        //   creditos_maximos  = intentos (créditos + créditos de obligatorios que le desmarcó el auditor).
        return Database::fetchAll(
            "SELECT u.id AS usuario_id,
                    u.nombre,
                    COUNT(DISTINCT CASE
                        WHEN ei.marcado = 1 AND ei.desmarcado_por_auditor = 0
                         AND (a.veredicto IS NULL OR a.veredicto <> 'rechazado')
                         AND h.es_espacio_comun = 0
                        THEN ec.habitacion_id END) AS habitaciones,
                    SUM(CASE
                        WHEN ei.marcado = 1 AND ei.desmarcado_por_auditor = 0
                         AND (a.veredicto IS NULL OR a.veredicto <> 'rechazado')
                        THEN ic.creditos ELSE 0 END) AS creditos,
                    SUM(CASE
                        WHEN (ei.marcado = 1 AND ei.desmarcado_por_auditor = 0
                              AND (a.veredicto IS NULL OR a.veredicto <> 'rechazado'))
                          OR ei.desmarcado_por_auditor = 1
                        THEN ic.creditos ELSE 0 END) AS creditos_maximos
               FROM #__ejecuciones_items ei
               JOIN #__usuarios u ON u.id = ei.marcado_por
               JOIN #__ejecuciones_checklist ec ON ec.id = ei.ejecucion_id
               JOIN #__items_checklist ic ON ic.id = ei.item_id AND ic.obligatorio = 1
               JOIN #__habitaciones h ON h.id = ec.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
          LEFT JOIN #__auditorias a ON a.ejecucion_id = ec.id
              WHERE ec.estado IN ('completada', 'auditada')
                AND ei.marcado_por IS NOT NULL
                AND ec.timestamp_inicio >= ? AND ec.timestamp_inicio < ?
                    {$hotelCond}
              GROUP BY u.id, u.nombre
              ORDER BY u.nombre",
            $params
        );
    }

    /** @return list<array<string, mixed>> */
    public function kpisPorTrabajadora(string $desde, string $hasta, string $hotel): array
    {
        $lista = $this->trabajadoras($desde, $hasta, $hotel);
        $result = [];
        foreach ($lista as $t) {
            $uid = (int) $t['usuario_id'];
            $result[] = [
                'usuario_id' => $uid,
                'nombre'     => $t['nombre'],
                'kpis'       => $this->kpis($desde, $hasta, $hotel, $uid),
            ];
        }
        return $result;
    }

    public function exportarCsv(string $desde, string $hasta, string $hotel, ?int $usuarioId = null): string
    {
        $kpis           = $this->kpis($desde, $hasta, $hotel, $usuarioId);
        $porTrabajadora = $usuarioId === null ? $this->kpisPorTrabajadora($desde, $hasta, $hotel) : [];

        $hotelLabel = match ($hotel) {
            '1_sur' => 'Atankalama',
            'inn'   => 'Atankalama INN',
            default => 'Ambos hoteles',
        };

        $rows = [];

        $rows[] = ['Reporte KPIs Limpieza Hotelera', 'Atankalama Corp'];
        $rows[] = ['Hotel', $hotelLabel, 'Período', "{$desde} al {$hasta}"];
        $rows[] = ['Generado', date('d/m/Y H:i:s')];
        $rows[] = [];
        $rows[] = ['RESUMEN DE KPIs'];
        $rows[] = ['KPI', 'Valor', 'Unidad', 'Meta', 'Estado', 'Contexto'];

        foreach ($this->kpiMetadata() as $clave => $titulo) {
            $k = $kpis[$clave];
            $rows[] = [
                $titulo,
                $k['valor'] ?? '',
                $k['unidad'] ?? '',
                $k['meta'] ?? '',
                $this->estadoLabel($k['estado'] ?? 'sin_datos'),
                $k['contexto'] ?? '',
            ];
        }

        if (!empty($porTrabajadora)) {
            $rows[] = [];
            $rows[] = ['DETALLE POR TRABAJADORA'];
            $rows[] = [
                'Trabajadora',
                'T. Prom. (min)',
                'Rechazo (%)',
                'Eficiencia (%)',
                'Créditos (%)',
                'Aprob. 1ª (%)',
                'Productiv. (hab/día)',
                'Desmarcados (%)',
            ];
            foreach ($porTrabajadora as $t) {
                $k = $t['kpis'];
                $rows[] = [
                    $t['nombre'],
                    $k['tiempo_promedio']['valor'] ?? '',
                    $k['tasa_rechazo']['valor'] ?? '',
                    $k['eficiencia']['valor'] ?? '',
                    $k['creditos']['valor'] ?? '',
                    $k['aprobacion_primera']['valor'] ?? '',
                    $k['productividad']['valor'] ?? '',
                    $k['tasa_desmarcados']['valor'] ?? '',
                ];
            }
        }

        // BOM UTF-8 para que Excel abra correctamente con tildes
        $output = "\xEF\xBB\xBF";
        foreach ($rows as $row) {
            $cols = array_map(
                fn ($cell) => '"' . str_replace('"', '""', (string) $cell) . '"',
                $row
            );
            $output .= implode(';', $cols) . "\r\n";
        }
        return $output;
    }

    /**
     * Resumen mensual de auditorías por auditor (supervisora / recepción).
     *
     * @return list<array{usuario_id:int, nombre:string, total:int, aprobadas:int, aprobadas_observacion:int, rechazadas:int}>
     */
    public function resumenMensualAuditores(int $anio, int $mes, string $hotel): array
    {
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = date('Y-m-t', strtotime($desde));
        $params = Fechas::rangoUtc($desde, $hasta);
        $hotelCond = $this->hotelCond($hotel, $params);

        return Database::fetchAll(
            "SELECT u.id AS usuario_id,
                    u.nombre,
                    COUNT(*) AS total,
                    SUM(CASE WHEN a.veredicto IN ('aprobado', 'aprobado_automatico') THEN 1 ELSE 0 END) AS aprobadas,
                    SUM(CASE WHEN a.veredicto='aprobado_con_observacion' THEN 1 ELSE 0 END) AS aprobadas_observacion,
                    SUM(CASE WHEN a.veredicto='rechazado' THEN 1 ELSE 0 END) AS rechazadas
               FROM #__auditorias a
               JOIN #__usuarios u ON u.id = a.auditor_id
               JOIN #__habitaciones h ON h.id = a.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
              WHERE a.created_at >= ? AND a.created_at < ?
                    {$hotelCond}
              GROUP BY u.id, u.nombre
              ORDER BY u.nombre",
            $params
        );
    }

    /**
     * CSV del resumen mensual de auditorías.
     */
    public function exportarCsvMensualAuditores(int $anio, int $mes, string $hotel): string
    {
        $filas = $this->resumenMensualAuditores($anio, $mes, $hotel);

        $hotelLabel = match ($hotel) {
            '1_sur' => 'Atankalama',
            'inn'   => 'Atankalama INN',
            default => 'Ambos hoteles',
        };
        $meses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];

        $rows = [];
        $rows[] = ['Resumen mensual de inspecciones por inspector', 'Atankalama Corp'];
        $rows[] = ['Hotel', $hotelLabel, 'Mes', "{$meses[$mes]} {$anio}"];
        $rows[] = ['Generado', date('d/m/Y H:i:s')];
        $rows[] = [];
        $rows[] = ['Inspector', 'Total inspeccionadas', 'Aprobadas', 'Aprobadas con observación', 'Rechazadas'];

        $totT = $totA = $totO = $totR = 0;
        foreach ($filas as $f) {
            $rows[] = [
                $f['nombre'],
                (int) $f['total'],
                (int) $f['aprobadas'],
                (int) $f['aprobadas_observacion'],
                (int) $f['rechazadas'],
            ];
            $totT += (int) $f['total'];
            $totA += (int) $f['aprobadas'];
            $totO += (int) $f['aprobadas_observacion'];
            $totR += (int) $f['rechazadas'];
        }
        if (!empty($filas)) {
            $rows[] = [];
            $rows[] = ['TOTAL', $totT, $totA, $totO, $totR];
        }

        $output = "\xEF\xBB\xBF";
        foreach ($rows as $row) {
            $cols = array_map(
                fn ($cell) => '"' . str_replace('"', '""', (string) $cell) . '"',
                $row
            );
            $output .= implode(';', $cols) . "\r\n";
        }
        return $output;
    }

    /**
     * CSV del resumen mensual (habitaciones + créditos por trabajador).
     */
    public function exportarCsvMensual(int $anio, int $mes, string $hotel): string
    {
        $filas = $this->resumenMensual($anio, $mes, $hotel);

        $hotelLabel = match ($hotel) {
            '1_sur' => 'Atankalama',
            'inn'   => 'Atankalama INN',
            default => 'Ambos hoteles',
        };
        $meses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];

        $rows = [];
        $rows[] = ['Resumen mensual de limpieza por trabajador', 'Atankalama Corp'];
        $rows[] = ['Hotel', $hotelLabel, 'Mes', "{$meses[$mes]} {$anio}"];
        $rows[] = ['Generado', date('d/m/Y H:i:s')];
        $rows[] = [];
        $rows[] = ['Trabajador', 'Habitaciones limpiadas', 'Créditos obtenidos', 'Créditos máximos', '% Créditos'];

        $totalHab = 0;
        $totalCre = 0;
        $totalMax = 0;
        foreach ($filas as $f) {
            $hab = (int) $f['habitaciones'];
            $cre = (int) $f['creditos'];
            $max = (int) $f['creditos_maximos'];
            $pct = $max > 0 ? round($cre / $max * 100, 1) : '';
            $rows[] = [$f['nombre'], $hab, $cre, $max, $pct];
            $totalHab += $hab;
            $totalCre += $cre;
            $totalMax += $max;
        }

        if (!empty($filas)) {
            $rows[] = [];
            $pctTotal = $totalMax > 0 ? round($totalCre / $totalMax * 100, 1) : '';
            $rows[] = ['TOTAL', $totalHab, $totalCre, $totalMax, $pctTotal];
        }

        $output = "\xEF\xBB\xBF";
        foreach ($rows as $row) {
            $cols = array_map(
                fn ($cell) => '"' . str_replace('"', '""', (string) $cell) . '"',
                $row
            );
            $output .= implode(';', $cols) . "\r\n";
        }
        return $output;
    }

    /**
     * Reporte de auditorías pendientes al corte de las 23:50 de $fecha, separado por
     * turno (mañana/tarde, según la hora local de término de la limpieza — el corte
     * es 18:00). Sirve tanto para "hoy en curso" como para reconstruir un día pasado:
     * una ejecución cuenta como "no auditada a tiempo" si no tiene auditoría, o si la
     * tiene pero con created_at posterior al corte de ESE día (auditada tarde). Así un
     * día pasado no "se limpia" solo porque alguien la auditó al día siguiente. Ver
     * docs/decisiones.md y CLAUDE.md del hotel (reporte pedido por gerencia).
     *
     * Excluye áreas comunes (es_espacio_comun), igual que el resto de reportes.
     *
     * IMPORTANTE — piezas resueltas por Cloudbeds sin auditoría (CloudbedsSyncService,
     * decisión de negocio 2026-08-21: "Cloudbeds es la fuente madre del estado real"):
     * ese cron fuerza la habitación a aprobada/sucia SIN crear fila en `auditorias` ni
     * marcar la ejecución como 'auditada'. Sin este filtro, esas ejecuciones quedarían
     * "sin auditar" para siempre en el reporte aunque la pieza ya esté resuelta y
     * Recepción no la vea en la bandeja (bug detectado y confirmado con datos reales
     * el 2026-09-10). Por eso una ejecución sin auditoría real solo cuenta como
     * pendiente si la habitación SIGUE, ahora mismo, en completada_pendiente_auditoria
     * — igual que bandejaPendientes(). Si ya se resolvió por otra vía, se excluye del
     * todo (decisión confirmada con el usuario: no se cuenta ni se lista aparte).
     * Limitación conocida: para una fecha PASADA esto mira el estado ACTUAL de la
     * habitación, no el estado que tenía al corte de ese día — si Cloudbeds resuelve
     * una pieza recién días después, el reporte histórico de ese día ya no la mostrará.
     *
     * @return array{fecha:string, hotel:string, corte:string, turnos:array<string, array{total:int, pendientes:list<array<string,mixed>>}>}
     */
    public function auditoriasPendientes(string $fecha, string $hotel): array
    {
        $rango = Fechas::rangoUtcDelDia($fecha);
        $corte = Fechas::instanteLocalUtc($fecha, '23:50');

        $params = [$rango[0], $rango[1]];
        $h = $this->hotelCond($hotel, $params);

        $filas = Database::fetchAll(
            "SELECT ec.id AS ejecucion_id, ec.habitacion_id, ec.timestamp_fin,
                    h.numero, h.es_nochero, h.estado AS habitacion_estado_actual,
                    ho.codigo AS hotel_codigo, ho.nombre AS hotel_nombre,
                    a.id AS auditoria_id, a.created_at AS auditoria_created_at
               FROM #__ejecuciones_checklist ec
               JOIN #__habitaciones h ON h.id = ec.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
          LEFT JOIN #__auditorias a ON a.ejecucion_id = ec.id
              WHERE ec.estado IN ('completada', 'auditada')
                AND ec.timestamp_fin IS NOT NULL
                AND ec.timestamp_fin >= ? AND ec.timestamp_fin < ?
                    {$h}
           ORDER BY ho.codigo, h.numero, ec.timestamp_fin",
            $params
        );

        $turnos = [
            'mañana' => ['total' => 0, 'pendientes' => []],
            'tarde'  => ['total' => 0, 'pendientes' => []],
        ];

        foreach ($filas as $f) {
            $timestampFin = (string) $f['timestamp_fin'];
            $turno = Fechas::horaLocalDeUtc($timestampFin) < 18 ? 'mañana' : 'tarde';
            $turnos[$turno]['total']++;

            $estadoAuditoria = null;
            if ($f['auditoria_id'] !== null) {
                $auditadaATiempo = (string) $f['auditoria_created_at'] < $corte;
                if (!$auditadaATiempo) {
                    $estadoAuditoria = 'auditada_tarde';
                }
            } elseif ($f['habitacion_estado_actual'] === Habitacion::ESTADO_COMPLETADA_PENDIENTE_AUDITORIA) {
                // Sin auditoría real Y la pieza sigue esperando auditar ahora mismo:
                // coincide con la bandeja. Si no está en este estado, Cloudbeds (u otro
                // mecanismo) ya la resolvió sin auditoría — se excluye, ver docblock.
                $estadoAuditoria = 'sin_auditar';
            }

            if ($estadoAuditoria === null) {
                continue;
            }

            $turnos[$turno]['pendientes'][] = [
                'habitacion_id'    => (int) $f['habitacion_id'],
                'numero'           => $f['numero'],
                'hotel_codigo'     => $f['hotel_codigo'],
                'hotel_nombre'     => $f['hotel_nombre'],
                'es_nochero'       => ((int) ($f['es_nochero'] ?? 0)) === 1,
                'hora_termino'     => Fechas::horaMinutoLocalDeUtc($timestampFin),
                'estado_auditoria' => $estadoAuditoria,
            ];
        }

        return [
            'fecha'  => $fecha,
            'hotel'  => $hotel,
            'corte'  => '23:50',
            'turnos' => $turnos,
        ];
    }

    /**
     * CSV del reporte de auditorías pendientes (ver auditoriasPendientes()).
     */
    public function exportarCsvAuditoriasPendientes(string $fecha, string $hotel): string
    {
        $reporte = $this->auditoriasPendientes($fecha, $hotel);

        $hotelLabel = match ($hotel) {
            '1_sur' => 'Atankalama',
            'inn'   => 'Atankalama INN',
            default => 'Ambos hoteles',
        };

        $rows = [];
        $rows[] = ['Reporte de inspecciones pendientes al corte de las 23:50', 'Atankalama Corp'];
        $rows[] = ['Hotel', $hotelLabel, 'Fecha', date('d/m/Y', strtotime($fecha))];
        $rows[] = ['Generado', date('d/m/Y H:i:s')];
        $rows[] = ['Criterio', 'Sin inspeccionar a tiempo = sin inspección registrada antes de las 23:50 de la fecha del reporte.'];

        $tituloTurno = [
            'mañana' => 'TURNO MAÑANA (antes de las 18:00)',
            'tarde'  => 'TURNO TARDE (18:00 en adelante)',
        ];
        foreach (['mañana', 'tarde'] as $turno) {
            $datos = $reporte['turnos'][$turno];
            $rows[] = [];
            $rows[] = [
                $tituloTurno[$turno],
                "Total limpiadas: {$datos['total']}",
                'Sin inspeccionar a tiempo: ' . count($datos['pendientes']),
            ];
            if ($datos['pendientes'] !== []) {
                $rows[] = ['Hotel', 'Habitación', 'Nochero', 'Hora término', 'Estado inspección'];
                foreach ($datos['pendientes'] as $p) {
                    $rows[] = [
                        match ($p['hotel_codigo']) {
                            '1_sur' => 'Atankalama',
                            'inn'   => 'Atankalama INN',
                            default => $p['hotel_codigo'],
                        },
                        $p['numero'],
                        $p['es_nochero'] ? 'Sí' : 'No',
                        $p['hora_termino'],
                        $p['estado_auditoria'] === 'sin_auditar' ? 'Sin inspeccionar' : 'Inspeccionada fuera de plazo',
                    ];
                }
            }
        }

        $output = "\xEF\xBB\xBF";
        foreach ($rows as $row) {
            $cols = array_map(
                fn ($cell) => '"' . str_replace('"', '""', (string) $cell) . '"',
                $row
            );
            $output .= implode(';', $cols) . "\r\n";
        }
        return $output;
    }

    /**
     * Usuarios con rol Admin, activos y con email registrado — destinatarios del
     * correo diario del reporte de auditorías pendientes.
     *
     * @return list<array{id:int, nombre:string, email:string}>
     */
    public function destinatariosAdminConEmail(): array
    {
        return Database::fetchAll(
            "SELECT DISTINCT u.id, u.nombre, u.email
               FROM #__usuarios u
               JOIN #__usuarios_roles ur ON ur.usuario_id = u.id
               JOIN #__roles r ON r.id = ur.rol_id
              WHERE r.nombre = 'Admin'
                AND u.activo = 1
                AND u.email IS NOT NULL AND u.email <> ''
              ORDER BY u.nombre"
        );
    }

    // ─── KPIs individuales ────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function kpiTiempoPromedio(string $desde, string $hasta, string $hotel, ?int $usuarioId): array
    {
        $params = Fechas::rangoUtc($desde, $hasta);
        $h = $this->hotelCond($hotel, $params);
        $u = $this->userCond($usuarioId, $params, 'ec');

        $fila = Database::fetchOne(
            "SELECT ROUND(AVG(" . Database::diffMinutosSql('ec.timestamp_inicio', 'ec.timestamp_fin') . "), 1) AS valor,
                    COUNT(*) AS total
               FROM #__ejecuciones_checklist ec
               JOIN #__habitaciones h ON h.id = ec.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
              WHERE ec.timestamp_fin IS NOT NULL
                AND ec.estado IN ('completada', 'auditada')
                AND ec.timestamp_inicio >= ? AND ec.timestamp_inicio < ?
                    {$h}{$u}",
            $params
        );

        $total = (int) ($fila['total'] ?? 0);
        $valor = $total > 0 ? round((float) $fila['valor'], 1) : null;
        $meta  = 30.0;

        return [
            'valor'    => $valor,
            'unidad'   => 'min',
            'meta'     => $meta,
            'contexto' => "{$total} ejecuciones",
            'estado'   => $valor === null ? 'sin_datos' : ($valor <= $meta ? 'ok' : ($valor <= $meta * 1.15 ? 'alerta' : 'critico')),
        ];
    }

    /** @return array<string, mixed> */
    private function kpiTasaRechazo(string $desde, string $hasta, string $hotel, ?int $usuarioId): array
    {
        $params = Fechas::rangoUtc($desde, $hasta);
        $h = $this->hotelCond($hotel, $params);
        // Filtro por la trabajadora que limpió (no el auditor)
        $u = '';
        if ($usuarioId !== null) {
            $params[] = $usuarioId;
            $u = ' AND ec.usuario_id = ?';
        }

        $fila = Database::fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN a.veredicto = 'rechazado' THEN 1 ELSE 0 END) AS rechazadas
               FROM #__auditorias a
               JOIN #__habitaciones h ON h.id = a.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
               JOIN #__ejecuciones_checklist ec ON ec.id = a.ejecucion_id
              WHERE a.created_at >= ? AND a.created_at < ?
                    {$h}{$u}",
            $params
        );

        $total = (int) ($fila['total'] ?? 0);
        if ($total === 0) {
            return ['valor' => null, 'unidad' => '%', 'meta' => 5.0, 'contexto' => '0 inspecciones', 'estado' => 'sin_datos'];
        }

        $rechazadas = (int) ($fila['rechazadas'] ?? 0);
        $valor      = round($rechazadas / $total * 100, 1);
        $meta       = 5.0;

        return [
            'valor'    => $valor,
            'unidad'   => '%',
            'meta'     => $meta,
            'contexto' => "{$rechazadas} de {$total} inspeccionadas",
            'estado'   => $valor <= $meta ? 'ok' : ($valor <= $meta * 1.4 ? 'alerta' : 'critico'),
        ];
    }

    /** @return array<string, mixed> */
    private function kpiEficiencia(string $desde, string $hasta, string $hotel, ?int $usuarioId): array
    {
        // OJO: acá NO va la conversión a UTC. Este KPI filtra por asignaciones.fecha,
        // que es un DATE con la fecha LOCAL del turno (no un timestamp UTC): comparar
        // fecha local contra fecha local ya es correcto.
        $params = [$desde, $hasta];
        $h = $this->hotelCond($hotel, $params);
        $u = '';
        if ($usuarioId !== null) {
            $params[] = $usuarioId;
            $u = ' AND asg.usuario_id = ?';
        }

        $fila = Database::fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN ec.estado IN ('completada', 'auditada') THEN 1 ELSE 0 END) AS completadas
               FROM #__asignaciones asg
               JOIN #__habitaciones h ON h.id = asg.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
          LEFT JOIN #__ejecuciones_checklist ec ON ec.asignacion_id = asg.id
              WHERE asg.fecha BETWEEN ? AND ?
                AND asg.activa = 1
                    {$h}{$u}",
            $params
        );

        $total = (int) ($fila['total'] ?? 0);
        if ($total === 0) {
            return ['valor' => null, 'unidad' => '%', 'meta' => 85.0, 'contexto' => '0 asignaciones', 'estado' => 'sin_datos'];
        }

        $completadas = (int) ($fila['completadas'] ?? 0);
        $valor       = round($completadas / $total * 100, 1);
        $meta        = 85.0;

        return [
            'valor'    => $valor,
            'unidad'   => '%',
            'meta'     => $meta,
            'contexto' => "{$completadas} de {$total} asignadas",
            'estado'   => $valor >= $meta ? 'ok' : ($valor >= 75.0 ? 'alerta' : 'critico'),
        ];
    }

    /** @return array<string, mixed> */
    private function kpiCreditos(string $desde, string $hasta, string $hotel, ?int $usuarioId): array
    {
        // Créditos por persona (marcado_por), solo obligatorios, pesados por ic.creditos. Ver docs/creditos-rework.md.
        // Numerador = suma de ic.creditos de obligatorios marcados y no desmarcados, de ejecuciones
        //             NO rechazadas (así los ítems heredados en la re-limpieza no se doble-cuentan).
        $pC = Fechas::rangoUtc($desde, $hasta);
        $hC = $this->hotelCondCreditos($hotel, $pC); // incluye áreas comunes (jul-2026)
        $uC = $this->marcadoPorCond($usuarioId, $pC);
        $creditos = (int) Database::fetchColumn(
            "SELECT COALESCE(SUM(ic.creditos), 0)
               FROM #__ejecuciones_items ei
               JOIN #__ejecuciones_checklist ec ON ec.id = ei.ejecucion_id
               JOIN #__items_checklist ic ON ic.id = ei.item_id
               JOIN #__habitaciones h ON h.id = ec.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
          LEFT JOIN #__auditorias a ON a.ejecucion_id = ec.id
              WHERE ei.marcado = 1 AND ei.desmarcado_por_auditor = 0
                AND ic.obligatorio = 1
                AND (a.veredicto IS NULL OR a.veredicto <> 'rechazado')
                AND ec.estado IN ('completada', 'auditada')
                AND ec.timestamp_inicio >= ? AND ec.timestamp_inicio < ?
                    {$hC}{$uC}",
            $pC
        );

        // Intentos fallidos = créditos de obligatorios desmarcados por el auditor (atribuidos a
        // quien los marcó mal). El denominador = créditos + fallidos → el % castiga el error.
        $pD = Fechas::rangoUtc($desde, $hasta);
        $hD = $this->hotelCondCreditos($hotel, $pD); // simetría con el numerador
        $uD = $this->marcadoPorCond($usuarioId, $pD);
        $desmarcados = (int) Database::fetchColumn(
            "SELECT COALESCE(SUM(ic.creditos), 0)
               FROM #__ejecuciones_items ei
               JOIN #__ejecuciones_checklist ec ON ec.id = ei.ejecucion_id
               JOIN #__items_checklist ic ON ic.id = ei.item_id
               JOIN #__habitaciones h ON h.id = ec.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
              WHERE ei.desmarcado_por_auditor = 1 AND ic.obligatorio = 1
                AND ei.marcado_por IS NOT NULL
                AND ec.estado IN ('completada', 'auditada')
                AND ec.timestamp_inicio >= ? AND ec.timestamp_inicio < ?
                    {$hD}{$uD}",
            $pD
        );

        $total = $creditos + $desmarcados; // créditos de obligatorios intentados
        if ($total === 0) {
            return ['valor' => null, 'unidad' => '%', 'meta' => 90.0, 'contexto' => '0 créditos', 'estado' => 'sin_datos'];
        }

        $valor = round($creditos / $total * 100, 1);
        $meta  = 90.0;

        return [
            'valor'    => $valor,
            'unidad'   => '%',
            'meta'     => $meta,
            'contexto' => "{$creditos} / {$total} créditos",
            'estado'   => $valor >= $meta ? 'ok' : ($valor >= 80.0 ? 'alerta' : 'critico'),
        ];
    }

    /** @return array<string, mixed> */
    private function kpiAprobacionPrimera(string $desde, string $hasta, string $hotel, ?int $usuarioId): array
    {
        $params = Fechas::rangoUtc($desde, $hasta);
        $h = $this->hotelCond($hotel, $params);
        $u = '';
        if ($usuarioId !== null) {
            $params[] = $usuarioId;
            $u = ' AND ec.usuario_id = ?';
        }

        $fila = Database::fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN a.veredicto IN ('aprobado', 'aprobado_con_observacion') THEN 1 ELSE 0 END) AS aprobadas
               FROM #__auditorias a
               JOIN #__habitaciones h ON h.id = a.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
               JOIN #__ejecuciones_checklist ec ON ec.id = a.ejecucion_id
              WHERE a.created_at >= ? AND a.created_at < ?
                -- aprobado_automatico (cierre de día 23:55) queda FUERA de este KPI: mide
                -- desempeño real del trabajador, y una pieza sin auditoría real no aporta
                -- evidencia de si la limpieza era buena o no.
                AND a.veredicto != 'aprobado_automatico'
                    {$h}{$u}",
            $params
        );

        $total = (int) ($fila['total'] ?? 0);
        if ($total === 0) {
            return ['valor' => null, 'unidad' => '%', 'meta' => 95.0, 'contexto' => '0 inspecciones', 'estado' => 'sin_datos'];
        }

        $aprobadas = (int) ($fila['aprobadas'] ?? 0);
        $valor     = round($aprobadas / $total * 100, 1);
        $meta      = 95.0;

        return [
            'valor'    => $valor,
            'unidad'   => '%',
            'meta'     => $meta,
            'contexto' => "{$aprobadas} de {$total} inspeccionadas",
            'estado'   => $valor >= $meta ? 'ok' : ($valor >= 85.0 ? 'alerta' : 'critico'),
        ];
    }

    /** @return array<string, mixed> */
    private function kpiProductividad(string $desde, string $hasta, string $hotel, ?int $usuarioId): array
    {
        $params = Fechas::rangoUtc($desde, $hasta);
        $h = $this->hotelCond($hotel, $params);
        $u = $this->userCond($usuarioId, $params, 'ec');

        // Los días con actividad se cuentan en PHP, no con COUNT(DISTINCT DATE(...)):
        // DATE() sobre la columna da el día UTC, así que una limpieza a las 18:00 y otra
        // a las 21:00 del MISMO día local caían en dos días UTC distintos y le partían la
        // productividad a la mitad. Agrupar por día local necesita conocer la zona horaria
        // (y su horario de verano), cosa que SQLite y MariaDB no comparten de forma portable.
        $filas = Database::fetchAll(
            "SELECT ec.usuario_id, ec.timestamp_inicio
               FROM #__ejecuciones_checklist ec
               JOIN #__habitaciones h ON h.id = ec.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
              WHERE ec.estado IN ('completada', 'auditada')
                AND ec.timestamp_inicio >= ? AND ec.timestamp_inicio < ?
                    {$h}{$u}",
            $params
        );

        $diasLocales   = [];
        $trabajadorIds = [];
        foreach ($filas as $f) {
            $diasLocales[Fechas::fechaLocalDeUtc((string) $f['timestamp_inicio'])] = true;
            $trabajadorIds[(int) $f['usuario_id']] = true;
        }

        $trabajadoras = count($trabajadorIds);
        $dias         = count($diasLocales);
        $completadas  = count($filas);

        if ($trabajadoras === 0 || $dias === 0) {
            return ['valor' => null, 'unidad' => 'hab/día', 'meta' => null, 'contexto' => '0 completadas', 'estado' => 'sin_datos'];
        }

        $valor = round($completadas / ($trabajadoras * $dias), 1);

        return [
            'valor'    => $valor,
            'unidad'   => 'hab/día',
            'meta'     => null,
            'contexto' => "{$completadas} hab · {$trabajadoras} trabaj. · {$dias} día(s)",
            'estado'   => 'informativo',
        ];
    }

    /** @return array<string, mixed> */
    private function kpiTasaDesmarcados(string $desde, string $hasta, string $hotel, ?int $usuarioId): array
    {
        $params = Fechas::rangoUtc($desde, $hasta);
        $h = $this->hotelCond($hotel, $params);
        $u = $this->userCond($usuarioId, $params, 'ec');

        $fila = Database::fetchOne(
            "SELECT SUM(CASE WHEN ei.marcado = 1 THEN 1 ELSE 0 END)                AS marcados,
                    SUM(CASE WHEN ei.desmarcado_por_auditor = 1 THEN 1 ELSE 0 END) AS desmarcados
               FROM #__ejecuciones_items ei
               JOIN #__ejecuciones_checklist ec ON ec.id = ei.ejecucion_id
               JOIN #__habitaciones h ON h.id = ec.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
              WHERE ec.estado = 'auditada'
                AND ec.timestamp_inicio >= ? AND ec.timestamp_inicio < ?
                    {$h}{$u}",
            $params
        );

        $marcados = (int) ($fila['marcados'] ?? 0);
        if ($marcados === 0) {
            return ['valor' => null, 'unidad' => '%', 'meta' => 3.0, 'contexto' => '0 ítems inspeccionados', 'estado' => 'sin_datos'];
        }

        $desmarcados = (int) ($fila['desmarcados'] ?? 0);
        $valor       = round($desmarcados / $marcados * 100, 1);
        $meta        = 3.0;

        return [
            'valor'    => $valor,
            'unidad'   => '%',
            'meta'     => $meta,
            'contexto' => "{$desmarcados} desmarcados de {$marcados}",
            'estado'   => $valor <= $meta ? 'ok' : ($valor <= $meta * 2 ? 'alerta' : 'critico'),
        ];
    }

    // ─── Ficha de KPIs (docs/kpis-sueldos.md) ─────────────────────────────────
    //
    // Implementa la ficha completa: Trabajador N1 (créditos y habitaciones en DOS ETAPAS
    // auditadas/no auditadas + cobertura derivada, tiempo), N2 (triángulo Esperado·Aprobado·
    // Rechazado → % realización/cumplimiento/calidad/rechazo, eficiencia, créditos por hab,
    // ritmo) y N3 (comparación con el grupo: promedio, Δ y semáforo por σ); y Supervisora
    // N1 (piezas auditadas por veredicto, tiempo por auditación), N2 (cobertura, % rechazo,
    // % aprobación de la sección) y N3 (sección vs metas + tendencia; entre inspectoras).
    //
    // Ventanas: los KPIs del trabajador y la cobertura van por FECHA DE LIMPIEZA
    // (ec.timestamp_inicio); los conteos personales de la inspectora por FECHA DE AUDITORÍA
    // (a.created_at). "Auditoría real" = veredicto humano; 'aprobado_automatico' (cierre de
    // día 23:55) cuenta como aprobada para el trabajador y como NO auditada para la sección.

    /** Veredictos humanos = auditoría real (excluye el cierre automático). */
    private const VEREDICTOS_HUMANOS = "('aprobado', 'aprobado_con_observacion', 'rechazado')";
    /** RUT del usuario técnico que firma el cierre de día automático (no es inspectora). */
    private const RUT_SISTEMA = 'SISTEMA-CRON';
    /** Antifraude del tiempo por auditación: fuera de [30 s, 4 h] se considera ruido (pausas, aperturas accidentales). */
    private const AUDITACION_MIN_MINUTOS = 0.5;
    private const AUDITACION_MAX_MINUTOS = 240.0;
    /**
     * Recuperación gradual de créditos cuando la MISMA persona rehace su pieza rechazada en el mismo
     * ciclo, según cuántos rechazos previos suyos tuvo esa pieza (regla de jefatura, 16/09/2026):
     * aprobada a la primera = 100 %, a la segunda = 50 %, a la tercera o más = 0 %.
     */
    private const RECUPERACION_TRAS_RECHAZO = [0 => 1.0, 1 => 0.5, 2 => 0.0];

    /**
     * Dataset completo de la ficha para el rango/hotel. Shape:
     *   config        → umbrales (σ amarillo/rojo, mínimo de datos, meta de cobertura)
     *   trabajadores  → lista por persona con N1/N2 + 'cmp' (N3 por KPI: delta, z, estado)
     *   comparativa   → por KPI: promedio, sigma, n (población con datos suficientes)
     *   supervisoras  → seccion (N2 vs metas + tendencia), inspectoras (N1 + aporte), comparativa
     *
     * @return array<string, mixed>
     */
    public function fichaKpis(string $desde, string $hasta, string $hotel, bool $incluirSupervisoras = true): array
    {
        $alertas = new AlertasService();
        $config = [
            'sigma_amarillo' => max(1, $alertas->obtenerConfigInt('reportes_sigma_amarillo')),
            'sigma_rojo'     => max(1, $alertas->obtenerConfigInt('reportes_sigma_rojo')),
            'min_datos'      => max(1, $alertas->obtenerConfigInt('reportes_min_datos')),
            'meta_cobertura'  => min(100, max(1, $alertas->obtenerConfigInt('reportes_meta_cobertura'))),
            'meta_rechazo'    => min(100, max(1, $alertas->obtenerConfigInt('reportes_meta_rechazo'))),
            'meta_aprobacion' => min(100, max(1, $alertas->obtenerConfigInt('reportes_meta_aprobacion'))),
        ];
        if ($config['sigma_rojo'] <= $config['sigma_amarillo']) {
            $config['sigma_rojo'] = $config['sigma_amarillo'] + 1;
        }

        $trabajadores = $this->fichaTrabajadores($desde, $hasta, $hotel);
        $comparativa  = $this->fichaComparativa($trabajadores, $config);

        return [
            'config'       => $config,
            'trabajadores' => $trabajadores,
            'comparativa'  => $comparativa,
            // Privacidad jerárquica: la sección de las supervisoras (con sus tiempos) solo viaja a
            // quien tiene reportes.ver_supervisoras; el controller decide, el servicio obedece.
            'supervisoras' => $incluirSupervisoras ? $this->fichaSupervisoras($desde, $hasta, $hotel, $config) : null,
        ];
    }

    /**
     * Trabajador N1 + N2, una fila por persona (unión de quienes marcaron ítems, tienen
     * ejecuciones, asignaciones o rechazos en el rango).
     *
     * @return list<array<string, mixed>>
     */
    private function fichaTrabajadores(string $desde, string $hasta, string $hotel): array
    {
        $filas = [];
        $asegurar = static function (array &$filas, int $uid, string $nombre): void {
            if (!isset($filas[$uid])) {
                $filas[$uid] = [
                    'usuario_id' => $uid, 'nombre' => $nombre,
                    'creditos_auditados' => 0, 'creditos_no_auditados' => 0, 'creditos' => 0, 'creditos_hab' => 0,
                    'hab_auditadas' => 0, 'hab_no_auditadas' => 0, 'habitaciones' => 0,
                    'esperado_hab' => 0, 'esperado_creditos' => 0,
                    'rechazadas_hab' => 0, 'rechazadas_creditos' => 0,
                    'ejecuciones' => 0, 'minutos_total' => 0.0, 'tiempo_promedio' => null,
                ];
            }
        };

        // Ciclo = (pieza, fecha del turno, franja): la unidad en que se cuentan E, A y R — "una vez
        // por habitación por persona" de la ficha, aplicada a cada turno; en un rango largo cada
        // turno vuelve a contar y E·A·R quedan en la misma unidad que los créditos (por limpieza).
        // Toda ejecución cuelga de una asignación (asignacion_id NOT NULL): la fecha local sale de ahí.
        $ciclo = static fn (array $f): string => $f['habitacion_id'] . ':' . $f['fecha'] . ':' . ($f['franja'] ?? '');
        $creditosPorTemplate = []; // cache template_id → créditos obligatorios vigentes

        // ── N2: rechazadas (R) por dueño de la ejecución, una vez por ciclo; pierde TODOS los créditos
        // obligatorios del checklist de esa pieza (versión exacta: ec.template_id). Solo piezas de
        // huésped. Va primero: el instante del rechazo alimenta la regla de auto-relimpieza de abajo.
        $p = Fechas::rangoUtc($desde, $hasta);
        $h = $this->hotelCond($hotel, $p);
        $rechazos = []; // uid → ciclo → lista cronológica de timestamp_inicio de sus intentos rechazados
        foreach (Database::fetchAll(
            "SELECT ec.usuario_id, u.nombre, ec.habitacion_id, ec.timestamp_inicio, ec.template_id, asg.fecha, asg.franja
               FROM #__ejecuciones_checklist ec
               JOIN #__auditorias a ON a.ejecucion_id = ec.id AND a.veredicto = 'rechazado'
               JOIN #__asignaciones asg ON asg.id = ec.asignacion_id
               JOIN #__usuarios u ON u.id = ec.usuario_id
               JOIN #__habitaciones h ON h.id = ec.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
              WHERE ec.timestamp_inicio >= ? AND ec.timestamp_inicio < ?
                    {$h}
              ORDER BY ec.timestamp_inicio",
            $p
        ) as $f) {
            $uid = (int) $f['usuario_id'];
            $c   = $ciclo($f);
            $rechazos[$uid][$c][] = (string) $f['timestamp_inicio'];
            if (count($rechazos[$uid][$c]) > 1) {
                continue; // R y los créditos perdidos se cuentan una vez por ciclo; los rechazos siguientes solo bajan la recuperación
            }
            $asegurar($filas, $uid, (string) $f['nombre']);
            $filas[$uid]['rechazadas_hab']++;
            $filas[$uid]['rechazadas_creditos'] += $this->creditosObligatorios((int) $f['template_id'], $creditosPorTemplate);
        }

        // ── N1: créditos y habitaciones en dos etapas, por quien marcó el ítem (marcado_por), sobre
        // ejecuciones NO rechazadas. Válido = obligatorio marcado y no desmarcado por el auditor
        // (mismo criterio que resumenMensual). A = veredicto humano aprobado; B = sin auditoría real
        // (NULL o cierre automático). Créditos por ejecución (cada limpieza suma); piezas una vez por
        // ciclo. Los créditos totales incluyen áreas comunes (N1); `creditos_hab` y el conteo de piezas
        // no (solo huésped): son la base de los ratios del N2, cuyo Esperado tampoco las incluye.
        // Regla 3 de la ficha + gradualidad de jefatura (16/09): si la persona rehace ELLA MISMA su
        // pieza rechazada en el mismo ciclo, esa re-limpieza no suma pieza (sigue rechazada) y sus
        // créditos se recuperan según el intento: 2° = 50 %, 3° o más = 0 % (RECUPERACION_TRAS_RECHAZO,
        // redondeo por pieza). Si la rehace otra persona, los ítems se atribuyen a quien los marcó
        // (regla de rework vigente, docs/creditos-rework.md).
        $p = Fechas::rangoUtc($desde, $hasta);
        $hc = $this->hotelCondCreditos($hotel, $p);
        $valido = "ei.marcado = 1 AND ei.desmarcado_por_auditor = 0";
        $ciclosA = []; // uid → ciclo → true si alguna ejecución tuvo veredicto humano (A), false si solo B
        foreach (Database::fetchAll(
            "SELECT ei.marcado_por AS usuario_id, u.nombre, ec.id AS ejecucion_id, ec.usuario_id AS dueno_id,
                    ec.habitacion_id, ec.timestamp_inicio, asg.fecha, asg.franja, h.es_espacio_comun, a.veredicto,
                    SUM(CASE WHEN {$valido} THEN ic.creditos ELSE 0 END) AS creditos,
                    SUM(CASE WHEN {$valido} THEN 1 ELSE 0 END) AS items_validos
               FROM #__ejecuciones_items ei
               JOIN #__usuarios u ON u.id = ei.marcado_por
               JOIN #__ejecuciones_checklist ec ON ec.id = ei.ejecucion_id
               JOIN #__asignaciones asg ON asg.id = ec.asignacion_id
               JOIN #__items_checklist ic ON ic.id = ei.item_id AND ic.obligatorio = 1
               JOIN #__habitaciones h ON h.id = ec.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
          LEFT JOIN #__auditorias a ON a.ejecucion_id = ec.id
              WHERE ec.estado IN ('completada', 'auditada')
                AND ei.marcado_por IS NOT NULL
                AND (a.veredicto IS NULL OR a.veredicto <> 'rechazado')
                AND ec.timestamp_inicio >= ? AND ec.timestamp_inicio < ?
                    {$hc}
              GROUP BY ei.marcado_por, u.nombre, ec.id, ec.usuario_id, ec.habitacion_id, ec.timestamp_inicio,
                       asg.fecha, asg.franja, h.es_espacio_comun, a.veredicto
              ORDER BY ec.timestamp_inicio",
            $p
        ) as $f) {
            if ((int) $f['items_validos'] === 0) {
                continue;
            }
            $uid = (int) $f['usuario_id'];
            $c   = $ciclo($f);
            // Rechazos previos de ESTA persona sobre esta pieza en este ciclo (anteriores a esta ejecución).
            $rechazosPrevios = 0;
            foreach ($rechazos[$uid][$c] ?? [] as $inicioRechazo) {
                if (strcmp((string) $f['timestamp_inicio'], $inicioRechazo) > 0) {
                    $rechazosPrevios++;
                }
            }
            // La gradualidad aplica cuando la re-limpieza es SUYA (auto-relimpieza). Si la rehizo OTRA
            // persona, los ítems que heredó a su nombre siguen valiendo lo marcado (rework vigente;
            // decisión pendiente en la ficha), pero la pieza NO le cuenta como hecha (sigue rechazada).
            $factor = (int) $f['dueno_id'] === $uid ? self::RECUPERACION_TRAS_RECHAZO[min($rechazosPrevios, 2)] : 1.0;
            if ($factor <= 0.0) {
                continue;
            }
            $asegurar($filas, $uid, (string) $f['nombre']);
            $humana   = in_array($f['veredicto'], ['aprobado', 'aprobado_con_observacion'], true);
            $creditos = (int) round((int) $f['creditos'] * $factor);
            $filas[$uid][$humana ? 'creditos_auditados' : 'creditos_no_auditados'] += $creditos;
            if ((int) $f['es_espacio_comun'] === 0) {
                $filas[$uid]['creditos_hab'] += $creditos;
                if ($rechazosPrevios === 0) { // tras un rechazo la pieza sigue siendo rechazada para ella, la rehaga quien la rehaga
                    $ciclosA[$uid][$c] = ($ciclosA[$uid][$c] ?? false) || $humana;
                }
            }
        }
        foreach ($ciclosA as $uid => $ciclos) {
            foreach ($ciclos as $humana) {
                $filas[$uid][$humana ? 'hab_auditadas' : 'hab_no_auditadas']++;
            }
        }

        // ── N1/N2: tiempo promedio y minutos trabajados (dueño de la ejecución) ──
        $p = Fechas::rangoUtc($desde, $hasta);
        $h = $this->hotelCond($hotel, $p);
        $diff = Database::diffMinutosSql('ec.timestamp_inicio', 'ec.timestamp_fin');
        foreach (Database::fetchAll(
            "SELECT ec.usuario_id, u.nombre, COUNT(*) AS n,
                    AVG({$diff}) AS tiempo_promedio, SUM({$diff}) AS minutos_total
               FROM #__ejecuciones_checklist ec
               JOIN #__usuarios u ON u.id = ec.usuario_id
               JOIN #__habitaciones h ON h.id = ec.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
              WHERE ec.timestamp_fin IS NOT NULL
                AND ec.estado IN ('completada', 'auditada')
                AND ec.timestamp_inicio >= ? AND ec.timestamp_inicio < ?
                    {$h}
              GROUP BY ec.usuario_id, u.nombre",
            $p
        ) as $f) {
            $uid = (int) $f['usuario_id'];
            $asegurar($filas, $uid, (string) $f['nombre']);
            $filas[$uid]['ejecuciones']     = (int) $f['n'];
            $filas[$uid]['tiempo_promedio'] = round((float) $f['tiempo_promedio'], 1);
            $filas[$uid]['minutos_total']   = round((float) $f['minutos_total'], 1);
        }

        // ── N2: Esperado (E). Una vez por pieza por persona POR CICLO, PEGAJOSO: cuenta aunque la
        // rechacen o la pieza pase a otra persona (regla de la ficha). Una asignación cuenta si
        // siguió activa, si tuvo trabajo (ejecución) o si la pieza pasó después a otra persona; NO
        // cuenta si se retiró sin trabajo y nadie más la tomó (autocancelada porque la pieza ya
        // estaba limpia al llegar el día, o sacada del plan): ahí no había nada que hacer.
        // Créditos = checklist obligatorio vigente: el de la ejecución si la hubo (exacto), si no
        // el que la app elegiría hoy (templateParaHabitacion).
        // asignaciones.fecha es DATE local: se compara contra desde/hasta sin pasar por UTC.
        $pE = [$desde, $hasta];
        $hE = $this->hotelCond($hotel, $pE);
        $esperado = Database::fetchAll(
            "SELECT asg.usuario_id, u.nombre, asg.habitacion_id, asg.fecha, asg.franja, asg.activa,
                    (SELECT ec.template_id FROM #__ejecuciones_checklist ec
                      WHERE ec.asignacion_id = asg.id ORDER BY ec.id DESC LIMIT 1) AS template_id,
                    (SELECT COUNT(*) FROM #__asignaciones o
                      WHERE o.habitacion_id = asg.habitacion_id AND o.fecha = asg.fecha
                        AND COALESCE(o.franja, '') = COALESCE(asg.franja, '')
                        AND o.id > asg.id AND o.usuario_id <> asg.usuario_id) AS pasada_a_otro
               FROM #__asignaciones asg
               JOIN #__usuarios u ON u.id = asg.usuario_id
               JOIN #__habitaciones h ON h.id = asg.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
              WHERE asg.fecha BETWEEN ? AND ?
                    {$hE}
              ORDER BY asg.usuario_id, asg.habitacion_id, asg.fecha, asg.id DESC",
            $pE
        );
        $ciclosE = []; // uid → ciclo → nombre, habitacion_id, cuenta, template_id
        foreach ($esperado as $f) {
            $uid = (int) $f['usuario_id'];
            $c   = $ciclo($f);
            $ciclosE[$uid][$c] ??= [
                'nombre' => (string) $f['nombre'], 'habitacion_id' => (int) $f['habitacion_id'],
                'cuenta' => false, 'template_id' => null,
            ];
            $cuenta = (int) $f['activa'] === 1 || $f['template_id'] !== null || (int) $f['pasada_a_otro'] > 0;
            $ciclosE[$uid][$c]['cuenta'] = $ciclosE[$uid][$c]['cuenta'] || $cuenta;
            if ($ciclosE[$uid][$c]['template_id'] === null && $f['template_id'] !== null) {
                $ciclosE[$uid][$c]['template_id'] = (int) $f['template_id'];
            }
        }
        $templatePorHab = []; // cache habitación → template vigente (sin ejecución)
        $checklist = null;
        $habitaciones = null;
        foreach ($ciclosE as $uid => $ciclos) {
            foreach ($ciclos as $e) {
                if (!$e['cuenta']) {
                    continue;
                }
                $asegurar($filas, $uid, (string) $e['nombre']);
                $templateId = $e['template_id'];
                if ($templateId === null) {
                    $habId = (int) $e['habitacion_id'];
                    if (!array_key_exists($habId, $templatePorHab)) {
                        $checklist    ??= new ChecklistService();
                        $habitaciones ??= new HabitacionService();
                        $hab = $habitaciones->obtener($habId);
                        $templatePorHab[$habId] = $hab === null ? null : $checklist->templateParaHabitacion($hab);
                    }
                    $templateId = $templatePorHab[$habId];
                }
                if ($templateId === null) {
                    // Tipo recién importado sin checklist todavía: sin créditos no hay unidad común
                    // entre piezas y créditos, así que la asignación no entra a Asignadas (caso raro).
                    continue;
                }
                $filas[$uid]['esperado_hab']++;
                $filas[$uid]['esperado_creditos'] += $this->creditosObligatorios($templateId, $creditosPorTemplate);
            }
        }

        // ── Derivados ──
        foreach ($filas as &$t) {
            $t['creditos']     = $t['creditos_auditados'] + $t['creditos_no_auditados'];
            $t['habitaciones'] = $t['hab_auditadas'] + $t['hab_no_auditadas'];
            $aHab = $t['habitaciones'];
            $rHab = $t['rechazadas_hab'];
            $eHab = $t['esperado_hab'];
            $horas = $t['minutos_total'] / 60;

            $t['cobertura_pct']    = $this->pct($t['hab_auditadas'], $t['hab_auditadas'] + $t['hab_no_auditadas']);
            $t['realizacion_pct']  = $this->pct($aHab + $rHab, $eHab);
            $t['cumplimiento_pct'] = $this->pct($aHab, $eHab);
            $t['calidad_pct']      = $this->pct($aHab, $aHab + $rHab);
            $t['rechazo_pct']      = $this->pct($rHab, $aHab + $rHab);
            // Ratios del N2 sobre piezas de huésped (creditos_hab): mismo universo que E, piezas y horas.
            $t['eficiencia_pct']   = $this->pct($t['creditos_hab'], $t['esperado_creditos']);
            $t['creditos_por_hab'] = $aHab > 0 ? round($t['creditos_hab'] / $aHab, 1) : null;
            $t['ritmo']            = $horas > 0 ? round($t['creditos_hab'] / $horas, 1) : null;
            $t['horas']            = round($horas, 1);
        }
        unset($t);

        usort($filas, static fn (array $a, array $b): int => strcmp((string) $a['nombre'], (string) $b['nombre']));
        return $filas; // usort ya reindexa: es una lista
    }

    /**
     * Trabajador N3: promedio y desviación estándar (σ) del equipo por KPI, y por persona
     * Δ + semáforo. Solo entran al promedio (y reciben semáforo) quienes tienen al menos
     * `min_datos` habitaciones en el período. Dirección por KPI según la ficha: más=mejor,
     * menos=mejor, dos lados (tiempo y ritmo: muy lento O sospechosamente rápido alertan),
     * o informativo (créditos por hab: sin rojo). Muta $trabajadores agregando 'cmp' y
     * 'datos_suficientes'.
     *
     * @param list<array<string, mixed>> $trabajadores
     * @param array{sigma_amarillo:int, sigma_rojo:int, min_datos:int, meta_cobertura:int, meta_rechazo:int, meta_aprobacion:int} $config
     * @return array<string, array{promedio:?float, sigma:?float, n:int, direccion:string}>
     */
    private function fichaComparativa(array &$trabajadores, array $config): array
    {
        $direccion = [
            'creditos' => 'mas_mejor', 'habitaciones' => 'mas_mejor',
            'realizacion_pct' => 'mas_mejor', 'cumplimiento_pct' => 'mas_mejor',
            'calidad_pct' => 'mas_mejor', 'eficiencia_pct' => 'mas_mejor',
            'rechazo_pct' => 'menos_mejor',
            'tiempo_promedio' => 'dos_lados', 'ritmo' => 'dos_lados',
            'creditos_por_hab' => 'informativo',
        ];

        foreach ($trabajadores as &$t) {
            $t['datos_suficientes'] = $t['habitaciones'] >= $config['min_datos'];
        }
        unset($t);

        $stats = [];
        foreach ($direccion as $kpi => $dir) {
            $valores = [];
            foreach ($trabajadores as $t) {
                if ($t['datos_suficientes'] && $t[$kpi] !== null) {
                    $valores[] = (float) $t[$kpi];
                }
            }
            $n = count($valores);
            $promedio = $n > 0 ? array_sum($valores) / $n : null;
            $sigma = null;
            if ($promedio !== null && $n > 1) {
                $acum = 0.0;
                foreach ($valores as $v) {
                    $acum += ($v - $promedio) ** 2;
                }
                $sigma = sqrt($acum / $n);
            }
            $stats[$kpi] = [
                'promedio'  => $promedio === null ? null : round($promedio, 1),
                'sigma'     => $sigma === null ? null : round($sigma, 2),
                'n'         => $n,
                'direccion' => $dir,
            ];
        }

        foreach ($trabajadores as &$t) {
            $cmp = [];
            foreach ($direccion as $kpi => $dir) {
                $s = $stats[$kpi];
                if (!$t['datos_suficientes'] || $t[$kpi] === null || $s['promedio'] === null) {
                    $cmp[$kpi] = ['delta' => null, 'z' => null, 'estado' => 'sin_datos'];
                    continue;
                }
                $delta = (float) $t[$kpi] - (float) $s['promedio'];
                $z = ($s['sigma'] !== null && $s['sigma'] > 0) ? abs($delta) / (float) $s['sigma'] : 0.0;
                $ladoAlerta = match ($dir) {
                    'mas_mejor'   => $delta < 0,
                    'menos_mejor' => $delta > 0,
                    'dos_lados'   => true,
                    default       => false,
                };
                if ($dir === 'informativo') {
                    $estado = 'informativo';
                } elseif (!$ladoAlerta) {
                    $estado = 'ok';
                } elseif ($z >= $config['sigma_rojo']) {
                    $estado = 'critico';
                } elseif ($z >= $config['sigma_amarillo']) {
                    $estado = 'alerta';
                } else {
                    $estado = 'ok';
                }
                $cmp[$kpi] = ['delta' => round($delta, 1), 'z' => round($z, 2), 'estado' => $estado];
            }
            $t['cmp'] = $cmp;
        }
        unset($t);

        return $stats;
    }

    /**
     * Supervisora N1-N3 sobre el universo del filtro (hoy la "sección" = ambos hoteles).
     *
     * @param array{sigma_amarillo:int, sigma_rojo:int, min_datos:int, meta_cobertura:int, meta_rechazo:int, meta_aprobacion:int} $config
     * @return array<string, mixed>
     */
    private function fichaSupervisoras(string $desde, string $hasta, string $hotel, array $config): array
    {
        $actual = $this->seccionSupervisora($desde, $hasta, $hotel);

        // Tendencia: mismo largo de período, inmediatamente anterior.
        $dias = (int) round((strtotime($hasta) - strtotime($desde)) / 86400) + 1;
        $prevHasta = date('Y-m-d', strtotime($desde . ' -1 day'));
        $prevDesde = date('Y-m-d', strtotime($prevHasta . ' -' . ($dias - 1) . ' days'));
        $anterior = $this->seccionSupervisora($prevDesde, $prevHasta, $hotel);

        // Metas configurables en Ajustes → Alertas (defaults de la ficha: cobertura 90 %, rechazo ≤ 5 %,
        // aprobación a la primera ≥ 95 %). Umbral de "alerta" derivado de la meta: 10 puntos por
        // debajo cuando más es mejor, 2 puntos por encima para el rechazo; más allá es "crítico".
        $metaCob = (float) $config['meta_cobertura'];
        $metaRec = (float) $config['meta_rechazo'];
        $metaApr = (float) $config['meta_aprobacion'];
        $seccion = [
            'completadas'       => $actual['completadas'],
            'auditadas_humanas' => $actual['auditadas_humanas'],
            'periodo_anterior'  => ['desde' => $prevDesde, 'hasta' => $prevHasta],
            'cobertura' => $this->kpiVsMeta($actual['cobertura_pct'], $anterior['cobertura_pct'], $metaCob, 'mas_mejor', $metaCob - 10),
            'rechazo'   => $this->kpiVsMeta($actual['rechazo_pct'], $anterior['rechazo_pct'], $metaRec, 'menos_mejor', $metaRec + 2),
            'aprobacion_primera' => $this->kpiVsMeta($actual['aprobacion_pct'], $anterior['aprobacion_pct'], $metaApr, 'mas_mejor', $metaApr - 10),
            // Desglose por turno del trabajador (calendario de Turnos): misma meta de cobertura; sin tendencia.
            'por_turno' => array_map(
                fn (array $t): array => $t + [
                    'cobertura_estado' => $this->kpiVsMeta($t['cobertura_pct'], null, $metaCob, 'mas_mejor', $metaCob - 10)['estado'],
                ],
                $actual['por_turno']
            ),
        ];

        // ── N1 por inspectora (fecha de AUDITORÍA), veredictos humanos, sin el usuario Sistema ──
        $p = Fechas::rangoUtc($desde, $hasta);
        $h = $this->hotelCond($hotel, $p);
        $dur = Database::diffMinutosSql('ec.auditoria_iniciada_at', 'a.created_at');
        $durValida = "ec.auditoria_iniciada_at IS NOT NULL AND {$dur} >= " . self::AUDITACION_MIN_MINUTOS . " AND {$dur} <= " . self::AUDITACION_MAX_MINUTOS;
        $inspectoras = [];
        foreach (Database::fetchAll(
            "SELECT u.id AS usuario_id, u.nombre, COUNT(*) AS total,
                    SUM(CASE WHEN a.veredicto = 'aprobado' THEN 1 ELSE 0 END) AS aprobadas,
                    SUM(CASE WHEN a.veredicto = 'aprobado_con_observacion' THEN 1 ELSE 0 END) AS con_observacion,
                    SUM(CASE WHEN a.veredicto = 'rechazado' THEN 1 ELSE 0 END) AS rechazadas,
                    AVG(CASE WHEN {$durValida} THEN {$dur} ELSE NULL END) AS tiempo_auditacion,
                    SUM(CASE WHEN {$durValida} THEN 1 ELSE 0 END) AS n_tiempo
               FROM #__auditorias a
               JOIN #__usuarios u ON u.id = a.auditor_id
               JOIN #__ejecuciones_checklist ec ON ec.id = a.ejecucion_id
               JOIN #__habitaciones h ON h.id = a.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
              WHERE a.veredicto IN " . self::VEREDICTOS_HUMANOS . "
                AND u.rut <> '" . self::RUT_SISTEMA . "'
                AND a.created_at >= ? AND a.created_at < ?
                    {$h}
              GROUP BY u.id, u.nombre
              ORDER BY u.nombre",
            $p
        ) as $f) {
            $nTiempo = (int) $f['n_tiempo'];
            $inspectoras[] = [
                'usuario_id'        => (int) $f['usuario_id'],
                'nombre'            => (string) $f['nombre'],
                'total'             => (int) $f['total'],
                'aprobadas'         => (int) $f['aprobadas'],
                'con_observacion'   => (int) $f['con_observacion'],
                'rechazadas'        => (int) $f['rechazadas'],
                'tiempo_auditacion' => $nTiempo > 0 ? round((float) $f['tiempo_auditacion'], 1) : null,
                'n_tiempo'          => $nTiempo,
                // Aporte a la cobertura: lo que ELLA auditó sobre todo lo limpiado en la sección.
                'aporte_cobertura_pct' => $this->pct((int) $f['total'], $actual['completadas']),
            ];
        }

        // ── N3 entre inspectoras: promedio simple + Δ (son pocas: sin σ, a propósito) ──
        $comparativa = [];
        foreach (['total', 'tiempo_auditacion', 'aporte_cobertura_pct'] as $kpi) {
            $valores = array_filter(array_column($inspectoras, $kpi), static fn ($v) => $v !== null);
            $comparativa[$kpi] = [
                'promedio' => $valores === [] ? null : round(array_sum($valores) / count($valores), 1),
                'n'        => count($valores),
            ];
        }
        foreach ($inspectoras as &$i) {
            $i['cmp'] = [];
            foreach ($comparativa as $kpi => $c) {
                $i['cmp'][$kpi] = ($c['promedio'] === null || $i[$kpi] === null)
                    ? null
                    : round((float) $i[$kpi] - (float) $c['promedio'], 1);
            }
        }
        unset($i);

        return [
            'seccion'     => $seccion,
            'inspectoras' => $inspectoras,
            'comparativa' => $comparativa,
        ];
    }

    /**
     * Agregados de la sección por FECHA DE LIMPIEZA: cobertura (auditadas humanas ÷ limpiadas),
     * % rechazo y % aprobación a la primera sobre las auditadas humanas. Solo piezas de huésped.
     *
     * Además, el desglose POR TURNO (decisión 17/09: opción A): cada limpieza se clasifica por el
     * turno que tenía SU TRABAJADOR ese día en el calendario de Turnos (usuarios_turnos por fecha
     * del turno de la asignación). Sin calendario ese día → «Sin turno», así se ve también si el
     * calendario se mantiene. El total de la sección es la suma de los turnos (mismas filas).
     *
     * @return array{completadas:int, auditadas_humanas:int, cobertura_pct:?float, rechazo_pct:?float, aprobacion_pct:?float, por_turno:list<array<string, mixed>>}
     */
    private function seccionSupervisora(string $desde, string $hasta, string $hotel): array
    {
        $p = Fechas::rangoUtc($desde, $hasta);
        $h = $this->hotelCond($hotel, $p);
        $filas = Database::fetchAll(
            "SELECT t.nombre AS turno, t.hora_inicio,
                    COUNT(*) AS completadas,
                    SUM(CASE WHEN a.veredicto IN " . self::VEREDICTOS_HUMANOS . " THEN 1 ELSE 0 END) AS auditadas_humanas,
                    SUM(CASE WHEN a.veredicto = 'rechazado' THEN 1 ELSE 0 END) AS rechazadas,
                    SUM(CASE WHEN a.veredicto IN ('aprobado', 'aprobado_con_observacion') THEN 1 ELSE 0 END) AS aprobadas
               FROM #__ejecuciones_checklist ec
               JOIN #__asignaciones asg ON asg.id = ec.asignacion_id
               JOIN #__habitaciones h ON h.id = ec.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
          LEFT JOIN #__auditorias a ON a.ejecucion_id = ec.id
          LEFT JOIN #__usuarios_turnos ut ON ut.usuario_id = ec.usuario_id AND ut.fecha = asg.fecha
          LEFT JOIN #__turnos t ON t.id = ut.turno_id
              WHERE ec.estado IN ('completada', 'auditada')
                AND ec.timestamp_inicio >= ? AND ec.timestamp_inicio < ?
                    {$h}
              GROUP BY t.nombre, t.hora_inicio",
            $p
        );
        // Orden estable en ambos motores: turnos por hora de inicio, «Sin turno» al final.
        usort($filas, static function (array $x, array $y): int {
            if (($x['turno'] === null) !== ($y['turno'] === null)) {
                return $x['turno'] === null ? 1 : -1;
            }
            return strcmp((string) $x['hora_inicio'], (string) $y['hora_inicio'])
                ?: strcmp((string) $x['turno'], (string) $y['turno']);
        });

        $total = ['completadas' => 0, 'auditadas_humanas' => 0, 'rechazadas' => 0, 'aprobadas' => 0];
        $porTurno = [];
        foreach ($filas as $f) {
            $b = [
                'completadas'       => (int) $f['completadas'],
                'auditadas_humanas' => (int) $f['auditadas_humanas'],
                'rechazadas'        => (int) $f['rechazadas'],
                'aprobadas'         => (int) $f['aprobadas'],
            ];
            foreach ($b as $k => $v) {
                $total[$k] += $v;
            }
            $nombre = $f['turno'] === null ? null : (string) $f['turno'];
            $porTurno[] = [
                'turno'  => $nombre ?? '__sin_turno', // centinela fuera del espacio de nombres de turnos.nombre
                'nombre' => $nombre === null ? 'Sin turno' : mb_strtoupper(mb_substr($nombre, 0, 1)) . mb_substr($nombre, 1),
                ...$b,
                'cobertura_pct'  => $this->pct($b['auditadas_humanas'], $b['completadas']),
                'rechazo_pct'    => $this->pct($b['rechazadas'], $b['auditadas_humanas']),
                'aprobacion_pct' => $this->pct($b['aprobadas'], $b['auditadas_humanas']),
            ];
        }

        return [
            'completadas'       => $total['completadas'],
            'auditadas_humanas' => $total['auditadas_humanas'],
            'cobertura_pct'     => $this->pct($total['auditadas_humanas'], $total['completadas']),
            'rechazo_pct'       => $this->pct($total['rechazadas'], $total['auditadas_humanas']),
            'aprobacion_pct'    => $this->pct($total['aprobadas'], $total['auditadas_humanas']),
            'por_turno'         => $porTurno,
        ];
    }

    /**
     * KPI de la sección contra una META (semáforo) y contra el período anterior (tendencia).
     *
     * @return array{valor:?float, meta:float, estado:string, anterior:?float, delta:?float, tendencia:string}
     */
    private function kpiVsMeta(?float $valor, ?float $anterior, float $meta, string $direccion, float $umbralAlerta): array
    {
        if ($valor === null) {
            $estado = 'sin_datos';
        } elseif ($direccion === 'mas_mejor') {
            $estado = $valor >= $meta ? 'ok' : ($valor >= $umbralAlerta ? 'alerta' : 'critico');
        } else {
            $estado = $valor <= $meta ? 'ok' : ($valor <= $umbralAlerta ? 'alerta' : 'critico');
        }
        $delta = ($valor !== null && $anterior !== null) ? round($valor - $anterior, 1) : null;
        $tendencia = 'sin_datos';
        if ($delta !== null) {
            $mejora = $direccion === 'mas_mejor' ? $delta > 0 : $delta < 0;
            $tendencia = abs($delta) < 0.05 ? 'igual' : ($mejora ? 'mejora' : 'empeora');
        }
        return [
            'valor' => $valor, 'meta' => $meta, 'estado' => $estado,
            'anterior' => $anterior, 'delta' => $delta, 'tendencia' => $tendencia,
        ];
    }

    /** Porcentaje a 1 decimal; null si el denominador es 0 (no inventa un 0 %). */
    private function pct(int|float $numerador, int|float $denominador): ?float
    {
        return $denominador > 0 ? round($numerador / $denominador * 100, 1) : null;
    }

    /**
     * Créditos obligatorios vigentes de un template (los que vale una pieza entera para E y R).
     *
     * @param array<int, int> $cache template_id → créditos, lo mantiene el llamador
     */
    private function creditosObligatorios(int $templateId, array &$cache): int
    {
        return $cache[$templateId] ??= (int) Database::fetchColumn(
            'SELECT COALESCE(SUM(creditos), 0) FROM #__items_checklist
              WHERE template_id = ? AND obligatorio = 1 AND activo = 1',
            [$templateId]
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function hotelCond(string $hotel, array &$params): string
    {
        // Excluye áreas comunes de los KPIs de piezas (tiempos, tasas de auditoría, productividad):
        // cada query que usa este helper joinea #__habitaciones con alias 'h'. Un solo punto para no
        // contaminar tasas. Ver docs/areas-comunes.md. Los CRÉDITOS usan hotelCondCreditos (abajo).
        $cond = ' AND h.es_espacio_comun = 0';
        if ($hotel !== 'ambos') {
            $params[] = $hotel;
            $cond .= ' AND ho.codigo = ?';
        }
        return $cond;
    }

    private function hotelCondCreditos(string $hotel, array &$params): string
    {
        // Variante para CRÉDITOS: NO excluye áreas comunes — desde julio 2026 los créditos de los
        // ítems de espacios SUMAN al total del trabajador (pedido de la empresa: hay gente dedicada
        // solo a espacios y su trabajo debe pesar en el KPI). Los demás KPIs siguen solo-piezas.
        if ($hotel !== 'ambos') {
            $params[] = $hotel;
            return ' AND ho.codigo = ?';
        }
        return '';
    }

    private function userCond(?int $usuarioId, array &$params, string $alias = 'ec'): string
    {
        if ($usuarioId !== null) {
            $params[] = $usuarioId;
            return " AND {$alias}.usuario_id = ?";
        }
        return '';
    }

    /** Filtro por la persona que marcó el ítem (para créditos por marcado_por). */
    private function marcadoPorCond(?int $usuarioId, array &$params): string
    {
        if ($usuarioId !== null) {
            $params[] = $usuarioId;
            return ' AND ei.marcado_por = ?';
        }
        return '';
    }

    private function estadoLabel(string $estado): string
    {
        return match ($estado) {
            'ok'          => 'OK',
            'alerta'      => 'Alerta',
            'critico'     => 'Crítico',
            'informativo' => 'Informativo',
            default       => 'Sin datos',
        };
    }

    /** @return array<string, string> */
    private function kpiMetadata(): array
    {
        return [
            'tiempo_promedio'    => 'Tiempo promedio de limpieza',
            'tasa_rechazo'       => 'Tasa de rechazo',
            'eficiencia'         => 'Eficiencia del equipo',
            'creditos'           => 'Créditos obtenidos / máximos',
            'aprobacion_primera' => 'Aprobación a la primera',
            'productividad'      => 'Productividad promedio',
            'tasa_desmarcados'   => 'Tasa de ítems desmarcados',
        ];
    }
}
