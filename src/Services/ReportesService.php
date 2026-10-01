<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Helpers\Fechas;
use Atankalama\Limpieza\Models\Habitacion;

final class ReportesService
{
    // Una sola definición en toda la pestaña (v6.15, 30/09/2026): los KPIs de arriba, el detalle por
    // trabajadora, el resumen mensual y sus CSV salen de la FICHA (fichaTrabajadores) y de la sección
    // de inspección (seccionSupervisora). Antes cada bloque contaba a su manera y la misma pantalla
    // mostraba cifras distintas para lo mismo (créditos sin la escalera 100/50/0, piezas distintas en
    // vez de limpiezas, rechazo con el cierre automático en el total). Ver docs/kpis-sueldos.md.

    /** Texto de cada estado de «Inspecciones pendientes al corte» (pantalla, CSV y correo diario). */
    public const ESTADOS_PENDIENTE = [
        'sin_auditar'         => 'Sin inspeccionar',
        'aprobada_automatica' => 'Sin inspeccionar (la aprobó el sistema)',
        'auditada_tarde'      => 'Inspeccionada fuera de plazo',
    ];

    /** @return array<string, mixed> */
    public function kpis(string $desde, string $hasta, string $hotel, ?int $usuarioId = null): array
    {
        return $this->reporteKpis($desde, $hasta, $hotel, $usuarioId, false)['kpis'];
    }

    /**
     * Lo que pinta el bloque de arriba de Reportes en UN cálculo de la ficha: los 7 KPIs (del equipo o
     * de la trabajadora filtrada), el detalle por trabajadora y la lista del selector.
     *
     * @return array{kpis: array<string, mixed>, por_trabajadora: list<array<string, mixed>>, trabajadoras: list<array{usuario_id:int, nombre:string}>}
     */
    public function reporteKpis(string $desde, string $hasta, string $hotel, ?int $usuarioId = null, bool $conDetalle = true): array
    {
        $ficha   = $this->fichaTrabajadores($desde, $hasta, $hotel);
        $config  = $this->configReportes();
        $seccion = $usuarioId === null ? $this->seccionSupervisora($desde, $hasta, $hotel) : null;

        $porTrabajadora = [];
        if ($conDetalle) {
            foreach ($ficha as $t) {
                $porTrabajadora[] = [
                    'usuario_id'      => (int) $t['usuario_id'],
                    'nombre'          => $t['nombre'],
                    'jornada'         => $t['jornada'],
                    'dias_trabajados' => (int) $t['dias_trabajados'],
                    'kpis'            => $this->armarKpis($ficha, null, $desde, $hasta, $hotel, (int) $t['usuario_id'], $config),
                ];
            }
        }

        return [
            'kpis'            => $this->armarKpis($ficha, $seccion, $desde, $hasta, $hotel, $usuarioId, $config),
            'por_trabajadora' => $porTrabajadora,
            'trabajadoras'    => $this->listaTrabajadoras($ficha),
        ];
    }

    /**
     * Quienes tuvieron algo en el período según la ficha: limpiezas, créditos (también de áreas
     * comunes), rechazos o piezas asignadas. Las limpiezas del atajo «Marcar limpia» (sin ítems
     * marcados) no cuentan como trabajo: la supervisora que lo usa no aparece como trabajadora.
     *
     * @return list<array{usuario_id:int, nombre:string}>
     */
    public function trabajadoras(string $desde, string $hasta, string $hotel): array
    {
        return $this->listaTrabajadoras($this->fichaTrabajadores($desde, $hasta, $hotel));
    }

    /**
     * Resumen mensual por trabajador = la ficha del mes (mismo cálculo, mismos números):
     *   habitaciones  = piezas de huésped que quedaron bien, una por limpieza (pieza · día · franja · vuelta);
     *   rechazadas    = piezas que le rechazaron (siguen rechazadas para ella la rehaga quien la rehaga);
     *   creditos      = créditos aprobados con la escalera 100/50/0 de jefatura, incluidas áreas comunes;
     *   creditos_asignados / eficiencia_pct = lo asignado del checklist vigente y créditos de piezas ÷ asignados;
     *   dias_trabajados = días con al menos una asignación (ver fichaTrabajadores); jornada = completa/parcial/null.
     * Es el «CRÉDITOS TOTAL» que usa sueldos.
     * Más las columnas de la planilla «KPI ASEO» de RRHH (BonoAseoService::calcular) con el corte del mes:
     *   hab. hechas = habitaciones (las que quedaron bien); observaciones = rechazadas + aprobadas con
     *   observación (decisión de Nicolás, 01/10/2026). Ver docs/kpis-sueldos.md.
     *
     * @return list<array<string, mixed>>
     */
    public function resumenMensual(int $anio, int $mes, string $hotel, ?float $corte = null): array
    {
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = date('Y-m-t', strtotime($desde));
        $corte ??= (new BonoAseoService())->corte($anio, $mes)['valor'];

        return array_map(static function (array $t) use ($corte): array {
            $observaciones = (int) $t['rechazadas_hab'] + (int) $t['observadas_hab'];
            return [
                'usuario_id'         => (int) $t['usuario_id'],
                'rut'                => $t['rut'],
                'nombre'             => (string) $t['nombre'],
                'jornada'            => $t['jornada'],
                'dias_trabajados'    => (int) $t['dias_trabajados'],
                'habitaciones'       => (int) $t['habitaciones'],
                'rechazadas'         => (int) $t['rechazadas_hab'],
                'con_observacion'    => (int) $t['observadas_hab'],
                'observaciones'      => $observaciones,
                'creditos'           => (int) $t['creditos'],
                'creditos_asignados' => (int) $t['esperado_creditos'],
                'eficiencia_pct'     => $t['eficiencia_pct'],
                'bono'               => BonoAseoService::calcular(
                    (int) $t['habitaciones'],
                    (int) $t['dias_trabajados'],
                    $t['jornada'],
                    $observaciones,
                    $corte
                ),
            ];
        }, $this->fichaTrabajadores($desde, $hasta, $hotel));
    }

    /** @return list<array<string, mixed>> */
    public function kpisPorTrabajadora(string $desde, string $hasta, string $hotel): array
    {
        return $this->reporteKpis($desde, $hasta, $hotel)['por_trabajadora'];
    }

    public function exportarCsv(string $desde, string $hasta, string $hotel, ?int $usuarioId = null): string
    {
        $reporte        = $this->reporteKpis($desde, $hasta, $hotel, $usuarioId, $usuarioId === null);
        $kpis           = $reporte['kpis'];
        $porTrabajadora = $reporte['por_trabajadora'];

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
                'Jornada',
                'Días trabajados',
                'T. Prom. (min)',
                'Rechazo (%)',
                'Eficiencia (%)',
                'Créditos',
                'Aprob. 1ª (%)',
                'Productiv. (hab/día)',
                'Desmarcados (%)',
            ];
            foreach ($porTrabajadora as $t) {
                $k = $t['kpis'];
                $rows[] = [
                    $t['nombre'],
                    self::jornadaLabel($t['jornada']),
                    $t['dias_trabajados'],
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
     * CSV del resumen mensual por trabajador (mismos números que la pantalla y que la ficha del mes).
     */
    public function exportarCsvMensual(int $anio, int $mes, string $hotel): string
    {
        $corte = (new BonoAseoService())->corte($anio, $mes)['valor'];
        $filas = $this->resumenMensual($anio, $mes, $hotel, $corte);

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
        $rows[] = ['Corte hab./día', $corte, 'Jornada parcial', $corte / 2];
        $rows[] = ['Generado', date('d/m/Y H:i:s')];
        $rows[] = [];
        // Mismo orden que la planilla «KPI ASEO» de RRHH (hab. hechas → extras), con el RUT como llave.
        $rows[] = [
            'RUT', 'Trabajador', 'Jornada', 'Días trabajados', 'Hab. hechas', 'Act. por día', 'Observaciones',
            '% act. observadas', 'Eficacia (%)', '% logro', 'Factor de peso', 'Resultado (%)', 'Actividades extras',
            'Habitaciones rechazadas', 'Aprobadas con observación', 'Créditos obtenidos', 'Créditos asignados', 'Eficiencia (%)',
        ];

        $totalDias = $totalHab = $totalObs = $totalRec = $totalConObs = $totalCre = $totalAsig = 0;
        $totalExtras = 0.0;
        foreach ($filas as $f) {
            $b = $f['bono'];
            $rows[] = [
                $f['rut'],
                $f['nombre'],
                self::jornadaLabel($f['jornada']),
                $f['dias_trabajados'],
                $f['habitaciones'],
                $b['act_dia'] ?? '',
                $f['observaciones'],
                $b['observadas_pct'] ?? '',
                $b['eficacia_pct'] ?? '',
                $b['logro_pct'] ?? '',
                $b['factor_peso'] ?? '',
                $b['resultado_pct'] ?? '',
                $b['extras'] ?? '',
                $f['rechazadas'],
                $f['con_observacion'],
                $f['creditos'],
                $f['creditos_asignados'],
                $f['eficiencia_pct'] ?? '',
            ];
            $totalDias   += $f['dias_trabajados'];
            $totalHab    += $f['habitaciones'];
            $totalObs    += $f['observaciones'];
            $totalExtras += (float) ($b['extras'] ?? 0);
            $totalRec    += $f['rechazadas'];
            $totalConObs += $f['con_observacion'];
            $totalCre    += $f['creditos'];
            $totalAsig   += $f['creditos_asignados'];
        }

        if (!empty($filas)) {
            // Los porcentajes y la eficiencia del total no se derivan de estas columnas (cada persona
            // tiene su base y su jornada; los créditos incluyen áreas comunes, lo asignado no): se dejan
            // vacíos antes que mostrar un cociente engañoso.
            $rows[] = [];
            $rows[] = ['', 'TOTAL', '', $totalDias, $totalHab, '', $totalObs, '', '', '', '', '', round($totalExtras, 1),
                $totalRec, $totalConObs, $totalCre, $totalAsig, ''];
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
     * El cierre automático (aprobado_automatico, cron de las 15:50 y de las 23:55) NO es una
     * inspección: esas piezas salen como 'aprobada_automatica' (nadie la inspeccionó), no como
     * inspeccionadas a tiempo ni fuera de plazo (corregido el 30/09/2026, R5).
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
                    a.id AS auditoria_id, a.created_at AS auditoria_created_at, a.veredicto AS auditoria_veredicto
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
                if ($f['auditoria_veredicto'] === 'aprobado_automatico') {
                    $estadoAuditoria = 'aprobada_automatica';
                } elseif ((string) $f['auditoria_created_at'] >= $corte) {
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
                        self::ESTADOS_PENDIENTE[$p['estado_auditoria']] ?? $p['estado_auditoria'],
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

    // ─── KPIs de arriba, armados desde la ficha ───────────────────────────────

    /**
     * Las 7 tarjetas (y cada fila del detalle). Tiempo, eficiencia y créditos salen de la ficha.
     * Rechazo y aprobación a la 1ª del EQUIPO salen de la sección de inspección: solo veredictos
     * humanos y por fecha de limpieza, porque el cierre automático no es una inspección. Los de UNA
     * trabajadora salen de su fila de la ficha, donde lo que aprobó el cierre automático cuenta como
     * aprobado (decisión de Gerencia del 16/09: nadie pierde puntos porque no alcanzaron a inspeccionar).
     *
     * @param list<array<string, mixed>> $ficha
     * @param array<string, mixed>|null  $seccion seccionSupervisora() del mismo período (solo para el equipo)
     * @param array{sigma_amarillo:int, sigma_rojo:int, min_datos:int, meta_cobertura:int, meta_rechazo:int, meta_aprobacion:int} $config
     * @return array<string, mixed>
     */
    private function armarKpis(array $ficha, ?array $seccion, string $desde, string $hasta, string $hotel, ?int $usuarioId, array $config): array
    {
        $filas = $usuarioId === null
            ? $ficha
            : array_values(array_filter($ficha, static fn (array $t): bool => (int) $t['usuario_id'] === $usuarioId));
        $suma = static fn (string $campo): int|float => array_sum(array_column($filas, $campo));
        $metaRec = (float) $config['meta_rechazo'];
        $metaApr = (float) $config['meta_aprobacion'];

        // Tiempo por limpieza: piezas de huésped, sin el atajo «Marcar limpia». La trabajadora ve
        // exactamente el de su fila de la ficha; el equipo, el promedio de todas las limpiezas.
        $ejecuciones = (int) $suma('ejecuciones');
        $tiempo = $usuarioId !== null
            ? ($filas[0]['tiempo_promedio'] ?? null)
            : ($ejecuciones > 0 ? round((float) $suma('minutos_total') / $ejecuciones, 1) : null);
        $metaTiempo = 30.0;

        if ($seccion !== null) {
            $rechazo      = $seccion['rechazo_pct'];
            $aprobacion   = $seccion['aprobacion_pct'];
            $ctxRechazo   = "{$seccion['rechazadas']} de {$seccion['auditadas_humanas']} inspeccionadas";
            $ctxAprob     = "{$seccion['aprobadas']} de {$seccion['auditadas_humanas']} inspeccionadas";
        } else {
            $a = (int) $suma('habitaciones');
            $r = (int) $suma('rechazadas_hab');
            $rechazo    = $this->pct($r, $a + $r);
            $aprobacion = $this->pct($a, $a + $r);
            $ctxRechazo = "{$r} de " . ($a + $r) . ' piezas';
            $ctxAprob   = "{$a} de " . ($a + $r) . ' piezas (cuenta las aprobadas por el cierre automático)';
        }

        $asignados   = (int) $suma('esperado_creditos');
        $creditosHab = (int) $suma('creditos_hab');
        $eficiencia  = $this->pct($creditosHab, $asignados);
        $metaEfic    = 85.0;

        return [
            'tiempo_promedio' => [
                'valor'    => $tiempo,
                'unidad'   => 'min',
                'meta'     => $metaTiempo,
                'contexto' => "{$ejecuciones} limpiezas",
                'estado'   => $tiempo === null ? 'sin_datos' : ($tiempo <= $metaTiempo ? 'ok' : ($tiempo <= $metaTiempo * 1.15 ? 'alerta' : 'critico')),
            ],
            'tasa_rechazo' => [
                'valor'    => $rechazo,
                'unidad'   => '%',
                'meta'     => $metaRec,
                'contexto' => $rechazo === null ? '0 inspecciones' : $ctxRechazo,
                'estado'   => $this->kpiVsMeta($rechazo, null, $metaRec, 'menos_mejor', $metaRec + 2)['estado'],
            ],
            'eficiencia' => [
                'valor'    => $eficiencia,
                'unidad'   => '%',
                'meta'     => $metaEfic,
                'contexto' => $eficiencia === null ? '0 créditos asignados' : "{$creditosHab} de {$asignados} créditos asignados",
                'estado'   => $eficiencia === null ? 'sin_datos' : ($eficiencia >= $metaEfic ? 'ok' : ($eficiencia >= 75.0 ? 'alerta' : 'critico')),
            ],
            // Créditos = el mismo número que la ficha y el resumen mensual (escalera 100/50/0, áreas comunes incluidas).
            'creditos' => $filas === []
                ? ['valor' => null, 'unidad' => 'cr', 'meta' => null, 'contexto' => '0 créditos', 'estado' => 'sin_datos']
                : [
                    'valor'    => (int) $suma('creditos'),
                    'unidad'   => 'cr',
                    'meta'     => null,
                    'contexto' => (int) $suma('creditos_auditados') . ' inspeccionados · ' . (int) $suma('creditos_no_auditados') . ' sin inspección',
                    'estado'   => 'informativo',
                ],
            'aprobacion_primera' => [
                'valor'    => $aprobacion,
                'unidad'   => '%',
                'meta'     => $metaApr,
                'contexto' => $aprobacion === null ? '0 inspecciones' : $ctxAprob,
                'estado'   => $this->kpiVsMeta($aprobacion, null, $metaApr, 'mas_mejor', $metaApr - 10)['estado'],
            ],
            'productividad'    => $this->kpiProductividad($desde, $hasta, $hotel, $usuarioId),
            'tasa_desmarcados' => $this->kpiTasaDesmarcados($desde, $hasta, $hotel, $usuarioId),
        ];
    }

    /**
     * @param list<array<string, mixed>> $ficha
     * @return list<array{usuario_id:int, nombre:string}>
     */
    private function listaTrabajadoras(array $ficha): array
    {
        return array_map(
            static fn (array $t): array => ['usuario_id' => (int) $t['usuario_id'], 'nombre' => (string) $t['nombre']],
            $ficha
        );
    }

    /**
     * Umbrales y metas de Reportes (Ajustes → Alertas), saneados.
     *
     * @return array{sigma_amarillo:int, sigma_rojo:int, min_datos:int, meta_cobertura:int, meta_rechazo:int, meta_aprobacion:int}
     */
    private function configReportes(): array
    {
        $alertas = new AlertasService();
        $config = [
            'sigma_amarillo'  => max(1, $alertas->obtenerConfigInt('reportes_sigma_amarillo')),
            'sigma_rojo'      => max(1, $alertas->obtenerConfigInt('reportes_sigma_rojo')),
            'min_datos'       => max(1, $alertas->obtenerConfigInt('reportes_min_datos')),
            'meta_cobertura'  => min(100, max(1, $alertas->obtenerConfigInt('reportes_meta_cobertura'))),
            'meta_rechazo'    => min(100, max(1, $alertas->obtenerConfigInt('reportes_meta_rechazo'))),
            'meta_aprobacion' => min(100, max(1, $alertas->obtenerConfigInt('reportes_meta_aprobacion'))),
        ];
        if ($config['sigma_rojo'] <= $config['sigma_amarillo']) {
            $config['sigma_rojo'] = $config['sigma_amarillo'] + 1;
        }
        return $config;
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
                AND " . self::CON_TRABAJO . "
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
    /**
     * Ejecución con trabajo real: su DUEÑA marcó al menos un ítem. El atajo «Marcar limpia»
     * (ChecklistService::marcarLimpiaManual) crea una ejecución SIN ítems a nombre de quien lo usó,
     * con inicio = fin: no es una limpieza de esa persona y no entra a tiempos, productividad,
     * rechazos ni Asignadas. Tampoco cuenta una re-limpieza que solo trae ítems HEREDADOS de otra
     * persona y se cerró con el atajo sin que la dueña marcara nada. marcado_por sobrevive al
     * desmarcado del auditor, así que un rechazo con todos los ítems desmarcados sigue contando.
     * Requiere el alias `ec`.
     */
    private const CON_TRABAJO = 'EXISTS (SELECT 1 FROM #__ejecuciones_items eit WHERE eit.ejecucion_id = ec.id AND eit.marcado_por = ec.usuario_id)';
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
        $config = $this->configReportes();

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
                    'dias_trabajados' => 0, 'jornada' => null, 'rut' => null, 'observadas_hab' => 0,
                ];
            }
        };

        // Ciclo = (pieza, fecha del turno, franja, vuelta): la unidad en que se cuentan E, A y R — "una
        // vez por habitación por persona" de la ficha, aplicada a cada limpieza pedida; en un rango
        // largo cada turno vuelve a contar y E·A·R quedan en la misma unidad que los créditos. Toda
        // ejecución cuelga de una asignación (asignacion_id NOT NULL): la fecha local sale de ahí.
        // La VUELTA separa las limpiezas nuevas del mismo día sobre la misma asignación (nochero de
        // las 16:00, turnover): sin ella la 2ª limpieza sumaba créditos contra una sola Asignada y la
        // eficiencia pasaba del 100 % (decisión de Nicolás, 30/09/2026). Ver vueltasPorEjecucion().
        $vueltas = $this->vueltasPorEjecucion($desde, $hasta);
        $ciclo = static fn (array $f): string => $f['habitacion_id'] . ':' . $f['fecha'] . ':' . ($f['franja'] ?? '')
            . ':' . ($vueltas[(int) $f['ejecucion_id']] ?? 0);
        $creditosPorTemplate = []; // cache template_id → créditos obligatorios vigentes

        // ── N2: rechazadas (R) por dueño de la ejecución, una vez por ciclo; pierde TODOS los créditos
        // obligatorios del checklist de esa pieza (versión exacta: ec.template_id). Solo piezas de
        // huésped. Va primero: el instante del rechazo alimenta la regla de auto-relimpieza de abajo.
        $p = Fechas::rangoUtc($desde, $hasta);
        $h = $this->hotelCond($hotel, $p);
        $rechazos = []; // uid → ciclo → lista cronológica de timestamp_inicio de sus intentos rechazados
        foreach (Database::fetchAll(
            "SELECT ec.id AS ejecucion_id, ec.usuario_id, u.nombre, ec.habitacion_id, ec.timestamp_inicio, ec.template_id, asg.fecha, asg.franja
               FROM #__ejecuciones_checklist ec
               JOIN #__auditorias a ON a.ejecucion_id = ec.id AND a.veredicto = 'rechazado'
               JOIN #__asignaciones asg ON asg.id = ec.asignacion_id
               JOIN #__usuarios u ON u.id = ec.usuario_id
               JOIN #__habitaciones h ON h.id = ec.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
              WHERE ec.timestamp_inicio >= ? AND ec.timestamp_inicio < ?
                AND " . self::CON_TRABAJO . "
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
        $ciclosObs = []; // uid → ciclo → true si su limpieza quedó «aprobada con observación» (bono RRHH)
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
                // La pieza cuenta como hecha solo para la DUEÑA de la limpieza: los ítems heredados de otra
                // persona (marcado_por ≠ dueña) le dan créditos a quien los marcó, pero no una pieza aprobada —
                // tras un rechazo la pieza sigue siendo rechazada para ella, la rehaga quien la rehaga y el día que sea.
                if ($rechazosPrevios === 0 && (int) $f['dueno_id'] === $uid) {
                    $ciclosA[$uid][$c] = ($ciclosA[$uid][$c] ?? false) || $humana;
                    if ($f['veredicto'] === 'aprobado_con_observacion') {
                        $ciclosObs[$uid][$c] = true;
                    }
                }
            }
        }
        foreach ($ciclosA as $uid => $ciclos) {
            foreach ($ciclos as $humana) {
                $filas[$uid][$humana ? 'hab_auditadas' : 'hab_no_auditadas']++;
            }
        }
        foreach ($ciclosObs as $uid => $ciclos) {
            $filas[$uid]['observadas_hab'] = count($ciclos);
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
                AND " . self::CON_TRABAJO . "
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

        // ── N2: Asignadas (E). Una vez por pieza por persona POR CICLO, PEGAJOSO: cuenta aunque la
        // rechacen o la pieza pase a otra persona (regla de la ficha). Por asignación:
        //  · con limpiezas suyas → un ciclo por cada vuelta que trabajó: el nochero de la tarde y el
        //    turnover suman otra pieza asignada, con los créditos del checklist de esa limpieza;
        //  · solo con el atajo «Marcar limpia» (otra persona la dio por limpia) → no cuenta, no le
        //    quedó nada que hacer;
        //  · sin ejecuciones → cuenta si siguió activa o si la pieza pasó después a otra persona; NO
        //    cuenta si se retiró sin trabajo y nadie más la tomó (autocancelada porque la pieza ya
        //    estaba limpia al llegar el día, o sacada del plan): ahí no había nada que hacer.
        // Créditos = checklist obligatorio vigente: el de la ejecución si la hubo (exacto), si no
        // el que la app elegiría hoy (templateParaHabitacion).
        // asignaciones.fecha es DATE local: se compara contra desde/hasta sin pasar por UTC.
        $pX = [$desde, $hasta];
        $hX = $this->hotelCond($hotel, $pX);
        $ejecucionesDe = []; // asignacion_id → sus ejecuciones
        foreach (Database::fetchAll(
            "SELECT ec.id, ec.asignacion_id, ec.template_id, ec.estado,
                    CASE WHEN " . self::CON_TRABAJO . " THEN 1 ELSE 0 END AS con_trabajo
               FROM #__ejecuciones_checklist ec
               JOIN #__asignaciones asg ON asg.id = ec.asignacion_id
               JOIN #__habitaciones h ON h.id = asg.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
              WHERE asg.fecha BETWEEN ? AND ?
                    {$hX}
              ORDER BY ec.id",
            $pX
        ) as $x) {
            $ejecucionesDe[(int) $x['asignacion_id']][] = $x;
        }
        $pE = [$desde, $hasta];
        $hE = $this->hotelCond($hotel, $pE);
        $esperado = Database::fetchAll(
            "SELECT asg.id, asg.usuario_id, u.nombre, asg.habitacion_id, asg.fecha, asg.franja, asg.activa,
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
        // Días trabajados (pedido de Nicolás, 30/09/2026): un día cuenta si ese día la persona tuvo al
        // menos una asignación, aunque sea de una sola pieza (o área común). No cuenta la asignación
        // retirada sin trabajo (autocancelada, sacada del plan o pasada a otra persona sin que la
        // trabajara, p. ej. porque faltó), ni la que solo tuvo el atajo «Marcar limpia» de otra persona.
        $diasTrabajados = []; // uid → fecha local del turno → nombre
        foreach ($esperado as $f) {
            $uid  = (int) $f['usuario_id'];
            $base = $f['habitacion_id'] . ':' . $f['fecha'] . ':' . ($f['franja'] ?? '') . ':';
            $trabajadas = []; // vuelta → template de su limpieza
            $soloAtajo  = false;
            foreach ($ejecucionesDe[(int) $f['id']] ?? [] as $x) {
                // Una limpieza recién empezada (en curso, aún sin ítems) también es trabajo suyo.
                if ((int) $x['con_trabajo'] === 1 || $x['estado'] === 'en_progreso') {
                    $trabajadas[$vueltas[(int) $x['id']] ?? 0] ??= (int) $x['template_id'];
                } else {
                    $soloAtajo = true;
                }
            }
            if ($trabajadas !== [] || (!$soloAtajo && (int) $f['activa'] === 1)) {
                $diasTrabajados[$uid][(string) $f['fecha']] = (string) $f['nombre'];
            }
            if ($trabajadas === []) {
                if ($soloAtajo) {
                    continue;
                }
                $trabajadas = [0 => null];
                $cuenta = (int) $f['activa'] === 1 || (int) $f['pasada_a_otro'] > 0;
            } else {
                $cuenta = true;
            }
            foreach ($trabajadas as $vuelta => $templateId) {
                $c = $base . $vuelta;
                $ciclosE[$uid][$c] ??= [
                    'nombre' => (string) $f['nombre'], 'habitacion_id' => (int) $f['habitacion_id'],
                    'cuenta' => false, 'template_id' => null,
                ];
                $ciclosE[$uid][$c]['cuenta'] = $ciclosE[$uid][$c]['cuenta'] || $cuenta;
                if ($ciclosE[$uid][$c]['template_id'] === null && $templateId !== null) {
                    $ciclosE[$uid][$c]['template_id'] = $templateId;
                }
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

        foreach ($diasTrabajados as $uid => $fechas) {
            $asegurar($filas, $uid, (string) reset($fechas));
            $filas[$uid]['dias_trabajados'] = count($fechas);
        }

        // ── Jornada (tiempo completo / parcial) y RUT de cada persona: contexto de sus KPIs y llave con RRHH ──
        if ($filas !== []) {
            $ids = array_keys($filas);
            $marcas = implode(', ', array_fill(0, count($ids), '?'));
            foreach (Database::fetchAll("SELECT id, rut, jornada FROM #__usuarios WHERE id IN ({$marcas})", $ids) as $u) {
                $filas[(int) $u['id']]['jornada'] = $u['jornada'] !== null ? (string) $u['jornada'] : null;
                $filas[(int) $u['id']]['rut']     = (string) $u['rut'];
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
     * Vuelta de cada limpieza terminada dentro de su (pieza, fecha del turno, franja). La primera es
     * la vuelta 0. Una limpieza que empieza DESPUÉS de otra que no fue rechazada (aprobada, aprobada
     * por el cierre automático o todavía sin inspeccionar) es trabajo nuevo —el nochero de las 16:00,
     * un turnover— y abre la vuelta siguiente: cuenta como otra pieza asignada. Una re-limpieza tras
     * un RECHAZO sigue en la misma vuelta: es el mismo ciclo, con la escalera 100/50/0.
     * Se calcula con un día de margen a cada lado del rango para que un ciclo reciba la misma vuelta
     * en todas las consultas de la ficha, las que filtran por fecha del turno (Asignadas) y las que
     * filtran por hora de inicio en UTC (créditos, piezas, rechazos).
     *
     * @return array<int, int> ejecucion_id → vuelta
     */
    private function vueltasPorEjecucion(string $desde, string $hasta): array
    {
        $filas = Database::fetchAll(
            "SELECT ec.id, ec.habitacion_id, asg.fecha, asg.franja, a.veredicto
               FROM #__ejecuciones_checklist ec
               JOIN #__asignaciones asg ON asg.id = ec.asignacion_id
          LEFT JOIN #__auditorias a ON a.ejecucion_id = ec.id
              WHERE ec.estado IN ('completada', 'auditada')
                AND asg.fecha BETWEEN ? AND ?
              ORDER BY ec.timestamp_inicio, ec.id",
            [date('Y-m-d', strtotime($desde . ' -1 day')), date('Y-m-d', strtotime($hasta . ' +1 day'))]
        );
        $vueltas = [];
        $ciclos  = []; // pieza:fecha:franja → [vuelta en curso, ¿la última limpieza quedó sin rechazo?]
        foreach ($filas as $f) {
            $base = $f['habitacion_id'] . ':' . $f['fecha'] . ':' . ($f['franja'] ?? '');
            if (!isset($ciclos[$base])) {
                $ciclos[$base] = [0, false];
            } elseif ($ciclos[$base][1]) {
                $ciclos[$base][0]++;
            }
            $vueltas[(int) $f['id']] = $ciclos[$base][0];
            $ciclos[$base][1] = $f['veredicto'] !== 'rechazado';
        }
        return $vueltas;
    }

    /**
     * Trabajador N3: promedio y desviación estándar (σ) del equipo por KPI, y por persona
     * Δ + semáforo. Solo entran al promedio (y reciben semáforo) quienes tienen al menos
     * `min_datos` piezas trabajadas (aprobadas + rechazadas) en el período. Dirección por KPI según la ficha: más=mejor,
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

        // El mínimo se mide sobre las piezas TRABAJADAS (aprobadas + rechazadas): si contara solo las
        // aprobadas, quien más rechazos tiene quedaría en «pocos datos», sin semáforo y fuera del promedio.
        foreach ($trabajadores as &$t) {
            $t['datos_suficientes'] = ($t['habitaciones'] + $t['rechazadas_hab']) >= $config['min_datos'];
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
     * @return array{completadas:int, auditadas_humanas:int, rechazadas:int, aprobadas:int, cobertura_pct:?float, rechazo_pct:?float, aprobacion_pct:?float, por_turno:list<array<string, mixed>>}
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
            'rechazadas'        => $total['rechazadas'],
            'aprobadas'         => $total['aprobadas'],
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

    /** Texto de la jornada del usuario en planillas; vacío si nadie la ha definido. */
    private static function jornadaLabel(?string $jornada): string
    {
        return match ($jornada) {
            'completa' => 'Tiempo completo',
            'parcial'  => 'Tiempo parcial',
            default    => '',
        };
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
            'creditos'           => 'Créditos obtenidos',
            'aprobacion_primera' => 'Aprobación a la primera',
            'productividad'      => 'Productividad promedio',
            'tasa_desmarcados'   => 'Tasa de ítems desmarcados',
        ];
    }
}
