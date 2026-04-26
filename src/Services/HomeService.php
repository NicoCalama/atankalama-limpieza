<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

/**
 * Servicio de datos para las cuatro homes (trabajador, supervisora, recepción, admin).
 *
 * Encapsula todo el acceso a base de datos que antes vivía como SQL crudo dentro
 * del HomeController. El controller delega aquí cualquier consulta no trivial y
 * conserva solo la responsabilidad de validar el request y armar la respuesta.
 */
final class HomeService
{
    /**
     * Límite de BD en MB usado para calcular el porcentaje en la Home Admin.
     * Se puede sobrescribir desde .env vía DB_LIMITE_MB.
     */
    private const DB_LIMITE_MB_DEFAULT = 512;

    public function __construct(
        private readonly AsignacionService $asignaciones = new AsignacionService(),
    ) {
    }

    // -----------------------------------------------------------------------
    // Helpers compartidos
    // -----------------------------------------------------------------------

    /**
     * Cola de habitaciones del trabajador para una fecha.
     *
     * @return array<int, array<string, mixed>>
     */
    public function colaTrabajador(int $usuarioId, string $fecha): array
    {
        return $this->asignaciones->colaDelTrabajador($usuarioId, $fecha);
    }

    // -----------------------------------------------------------------------
    // Home Trabajador
    // -----------------------------------------------------------------------

    /**
     * ¿El trabajador ya envió un aviso de disponibilidad en la fecha indicada?
     */
    public function avisoDisponibilidadEnviado(int $trabajadorId, string $fecha): bool
    {
        $fila = Database::fetchOne(
            'SELECT 1 FROM notificaciones_disponibilidad WHERE trabajador_id = ? AND fecha = ?',
            [$trabajadorId, $fecha]
        );
        return $fila !== null;
    }

    /**
     * Registra el aviso de disponibilidad. Retorna true si se registró,
     * false si ya existía uno para esa fecha (idempotencia explícita).
     */
    public function registrarAvisoDisponibilidad(int $trabajadorId, string $fecha): bool
    {
        if ($this->avisoDisponibilidadEnviado($trabajadorId, $fecha)) {
            return false;
        }

        Database::execute(
            "INSERT INTO notificaciones_disponibilidad (trabajador_id, fecha, created_at)
             VALUES (?, ?, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))",
            [$trabajadorId, $fecha]
        );
        return true;
    }

    // -----------------------------------------------------------------------
    // Home Supervisora
    // -----------------------------------------------------------------------

    /**
     * Trabajadores con turno hoy junto con horarios de inicio/fin.
     *
     * @return array<int, array<string, mixed>>
     */
    public function trabajadoresEnTurno(string $fecha): array
    {
        return Database::fetchAll(
            'SELECT u.id, u.nombre, u.rut, u.hotel_default,
                    t.hora_inicio, t.hora_fin
               FROM usuarios u
               JOIN usuarios_turnos ut ON ut.usuario_id = u.id
               JOIN turnos t ON t.id = ut.turno_id
              WHERE ut.fecha = ? AND u.activo = 1
              ORDER BY u.nombre',
            [$fecha]
        );
    }

    /**
     * Indica si existe una alerta activa de "trabajador_en_riesgo" para el
     * trabajador y fecha dados (uso del marcador `_dedupe` en contexto_json).
     */
    public function trabajadorEnRiesgo(int $trabajadorId, string $fecha): bool
    {
        $dedupe = "trabajador:{$trabajadorId}:fecha:{$fecha}";
        $fila = Database::fetchOne(
            "SELECT 1 FROM alertas_activas WHERE tipo = 'trabajador_en_riesgo' AND contexto_json LIKE ?",
            ['%"_dedupe":"' . $dedupe . '"%']
        );
        return $fila !== null;
    }

    // -----------------------------------------------------------------------
    // Home Admin — hoteles, alertas, métricas y KPIs
    // -----------------------------------------------------------------------

    /**
     * Hoteles indexados por código. Cada entrada contiene id, codigo y nombre.
     *
     * @return array<string, array{id: int, codigo: string, nombre: string}>
     */
    public function hotelesPorCodigo(): array
    {
        $filas = Database::fetchAll('SELECT id, codigo, nombre FROM hoteles ORDER BY codigo');
        $indexado = [];
        foreach ($filas as $h) {
            $indexado[(string) $h['codigo']] = [
                'id' => (int) $h['id'],
                'codigo' => (string) $h['codigo'],
                'nombre' => (string) $h['nombre'],
            ];
        }
        return $indexado;
    }

    /**
     * Alertas activas P0–P1 (con filtro opcional por hotel) ya formateadas.
     * Retorna `[lista, total]` donde lista contiene solo las primeras 5.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    public function alertasOperativasAdmin(string $hotel): array
    {
        $sql = 'SELECT a.id, a.tipo, a.prioridad, a.titulo, a.descripcion, a.contexto_json,
                       a.hotel_id, a.created_at, ho.codigo AS hotel_codigo
                  FROM alertas_activas a
             LEFT JOIN hoteles ho ON ho.id = a.hotel_id
                 WHERE a.prioridad <= 1';
        $params = [];
        if ($hotel !== 'ambos') {
            $sql .= ' AND (ho.codigo = ? OR a.hotel_id IS NULL)';
            $params[] = $hotel;
        }
        $sql .= ' ORDER BY a.prioridad ASC, a.created_at ASC';

        $filas = Database::fetchAll($sql, $params);
        $total = count($filas);

        $alertas = [];
        foreach (array_slice($filas, 0, 5) as $a) {
            $contexto = null;
            if (!empty($a['contexto_json'])) {
                $ctx = json_decode((string) $a['contexto_json'], true);
                if (is_array($ctx)) {
                    $contexto = $ctx;
                }
            }
            $alertas[] = [
                'id' => (int) $a['id'],
                'tipo' => (string) $a['tipo'],
                'prioridad' => (int) $a['prioridad'],
                'titulo' => (string) $a['titulo'],
                'descripcion' => (string) $a['descripcion'],
                'hotel_codigo' => $a['hotel_codigo'] ?? null,
                'contexto' => $contexto,
                'created_at' => (string) $a['created_at'],
            ];
        }
        return [$alertas, $total];
    }

    /**
     * Métricas operativas del día para un hotel concreto.
     *
     * @return array<string, mixed>
     */
    public function metricasOperativasHotel(int $hotelId, string $fecha): array
    {
        // Habitaciones activas agrupadas por estado actual
        $habs = Database::fetchAll(
            'SELECT estado, COUNT(*) AS c FROM habitaciones WHERE activa = 1 AND hotel_id = ? GROUP BY estado',
            [$hotelId]
        );
        $porEstado = [
            'sucia' => 0,
            'en_progreso' => 0,
            'completada_pendiente_auditoria' => 0,
            'aprobada' => 0,
            'aprobada_con_observacion' => 0,
            'rechazada' => 0,
        ];
        $total = 0;
        foreach ($habs as $r) {
            $e = (string) $r['estado'];
            $c = (int) $r['c'];
            $porEstado[$e] = $c;
            $total += $c;
        }
        $limpias = $porEstado['aprobada'] + $porEstado['aprobada_con_observacion'];
        $enProgreso = $porEstado['en_progreso'];
        $pendientes = $porEstado['sucia'] + $porEstado['rechazada'];
        $porAuditar = $porEstado['completada_pendiente_auditoria'];

        // Habitaciones sucias del hotel sin asignación activa para hoy
        $noAsignadas = (int) (Database::fetchOne(
            "SELECT COUNT(*) AS c
               FROM habitaciones h
          LEFT JOIN asignaciones a ON a.habitacion_id = h.id AND a.fecha = ? AND a.activa = 1
              WHERE h.activa = 1 AND h.hotel_id = ? AND h.estado = 'sucia' AND a.id IS NULL",
            [$fecha, $hotelId]
        )['c'] ?? 0);

        // Auditorías del día agrupadas por veredicto
        $auditoriasFila = Database::fetchAll(
            'SELECT au.veredicto, COUNT(*) AS c
               FROM auditorias au
               JOIN habitaciones h ON h.id = au.habitacion_id
              WHERE h.hotel_id = ? AND DATE(au.created_at) = ?
           GROUP BY au.veredicto',
            [$hotelId, $fecha]
        );
        $audAprobadas = 0;
        $audObs = 0;
        $audRech = 0;
        foreach ($auditoriasFila as $r) {
            $v = (string) $r['veredicto'];
            $c = (int) $r['c'];
            if ($v === 'aprobado') {
                $audAprobadas = $c;
            } elseif ($v === 'aprobado_con_observacion') {
                $audObs = $c;
            } elseif ($v === 'rechazado') {
                $audRech = $c;
            }
        }

        // Trabajadores con turno hoy en este hotel (incluye hotel_default = 'ambos')
        $enTurno = (int) (Database::fetchOne(
            "SELECT COUNT(DISTINCT u.id) AS c
               FROM usuarios u
               JOIN usuarios_turnos ut ON ut.usuario_id = u.id
              WHERE ut.fecha = ? AND u.activo = 1
                AND (u.hotel_default = (SELECT codigo FROM hoteles WHERE id = ?) OR u.hotel_default = 'ambos')",
            [$fecha, $hotelId]
        )['c'] ?? 0);

        // Trabajadores marcados como disponibles (alertas activas)
        $disponibles = (int) (Database::fetchOne(
            "SELECT COUNT(*) AS c
               FROM alertas_activas
              WHERE tipo = 'trabajador_disponible' AND (hotel_id = ? OR hotel_id IS NULL)",
            [$hotelId]
        )['c'] ?? 0);

        // Tickets abiertos del hotel
        $ticketsAbiertos = (int) (Database::fetchOne(
            "SELECT COUNT(*) AS c FROM tickets WHERE hotel_id = ? AND estado IN ('abierto','en_progreso')",
            [$hotelId]
        )['c'] ?? 0);

        // Tiempo promedio (min) de ejecuciones cerradas hoy en este hotel
        $tiempoProm = Database::fetchOne(
            'SELECT AVG((julianday(e.timestamp_fin) - julianday(e.timestamp_inicio)) * 1440) AS prom
               FROM ejecuciones_checklist e
               JOIN habitaciones h ON h.id = e.habitacion_id
              WHERE h.hotel_id = ?
                AND e.timestamp_fin IS NOT NULL
                AND DATE(e.timestamp_fin) = ?',
            [$hotelId, $fecha]
        );
        $tiempoPromMin = ($tiempoProm !== null && $tiempoProm['prom'] !== null)
            ? (int) round((float) $tiempoProm['prom'])
            : null;

        return [
            'habitaciones' => [
                'limpias' => $limpias,
                'en_progreso' => $enProgreso,
                'pendientes' => $pendientes,
                'por_auditar' => $porAuditar,
                'no_asignadas' => $noAsignadas,
                'total' => $total,
            ],
            'auditorias' => [
                'aprobadas' => $audAprobadas,
                'con_observacion' => $audObs,
                'rechazadas' => $audRech,
                'total' => $audAprobadas + $audObs + $audRech,
            ],
            'trabajadores' => [
                'en_turno' => $enTurno,
                'disponibles' => $disponibles,
            ],
            'tickets_abiertos' => $ticketsAbiertos,
            'tiempo_promedio_minutos' => $tiempoPromMin,
        ];
    }

    /**
     * Calcula KPIs (tiempo promedio, tasa de rechazo, eficiencia) para un hotel.
     *
     * @param array<string, mixed> $metricas
     * @return array<string, mixed>
     */
    public function kpisHotel(int $hotelId, string $fecha, array $metricas): array
    {
        // KPI 1: tiempo promedio
        $metaTiempo = 30;
        $tiempoValor = $metricas['tiempo_promedio_minutos'];
        if ($tiempoValor === null) {
            $tiempoEstado = 'SIN_DATOS';
            $tiempoPct = 0;
        } else {
            if ($tiempoValor <= $metaTiempo) {
                $tiempoEstado = 'OK';
            } elseif ($tiempoValor <= $metaTiempo * 1.05) {
                $tiempoEstado = 'ALERTA';
            } else {
                $tiempoEstado = 'CRITICO';
            }
            $tiempoPct = $tiempoValor > 0 ? min(100, (int) round($metaTiempo * 100 / $tiempoValor)) : 0;
        }

        // KPI 2: tasa de rechazo
        $metaRechazo = 5.0;
        $totalAud = (int) $metricas['auditorias']['total'];
        $rech = (int) $metricas['auditorias']['rechazadas'];
        $tasa = $totalAud > 0 ? round($rech * 100 / $totalAud, 1) : 0.0;
        if ($totalAud === 0) {
            $rechEstado = 'SIN_DATOS';
        } elseif ($tasa <= $metaRechazo) {
            $rechEstado = 'OK';
        } elseif ($tasa <= 7.0) {
            $rechEstado = 'ALERTA';
        } else {
            $rechEstado = 'CRITICO';
        }

        // KPI 3: eficiencia de equipo = completadas / asignadas
        $metaEf = 85;
        $asignadas = (int) (Database::fetchOne(
            'SELECT COUNT(*) AS c
               FROM asignaciones a
               JOIN habitaciones h ON h.id = a.habitacion_id
              WHERE h.hotel_id = ? AND a.fecha = ? AND a.activa = 1',
            [$hotelId, $fecha]
        )['c'] ?? 0);
        $completadas = (int) $metricas['habitaciones']['limpias']
            + (int) $metricas['habitaciones']['por_auditar'];
        $eficiencia = $asignadas > 0 ? (int) round($completadas * 100 / $asignadas) : 0;
        if ($asignadas === 0) {
            $efEstado = 'SIN_DATOS';
        } elseif ($eficiencia >= $metaEf) {
            $efEstado = 'OK';
        } elseif ($eficiencia >= 75) {
            $efEstado = 'ALERTA';
        } else {
            $efEstado = 'CRITICO';
        }

        return [
            'tiempo_promedio' => [
                'valor' => $tiempoValor,
                'unidad' => 'min',
                'meta' => $metaTiempo,
                'estado' => $tiempoEstado,
                'porcentaje' => $tiempoPct,
            ],
            'tasa_rechazo' => [
                'valor' => $tasa,
                'unidad' => '%',
                'meta' => $metaRechazo,
                'estado' => $rechEstado,
                'contexto' => $totalAud === 0
                    ? 'Sin auditorías hoy'
                    : ($rech . ' rechazadas de ' . $totalAud . ' auditadas'),
            ],
            'eficiencia_equipo' => [
                'valor' => $eficiencia,
                'unidad' => '%',
                'meta' => $metaEf,
                'estado' => $efEstado,
                'contexto' => $asignadas === 0
                    ? 'Sin asignaciones hoy'
                    : ($completadas . ' completadas de ' . $asignadas . ' asignadas'),
            ],
        ];
    }

    /**
     * Suma las métricas operativas por hotel y devuelve el consolidado global.
     *
     * @param array<string, array<string, mixed>> $porHotel
     * @return array<string, mixed>
     */
    public function consolidarMetricas(array $porHotel): array
    {
        $habs = ['limpias' => 0, 'en_progreso' => 0, 'pendientes' => 0, 'por_auditar' => 0, 'no_asignadas' => 0, 'total' => 0];
        $auds = ['aprobadas' => 0, 'con_observacion' => 0, 'rechazadas' => 0, 'total' => 0];
        $trab = ['en_turno' => 0, 'disponibles' => 0];
        $tickets = 0;
        $tiempos = [];
        foreach ($porHotel as $m) {
            foreach ($habs as $k => $_) {
                $habs[$k] += (int) $m['habitaciones'][$k];
            }
            foreach ($auds as $k => $_) {
                $auds[$k] += (int) $m['auditorias'][$k];
            }
            foreach ($trab as $k => $_) {
                $trab[$k] += (int) $m['trabajadores'][$k];
            }
            $tickets += (int) $m['tickets_abiertos'];
            if ($m['tiempo_promedio_minutos'] !== null) {
                $tiempos[] = (int) $m['tiempo_promedio_minutos'];
            }
        }
        $tiempoProm = $tiempos === [] ? null : (int) round(array_sum($tiempos) / count($tiempos));
        return [
            'habitaciones' => $habs,
            'auditorias' => $auds,
            'trabajadores' => $trab,
            'tickets_abiertos' => $tickets,
            'tiempo_promedio_minutos' => $tiempoProm,
        ];
    }

    /**
     * KPIs consolidados (sin tocar BD) a partir de las métricas ya consolidadas.
     *
     * @param array<string, array<string, mixed>> $kpisPorHotel sin uso directo, se mantiene por simetría con el controller.
     * @param array<string, array<string, mixed>> $metricasPorHotel
     * @return array<string, mixed>
     */
    public function consolidarKpis(array $kpisPorHotel, array $metricasPorHotel): array
    {
        $consolidado = $this->consolidarMetricas($metricasPorHotel);
        return $this->kpisDesdeMetricasConsolidadas($consolidado);
    }

    /**
     * @param array<string, mixed> $metricas
     * @return array<string, mixed>
     */
    public function kpisDesdeMetricasConsolidadas(array $metricas): array
    {
        $metaTiempo = 30;
        $tiempoValor = $metricas['tiempo_promedio_minutos'];
        if ($tiempoValor === null) {
            $tiempoEstado = 'SIN_DATOS';
            $tiempoPct = 0;
        } else {
            if ($tiempoValor <= $metaTiempo) {
                $tiempoEstado = 'OK';
            } elseif ($tiempoValor <= $metaTiempo * 1.05) {
                $tiempoEstado = 'ALERTA';
            } else {
                $tiempoEstado = 'CRITICO';
            }
            $tiempoPct = $tiempoValor > 0 ? min(100, (int) round($metaTiempo * 100 / $tiempoValor)) : 0;
        }

        $totalAud = (int) $metricas['auditorias']['total'];
        $rech = (int) $metricas['auditorias']['rechazadas'];
        $tasa = $totalAud > 0 ? round($rech * 100 / $totalAud, 1) : 0.0;
        if ($totalAud === 0) {
            $rechEstado = 'SIN_DATOS';
        } elseif ($tasa <= 5.0) {
            $rechEstado = 'OK';
        } elseif ($tasa <= 7.0) {
            $rechEstado = 'ALERTA';
        } else {
            $rechEstado = 'CRITICO';
        }

        $completadas = (int) $metricas['habitaciones']['limpias'] + (int) $metricas['habitaciones']['por_auditar'];
        $enProgreso = (int) $metricas['habitaciones']['en_progreso'];
        $pendientes = (int) $metricas['habitaciones']['pendientes'];
        $asignadas = $completadas + $enProgreso + $pendientes;
        $eficiencia = $asignadas > 0 ? (int) round($completadas * 100 / $asignadas) : 0;
        if ($asignadas === 0) {
            $efEstado = 'SIN_DATOS';
        } elseif ($eficiencia >= 85) {
            $efEstado = 'OK';
        } elseif ($eficiencia >= 75) {
            $efEstado = 'ALERTA';
        } else {
            $efEstado = 'CRITICO';
        }

        return [
            'tiempo_promedio' => [
                'valor' => $tiempoValor,
                'unidad' => 'min',
                'meta' => $metaTiempo,
                'estado' => $tiempoEstado,
                'porcentaje' => $tiempoPct,
            ],
            'tasa_rechazo' => [
                'valor' => $tasa,
                'unidad' => '%',
                'meta' => 5.0,
                'estado' => $rechEstado,
                'contexto' => $totalAud === 0
                    ? 'Sin auditorías hoy'
                    : ($rech . ' rechazadas de ' . $totalAud . ' auditadas'),
            ],
            'eficiencia_equipo' => [
                'valor' => $eficiencia,
                'unidad' => '%',
                'meta' => 85,
                'estado' => $efEstado,
                'contexto' => $asignadas === 0
                    ? 'Sin asignaciones hoy'
                    : ($completadas . ' completadas de ' . $asignadas . ' asignadas'),
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Home Admin — Salud del sistema
    // -----------------------------------------------------------------------

    /**
     * Estado de la última sincronización con Cloudbeds.
     *
     * @return array<string, mixed>
     */
    public function sistemaCloudbeds(): array
    {
        $fila = Database::fetchOne('SELECT * FROM cloudbeds_sync_historial ORDER BY id DESC LIMIT 1');
        if ($fila === null) {
            return [
                'estado' => 'ALERTA',
                'ultima_sync' => null,
                'ultima_sync_relativa' => 'Nunca',
                'resultado' => null,
                'minutos_desde_ultima' => null,
            ];
        }
        $finalizada = (string) ($fila['finalizada_at'] ?? $fila['iniciada_at'] ?? '');
        $minutos = null;
        if ($finalizada !== '') {
            $ts = strtotime($finalizada);
            if ($ts !== false) {
                $minutos = (int) floor((time() - $ts) / 60);
            }
        }
        $resultado = (string) ($fila['resultado'] ?? '');
        if ($resultado === 'fallida' || $resultado === 'error') {
            $estado = 'ERROR';
        } elseif ($minutos === null) {
            $estado = 'ALERTA';
        } elseif ($minutos <= 30) {
            $estado = 'OK';
        } elseif ($minutos <= 60) {
            $estado = 'ALERTA';
        } else {
            $estado = 'ERROR';
        }
        return [
            'estado' => $estado,
            'ultima_sync' => $finalizada !== '' ? $finalizada : null,
            'ultima_sync_relativa' => $minutos === null
                ? null
                : ($minutos <= 1 ? 'hace un momento' : 'hace ' . $minutos . ' min'),
            'resultado' => $resultado !== '' ? $resultado : null,
            'minutos_desde_ultima' => $minutos,
            'errores_count' => (int) ($fila['errores_count'] ?? 0),
            'error_mensaje' => $fila['error_mensaje'] ?? null,
        ];
    }

    /**
     * Resumen de errores y warnings del día actual desde logs_eventos.
     *
     * @return array<string, mixed>
     */
    public function sistemaErroresLogs(string $fecha): array
    {
        $fila = Database::fetchOne(
            "SELECT
                SUM(CASE WHEN nivel = 'ERROR' THEN 1 ELSE 0 END) AS errores,
                SUM(CASE WHEN nivel = 'WARNING' THEN 1 ELSE 0 END) AS warnings,
                MAX(created_at) AS ultimo
               FROM logs_eventos
              WHERE DATE(created_at) = ? AND nivel IN ('ERROR','WARNING')",
            [$fecha]
        );
        $errores = (int) ($fila['errores'] ?? 0);
        $warnings = (int) ($fila['warnings'] ?? 0);
        $ultimo = $fila['ultimo'] ?? null;
        $severidad = $errores > 0 ? 'alta' : ($warnings > 0 ? 'media' : 'baja');
        return [
            'cantidad_hoy' => $errores + $warnings,
            'errores' => $errores,
            'warnings' => $warnings,
            'timestamp_ultimo' => $ultimo,
            'severidad' => $severidad,
        ];
    }

    /**
     * Estado del archivo de base de datos (tamaño, límite y porcentaje).
     *
     * @return array<string, mixed>
     */
    public function sistemaBaseDatos(): array
    {
        $dbPath = (string) Config::get('DB_PATH', 'database/atankalama.db');
        if (!str_starts_with($dbPath, '/') && !preg_match('/^[A-Za-z]:/', $dbPath)) {
            $dbPath = Config::basePath() . DIRECTORY_SEPARATOR . $dbPath;
        }
        $tamanoBytes = @filesize($dbPath);
        $tamanoMb = ($tamanoBytes === false) ? null : (float) round($tamanoBytes / 1024 / 1024, 1);
        $limiteMb = (int) Config::getInt('DB_LIMITE_MB', self::DB_LIMITE_MB_DEFAULT);
        $pct = ($tamanoMb === null || $limiteMb <= 0) ? 0 : (int) round($tamanoMb * 100 / $limiteMb);
        if ($pct < 70) {
            $estado = 'OK';
        } elseif ($pct < 85) {
            $estado = 'ALERTA';
        } else {
            $estado = 'CRITICO';
        }
        return [
            'tamano_mb' => $tamanoMb,
            'limite_mb' => $limiteMb,
            'porcentaje_usado' => $pct,
            'estado' => $estado,
        ];
    }

    /**
     * Sesiones activas en este momento, excluyendo al admin que consulta.
     *
     * @return array<string, mixed>
     */
    public function sistemaUsuariosActivos(int $adminId): array
    {
        $ahora = date('Y-m-d H:i:s');
        $sesiones = Database::fetchAll(
            'SELECT s.usuario_id, u.nombre, MAX(s.created_at) AS created_at
               FROM sesiones s
               JOIN usuarios u ON u.id = s.usuario_id
              WHERE s.expires_at > ? AND s.usuario_id <> ? AND u.activo = 1
           GROUP BY s.usuario_id, u.nombre
           ORDER BY created_at DESC',
            [$ahora, $adminId]
        );
        $lista = [];
        foreach ($sesiones as $s) {
            $roles = Database::fetchAll(
                'SELECT r.nombre FROM roles r JOIN usuarios_roles ur ON ur.rol_id = r.id WHERE ur.usuario_id = ?',
                [(int) $s['usuario_id']]
            );
            $lista[] = [
                'usuario_id' => (int) $s['usuario_id'],
                'nombre' => (string) $s['nombre'],
                'roles' => array_map(static fn($r) => (string) $r['nombre'], $roles),
                'ultima_actividad' => (string) $s['created_at'],
            ];
        }
        return [
            'ahora' => count($lista),
            'listado' => $lista,
        ];
    }

    /**
     * Versión de la app, ambiente, commit y timestamp aproximado de deploy.
     *
     * @return array<string, mixed>
     */
    public function sistemaVersionApp(): array
    {
        $version = (string) Config::get('APP_VERSION', '1.0.0');
        $ambiente = (string) Config::get('APP_ENV', 'produccion');
        $commit = $this->leerCommitCorto();
        $deployTs = null;
        $headFile = Config::basePath() . '/.git/HEAD';
        if (is_file($headFile)) {
            $mtime = filemtime($headFile);
            if ($mtime !== false) {
                $deployTs = date('c', $mtime);
            }
        }
        return [
            'actual' => $version,
            'ambiente' => $ambiente,
            'commit_hash' => $commit,
            'timestamp_deploy' => $deployTs,
        ];
    }

    private function leerCommitCorto(): ?string
    {
        $headFile = Config::basePath() . '/.git/HEAD';
        if (!is_file($headFile)) {
            return null;
        }
        $head = trim((string) @file_get_contents($headFile));
        if ($head === '') {
            return null;
        }
        if (str_starts_with($head, 'ref: ')) {
            $ref = trim(substr($head, 5));
            $refFile = Config::basePath() . '/.git/' . $ref;
            if (is_file($refFile)) {
                $hash = trim((string) @file_get_contents($refFile));
                return $hash === '' ? null : substr($hash, 0, 7);
            }
            return null;
        }
        return substr($head, 0, 7);
    }
}
