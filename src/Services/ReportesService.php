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
                    SUM(CASE WHEN a.veredicto='aprobado' THEN 1 ELSE 0 END) AS aprobadas,
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
        $rows[] = ['Resumen mensual de auditorías por auditor', 'Atankalama Corp'];
        $rows[] = ['Hotel', $hotelLabel, 'Mes', "{$meses[$mes]} {$anio}"];
        $rows[] = ['Generado', date('d/m/Y H:i:s')];
        $rows[] = [];
        $rows[] = ['Auditor', 'Total auditadas', 'Aprobadas', 'Aprobadas con observación', 'Rechazadas'];

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
        $rows[] = ['Reporte de auditorías pendientes al corte de las 23:50', 'Atankalama Corp'];
        $rows[] = ['Hotel', $hotelLabel, 'Fecha', date('d/m/Y', strtotime($fecha))];
        $rows[] = ['Generado', date('d/m/Y H:i:s')];
        $rows[] = ['Criterio', 'Sin auditar a tiempo = sin auditoría registrada antes de las 23:50 de la fecha del reporte.'];

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
                'Sin auditar a tiempo: ' . count($datos['pendientes']),
            ];
            if ($datos['pendientes'] !== []) {
                $rows[] = ['Hotel', 'Habitación', 'Nochero', 'Hora término', 'Estado auditoría'];
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
                        $p['estado_auditoria'] === 'sin_auditar' ? 'Sin auditar' : 'Auditada fuera de plazo',
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
            return ['valor' => null, 'unidad' => '%', 'meta' => 5.0, 'contexto' => '0 auditorías', 'estado' => 'sin_datos'];
        }

        $rechazadas = (int) ($fila['rechazadas'] ?? 0);
        $valor      = round($rechazadas / $total * 100, 1);
        $meta       = 5.0;

        return [
            'valor'    => $valor,
            'unidad'   => '%',
            'meta'     => $meta,
            'contexto' => "{$rechazadas} de {$total} auditadas",
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
                    {$h}{$u}",
            $params
        );

        $total = (int) ($fila['total'] ?? 0);
        if ($total === 0) {
            return ['valor' => null, 'unidad' => '%', 'meta' => 95.0, 'contexto' => '0 auditorías', 'estado' => 'sin_datos'];
        }

        $aprobadas = (int) ($fila['aprobadas'] ?? 0);
        $valor     = round($aprobadas / $total * 100, 1);
        $meta      = 95.0;

        return [
            'valor'    => $valor,
            'unidad'   => '%',
            'meta'     => $meta,
            'contexto' => "{$aprobadas} de {$total} auditadas",
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
            return ['valor' => null, 'unidad' => '%', 'meta' => 3.0, 'contexto' => '0 ítems auditados', 'estado' => 'sin_datos'];
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
