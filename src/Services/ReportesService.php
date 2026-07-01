<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;

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
        $params = [$desde, $hasta];
        $hotelCond = $this->hotelCond($hotel, $params);

        return Database::fetchAll(
            "SELECT DISTINCT ec.usuario_id AS usuario_id, u.nombre
               FROM #__ejecuciones_checklist ec
               JOIN #__usuarios u ON u.id = ec.usuario_id
               JOIN #__habitaciones h ON h.id = ec.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
              WHERE DATE(ec.timestamp_inicio) BETWEEN ? AND ?
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
        $params = [$desde, $hasta];
        $hotelCond = $this->hotelCond($hotel, $params);

        // Créditos por persona (marcado_por), solo obligatorios. Ver docs/creditos-rework.md.
        //   habitaciones      = piezas donde la persona obtuvo al menos un crédito.
        //   creditos          = obligatorios marcados y no desmarcados, de ejecuciones no rechazadas.
        //   creditos_maximos  = intentos (créditos + obligatorios que le desmarcó el auditor).
        return Database::fetchAll(
            "SELECT u.id AS usuario_id,
                    u.nombre,
                    COUNT(DISTINCT CASE
                        WHEN ei.marcado = 1 AND ei.desmarcado_por_auditor = 0
                         AND (a.veredicto IS NULL OR a.veredicto <> 'rechazado')
                        THEN ec.habitacion_id END) AS habitaciones,
                    SUM(CASE
                        WHEN ei.marcado = 1 AND ei.desmarcado_por_auditor = 0
                         AND (a.veredicto IS NULL OR a.veredicto <> 'rechazado')
                        THEN 1 ELSE 0 END) AS creditos,
                    SUM(CASE
                        WHEN (ei.marcado = 1 AND ei.desmarcado_por_auditor = 0
                              AND (a.veredicto IS NULL OR a.veredicto <> 'rechazado'))
                          OR ei.desmarcado_por_auditor = 1
                        THEN 1 ELSE 0 END) AS creditos_maximos
               FROM #__ejecuciones_items ei
               JOIN #__usuarios u ON u.id = ei.marcado_por
               JOIN #__ejecuciones_checklist ec ON ec.id = ei.ejecucion_id
               JOIN #__items_checklist ic ON ic.id = ei.item_id AND ic.obligatorio = 1
               JOIN #__habitaciones h ON h.id = ec.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
          LEFT JOIN #__auditorias a ON a.ejecucion_id = ec.id
              WHERE ec.estado IN ('completada', 'auditada')
                AND ei.marcado_por IS NOT NULL
                AND DATE(ec.timestamp_inicio) BETWEEN ? AND ?
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
        $params = [$desde, $hasta];
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
              WHERE DATE(a.created_at) BETWEEN ? AND ?
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

    // ─── KPIs individuales ────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function kpiTiempoPromedio(string $desde, string $hasta, string $hotel, ?int $usuarioId): array
    {
        $params = [$desde, $hasta];
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
                AND DATE(ec.timestamp_inicio) BETWEEN ? AND ?
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
        $params = [$desde, $hasta];
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
              WHERE DATE(a.created_at) BETWEEN ? AND ?
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
        // Créditos por persona (marcado_por), solo obligatorios. Ver docs/creditos-rework.md.
        // Numerador = obligatorios marcados y no desmarcados, de ejecuciones NO rechazadas
        //             (así los ítems heredados en la re-limpieza no se doble-cuentan).
        $pC = [$desde, $hasta];
        $hC = $this->hotelCond($hotel, $pC);
        $uC = $this->marcadoPorCond($usuarioId, $pC);
        $creditos = (int) Database::fetchColumn(
            "SELECT COUNT(*)
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
                AND DATE(ec.timestamp_inicio) BETWEEN ? AND ?
                    {$hC}{$uC}",
            $pC
        );

        // Intentos fallidos = obligatorios desmarcados por el auditor (atribuidos a quien los
        // marcó mal). El denominador = créditos + fallidos → el % castiga el error.
        $pD = [$desde, $hasta];
        $hD = $this->hotelCond($hotel, $pD);
        $uD = $this->marcadoPorCond($usuarioId, $pD);
        $desmarcados = (int) Database::fetchColumn(
            "SELECT COUNT(*)
               FROM #__ejecuciones_items ei
               JOIN #__ejecuciones_checklist ec ON ec.id = ei.ejecucion_id
               JOIN #__items_checklist ic ON ic.id = ei.item_id
               JOIN #__habitaciones h ON h.id = ec.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
              WHERE ei.desmarcado_por_auditor = 1 AND ic.obligatorio = 1
                AND ei.marcado_por IS NOT NULL
                AND ec.estado IN ('completada', 'auditada')
                AND DATE(ec.timestamp_inicio) BETWEEN ? AND ?
                    {$hD}{$uD}",
            $pD
        );

        $total = $creditos + $desmarcados; // obligatorios intentados
        if ($total === 0) {
            return ['valor' => null, 'unidad' => '%', 'meta' => 90.0, 'contexto' => '0 ítems', 'estado' => 'sin_datos'];
        }

        $valor = round($creditos / $total * 100, 1);
        $meta  = 90.0;

        return [
            'valor'    => $valor,
            'unidad'   => '%',
            'meta'     => $meta,
            'contexto' => "{$creditos} / {$total} ítems",
            'estado'   => $valor >= $meta ? 'ok' : ($valor >= 80.0 ? 'alerta' : 'critico'),
        ];
    }

    /** @return array<string, mixed> */
    private function kpiAprobacionPrimera(string $desde, string $hasta, string $hotel, ?int $usuarioId): array
    {
        $params = [$desde, $hasta];
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
              WHERE DATE(a.created_at) BETWEEN ? AND ?
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
        $params = [$desde, $hasta];
        $h = $this->hotelCond($hotel, $params);
        $u = $this->userCond($usuarioId, $params, 'ec');

        $fila = Database::fetchOne(
            "SELECT COUNT(ec.id)                       AS completadas,
                    COUNT(DISTINCT ec.usuario_id)       AS trabajadoras,
                    COUNT(DISTINCT DATE(ec.timestamp_inicio)) AS dias
               FROM #__ejecuciones_checklist ec
               JOIN #__habitaciones h ON h.id = ec.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
              WHERE ec.estado IN ('completada', 'auditada')
                AND DATE(ec.timestamp_inicio) BETWEEN ? AND ?
                    {$h}{$u}",
            $params
        );

        $trabajadoras = (int) ($fila['trabajadoras'] ?? 0);
        $dias         = (int) ($fila['dias'] ?? 0);
        $completadas  = (int) ($fila['completadas'] ?? 0);

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
        $params = [$desde, $hasta];
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
                AND DATE(ec.timestamp_inicio) BETWEEN ? AND ?
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
