<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaService;

final class HomeController
{
    public function __construct(
        private readonly AsignacionService $asignaciones = new AsignacionService(),
        private readonly AuditoriaService $auditorias = new AuditoriaService(),
    ) {
    }

    /**
     * GET /api/home/trabajador
     * Retorna la data necesaria para renderizar la home del trabajador.
     */
    public function trabajador(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }

        $hoy = date('Y-m-d');
        $cola = $this->asignaciones->colaDelTrabajador($usuario->id, $hoy);

        // Clasificar habitaciones por estado
        $completadas = 0;
        $enProgreso = 0;
        $pendientes = 0;
        $habitacionActual = null;
        $proximas = [];

        foreach ($cola as $item) {
            $estado = $item['estado'];

            if (in_array($estado, ['aprobada', 'aprobada_con_observacion', 'completada_pendiente_auditoria'], true)) {
                $completadas++;
                continue;
            }

            if ($estado === 'en_progreso') {
                $enProgreso++;
                if ($habitacionActual === null) {
                    $habitacionActual = $this->formatearHabitacion($item);
                } else {
                    $proximas[] = $this->formatearHabitacion($item);
                }
                continue;
            }

            if ($estado === 'sucia' || $estado === 'rechazada') {
                $pendientes++;
                if ($habitacionActual === null) {
                    $habitacionActual = $this->formatearHabitacion($item);
                } else {
                    $proximas[] = $this->formatearHabitacion($item);
                }
                continue;
            }

            // Cualquier otro estado — contar como completada
            $completadas++;
        }

        $total = $completadas + $enProgreso + $pendientes;

        // Hotel actual (del primer item de la cola, o del perfil del usuario)
        $hotelActual = null;
        if (!empty($cola)) {
            $hotelActual = [
                'codigo' => $cola[0]['hotel_codigo'],
                'nombre' => $this->nombreHotel($cola[0]['hotel_codigo']),
            ];
        } else {
            $hotelActual = [
                'codigo' => $usuario->hotelDefault,
                'nombre' => $this->nombreHotel($usuario->hotelDefault),
            ];
        }

        // Verificar si ya envió aviso de disponibilidad hoy
        $avisoEnviado = Database::fetchOne(
            "SELECT 1 FROM notificaciones_disponibilidad WHERE trabajador_id = ? AND fecha = ?",
            [$usuario->id, $hoy]
        ) !== null;

        $primerNombre = explode(' ', $usuario->nombre)[0];

        return Response::ok([
            'usuario' => [
                'id' => $usuario->id,
                'nombre' => $usuario->nombre,
                'primer_nombre' => $primerNombre,
                'rut' => $usuario->rut,
            ],
            'hotel_actual' => $hotelActual,
            'progreso' => [
                'completadas' => $completadas,
                'en_progreso' => $enProgreso,
                'pendientes' => $pendientes,
                'total' => $total,
                'todas_completadas' => $total > 0 && $pendientes === 0 && $enProgreso === 0,
            ],
            'habitacion_actual' => $habitacionActual,
            'proximas' => $proximas,
            'tiene_asignaciones_hoy' => $total > 0,
            'aviso_disponibilidad_enviado_hoy' => $avisoEnviado,
        ]);
    }

    /**
     * POST /api/disponibilidad/avisar
     * El trabajador avisa que está disponible.
     */
    public function avisarDisponibilidad(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }

        $hoy = date('Y-m-d');

        // Verificar que no haya avisado ya hoy
        $yaAviso = Database::fetchOne(
            "SELECT 1 FROM notificaciones_disponibilidad WHERE trabajador_id = ? AND fecha = ?",
            [$usuario->id, $hoy]
        );
        if ($yaAviso !== null) {
            return Response::error('YA_AVISADO', 'Ya enviaste un aviso de disponibilidad hoy.', 409);
        }

        Database::execute(
            "INSERT INTO notificaciones_disponibilidad (trabajador_id, fecha, created_at) VALUES (?, ?, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))",
            [$usuario->id, $hoy]
        );

        return Response::ok(['mensaje' => 'Aviso enviado a tu supervisora.']);
    }

    /**
     * GET /api/home/supervisora
     * Retorna progreso global y estado del equipo para la Home de Supervisora.
     */
    public function supervisora(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }

        $hotelQuery = $request->query['hotel'] ?? 'ambos';
        $hotel = is_string($hotelQuery) && $hotelQuery !== '' ? $hotelQuery : 'ambos';

        $hoy = date('Y-m-d');
        $horaActual = date('H:i');

        $trabajadores = Database::fetchAll(
            "SELECT u.id, u.nombre, u.rut, u.hotel_default,
                    t.hora_inicio, t.hora_fin
               FROM usuarios u
               JOIN usuarios_turnos ut ON ut.usuario_id = u.id
               JOIN turnos t ON t.id = ut.turno_id
              WHERE ut.fecha = ? AND u.activo = 1
              ORDER BY u.nombre",
            [$hoy]
        );

        $equipo = [];
        $globalCompletadas = 0;
        $globalEnProgreso = 0;
        $globalPendientes = 0;
        $globalRechazadas = 0;
        $globalTotal = 0;

        foreach ($trabajadores as $t) {
            $usuarioId = (int) $t['id'];
            $cola = $this->asignaciones->colaDelTrabajador($usuarioId, $hoy);

            $completadas = 0;
            $enProgreso = 0;
            $pendientes = 0;
            $rechazadas = 0;
            $habActual = null;
            foreach ($cola as $item) {
                $estado = $item['estado'];
                if (in_array($estado, ['aprobada', 'aprobada_con_observacion', 'completada_pendiente_auditoria'], true)) {
                    $completadas++;
                    continue;
                }
                if ($estado === 'rechazada') {
                    $rechazadas++;
                    $pendientes++;
                    if ($habActual === null) {
                        $habActual = $item;
                    }
                    continue;
                }
                if ($estado === 'en_progreso') {
                    $enProgreso++;
                    if ($habActual === null) {
                        $habActual = $item;
                    }
                    continue;
                }
                if ($estado === 'sucia') {
                    $pendientes++;
                    if ($habActual === null) {
                        $habActual = $item;
                    }
                }
            }
            $total = count($cola);

            $hotelCodigo = $t['hotel_default'] ?? null;
            if (!empty($cola)) {
                $hotelCodigo = $cola[0]['hotel_codigo'];
            }

            if ($hotel !== 'ambos' && $hotelCodigo !== $hotel) {
                continue;
            }

            $estadoTrab = 'en_tiempo';
            $sinTrabajo = ($pendientes === 0 && $enProgreso === 0);
            if ($sinTrabajo) {
                $estadoTrab = 'disponible';
            }
            $dedupe = "trabajador:{$usuarioId}:fecha:{$hoy}";
            $enRiesgo = Database::fetchOne(
                "SELECT 1 FROM alertas_activas WHERE tipo = 'trabajador_en_riesgo' AND contexto_json LIKE ?",
                ['%"_dedupe":"' . $dedupe . '"%']
            );
            if ($enRiesgo !== null) {
                $estadoTrab = 'en_riesgo';
            }

            $horaFin = (string) $t['hora_fin'];
            $tiempoRestante = max(0, $this->minutosEntre($horaActual, $horaFin));

            $equipo[] = [
                'usuario' => [
                    'id' => $usuarioId,
                    'nombre' => $t['nombre'],
                    'rut' => $t['rut'],
                ],
                'hotel_codigo' => $hotelCodigo,
                'estado' => $estadoTrab,
                'tiempo_restante_min' => $tiempoRestante,
                'hora_fin_turno' => $horaFin,
                'progreso' => [
                    'completadas' => $completadas,
                    'en_progreso' => $enProgreso,
                    'pendientes' => $pendientes,
                    'rechazadas' => $rechazadas,
                    'total' => $total,
                    'porcentaje' => $total === 0 ? 0 : (int) round($completadas * 100 / $total),
                ],
                'habitacion_actual' => $habActual === null ? null : [
                    'id' => (int) $habActual['habitacion_id'],
                    'numero' => $habActual['numero'],
                    'estado' => $habActual['estado'],
                    'tipo' => $habActual['tipo_nombre'],
                    'hotel_codigo' => $habActual['hotel_codigo'],
                ],
            ];

            $globalCompletadas += $completadas;
            $globalEnProgreso += $enProgreso;
            $globalPendientes += $pendientes;
            $globalRechazadas += $rechazadas;
            $globalTotal += $total;
        }

        $ordenEstado = ['en_riesgo' => 0, 'en_tiempo' => 1, 'disponible' => 2];
        usort($equipo, static function (array $a, array $b) use ($ordenEstado): int {
            $ea = $ordenEstado[$a['estado']] ?? 9;
            $eb = $ordenEstado[$b['estado']] ?? 9;
            if ($ea !== $eb) {
                return $ea - $eb;
            }
            if ($a['estado'] === 'disponible') {
                return strcmp((string) $a['usuario']['nombre'], (string) $b['usuario']['nombre']);
            }
            return $a['tiempo_restante_min'] - $b['tiempo_restante_min'];
        });

        $primerNombre = explode(' ', $usuario->nombre)[0];

        return Response::ok([
            'usuario' => [
                'id' => $usuario->id,
                'nombre' => $usuario->nombre,
                'primer_nombre' => $primerNombre,
                'rut' => $usuario->rut,
            ],
            'hotel_seleccionado' => $hotel,
            'progreso_global' => [
                'completadas' => $globalCompletadas,
                'en_progreso' => $globalEnProgreso,
                'pendientes' => $globalPendientes,
                'rechazadas' => $globalRechazadas,
                'total' => $globalTotal,
                'porcentaje' => $globalTotal === 0 ? 0 : (int) round($globalCompletadas * 100 / $globalTotal),
            ],
            'equipo' => $equipo,
            'total_trabajadores' => count($equipo),
            'permisos' => [
                'asignaciones_asignar_manual' => $usuario->tienePermiso('asignaciones.asignar_manual'),
                'asignaciones_auto' => $usuario->tienePermiso('asignaciones.auto_asignar'),
                'alertas_recibir_predictivas' => $usuario->tienePermiso('alertas.recibir_predictivas'),
                'auditoria_ver_bandeja' => $usuario->tienePermiso('auditoria.ver_bandeja'),
                'tickets_ver_todos' => $usuario->tienePermiso('tickets.ver_todos'),
            ],
        ]);
    }

    /**
     * GET /api/home/recepcion
     * Retorna la bandeja de auditoría para Recepción (o cualquiera con auditoria.ver_bandeja).
     */
    public function recepcion(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }

        $hotelQuery = $request->query['hotel'] ?? 'ambos';
        $hotel = is_string($hotelQuery) && $hotelQuery !== '' ? $hotelQuery : 'ambos';

        $pendientes = $this->auditorias->bandejaPendientes($hotel);

        $primerNombre = explode(' ', $usuario->nombre)[0];

        return Response::ok([
            'usuario' => [
                'id' => $usuario->id,
                'nombre' => $usuario->nombre,
                'primer_nombre' => $primerNombre,
                'rut' => $usuario->rut,
            ],
            'hotel_seleccionado' => $hotel,
            'habitaciones_pendientes' => $pendientes,
            'total_pendientes' => count($pendientes),
            'permisos' => [
                'auditoria_ver_bandeja' => $usuario->tienePermiso('auditoria.ver_bandeja'),
                'auditoria_aprobar' => $usuario->tienePermiso('auditoria.aprobar'),
                'auditoria_aprobar_con_observacion' => $usuario->tienePermiso('auditoria.aprobar_con_observacion'),
                'auditoria_rechazar' => $usuario->tienePermiso('auditoria.rechazar'),
                'auditoria_editar_checklist' => $usuario->tienePermiso('auditoria.editar_checklist_durante_auditoria'),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function formatearHabitacion(array $item): array
    {
        $estado = $item['estado'];
        // Para el trabajador: sucia → pendiente, rechazada → pendiente (la ve como "hay que hacerla")
        $estadoDisplay = match ($estado) {
            'sucia', 'rechazada' => 'pendiente',
            'en_progreso' => 'en_progreso',
            'completada_pendiente_auditoria' => 'completada',
            'aprobada', 'aprobada_con_observacion' => 'aprobada',
            default => $estado,
        };

        return [
            'id' => (int) $item['habitacion_id'],
            'numero' => $item['numero'],
            'tipo' => $item['tipo_nombre'],
            'estado' => $estadoDisplay,
            'hotel_codigo' => $item['hotel_codigo'],
        ];
    }

    private function nombreHotel(?string $codigo): string
    {
        return match ($codigo) {
            'inn' => 'Hotel Atankalama Inn',
            '1_sur' => 'Hotel Atankalama 1 Sur',
            default => 'Atankalama',
        };
    }

    private function minutosEntre(string $horaActual, string $horaObjetivo): int
    {
        $ahora = \DateTime::createFromFormat('H:i', substr($horaActual, 0, 5));
        $obj = \DateTime::createFromFormat('H:i', substr($horaObjetivo, 0, 5));
        if ($ahora === false || $obj === false) {
            return 0;
        }
        return (int) floor(($obj->getTimestamp() - $ahora->getTimestamp()) / 60);
    }

    /**
     * Límite de BD en MB. Usado para calcular porcentaje usado en la Home Admin.
     * Revisable desde .env con DB_LIMITE_MB.
     */
    private const DB_LIMITE_MB_DEFAULT = 512;

    /**
     * GET /api/home/admin
     * Dashboard ejecutivo del Administrador (item 47).
     * Spec: docs/home-admin.md
     */
    public function admin(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }

        $hotelQuery = $request->query['hotel'] ?? 'ambos';
        $hotel = is_string($hotelQuery) && $hotelQuery !== '' ? $hotelQuery : 'ambos';

        $hoy = date('Y-m-d');

        $puedeAlertas = $usuario->tienePermiso('alertas.recibir_predictivas');
        $puedeKpis = $usuario->tienePermiso('kpis.ver_operativas');
        $puedeSistema = $usuario->tienePermiso('sistema.ver_salud');
        $puedeAjustes = $usuario->tienePermiso('ajustes.acceder');

        // --- Hoteles disponibles (para claves ambos/por hotel) ---
        $hotelesFila = Database::fetchAll('SELECT id, codigo, nombre FROM hoteles ORDER BY codigo');
        $hotelesPorCodigo = [];
        foreach ($hotelesFila as $h) {
            $hotelesPorCodigo[(string) $h['codigo']] = [
                'id' => (int) $h['id'],
                'codigo' => (string) $h['codigo'],
                'nombre' => (string) $h['nombre'],
            ];
        }

        // --- Alertas (mezcla técnicas + operativas P0-P1) ---
        $alertas = [];
        $alertasTotal = 0;
        if ($puedeAlertas) {
            $sqlAlertas = 'SELECT a.id, a.tipo, a.prioridad, a.titulo, a.descripcion, a.contexto_json, a.hotel_id, a.created_at, ho.codigo AS hotel_codigo
                             FROM alertas_activas a
                        LEFT JOIN hoteles ho ON ho.id = a.hotel_id
                            WHERE a.prioridad <= 1';
            $params = [];
            if ($hotel !== 'ambos') {
                $sqlAlertas .= ' AND (ho.codigo = ? OR a.hotel_id IS NULL)';
                $params[] = $hotel;
            }
            $sqlAlertas .= ' ORDER BY a.prioridad ASC, a.created_at ASC';
            $filas = Database::fetchAll($sqlAlertas, $params);
            $alertasTotal = count($filas);
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
        }

        // --- Métricas operativas y KPIs por hotel ---
        $metricasPorHotel = [];
        $kpisPorHotel = [];
        foreach ($hotelesPorCodigo as $codigo => $info) {
            $metricasPorHotel[$codigo] = $this->metricasOperativasHotel((int) $info['id'], $hoy);
            $kpisPorHotel[$codigo] = $this->kpisHotel((int) $info['id'], $hoy, $metricasPorHotel[$codigo]);
        }
        $metricasConsolidado = $this->consolidarMetricas($metricasPorHotel);
        $kpisConsolidado = $this->consolidarKpis($kpisPorHotel, $metricasPorHotel);

        // --- Sistema (salud técnica) ---
        $sistema = null;
        if ($puedeSistema) {
            $sistema = [
                'cloudbeds' => $this->sistemaCloudbeds(),
                'errores_logs' => $this->sistemaErroresLogs($hoy),
                'base_datos' => $this->sistemaBaseDatos(),
                'usuarios_activos' => $this->sistemaUsuariosActivos($usuario->id),
                'version_app' => $this->sistemaVersionApp(),
            ];
        }

        // --- Indicador global de estado del sistema (header) ---
        $indicadorEstado = $this->calcularIndicadorGlobal($sistema);

        $primerNombre = explode(' ', $usuario->nombre)[0];

        return Response::ok([
            'usuario' => [
                'id' => $usuario->id,
                'nombre' => $usuario->nombre,
                'primer_nombre' => $primerNombre,
                'rut' => $usuario->rut,
            ],
            'hotel_seleccionado' => $hotel,
            'hoteles' => array_values($hotelesPorCodigo),
            'indicador_estado_sistema' => $indicadorEstado,
            'alertas' => $alertas,
            'alertas_total' => $alertasTotal,
            'metricas_operativas' => [
                'por_hotel' => $metricasPorHotel,
                'consolidado' => $metricasConsolidado,
            ],
            'kpis' => [
                'por_hotel' => $kpisPorHotel,
                'consolidado' => $kpisConsolidado,
            ],
            'sistema' => $sistema,
            'permisos' => [
                'alertas_recibir_predictivas' => $puedeAlertas,
                'kpis_ver_operativas' => $puedeKpis,
                'sistema_ver_salud' => $puedeSistema,
                'ajustes_acceder' => $puedeAjustes,
                'asignaciones_asignar_manual' => $usuario->tienePermiso('asignaciones.asignar_manual'),
            ],
            'timestamp_request' => date('c'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function metricasOperativasHotel(int $hotelId, string $hoy): array
    {
        // Habitaciones por estado (tomadas del estado actual de la habitación)
        $habs = Database::fetchAll(
            'SELECT estado, COUNT(*) AS c FROM habitaciones WHERE activa = 1 AND hotel_id = ? GROUP BY estado',
            [$hotelId]
        );
        $porEstado = ['sucia' => 0, 'en_progreso' => 0, 'completada_pendiente_auditoria' => 0,
                      'aprobada' => 0, 'aprobada_con_observacion' => 0, 'rechazada' => 0];
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

        // Habitaciones sucias sin asignación hoy (no asignadas)
        $noAsignadas = (int) (Database::fetchOne(
            "SELECT COUNT(*) AS c
               FROM habitaciones h
          LEFT JOIN asignaciones a ON a.habitacion_id = h.id AND a.fecha = ? AND a.activa = 1
              WHERE h.activa = 1 AND h.hotel_id = ? AND h.estado = 'sucia' AND a.id IS NULL",
            [$hoy, $hotelId]
        )['c'] ?? 0);

        // Auditorías del día
        $auditoriasFila = Database::fetchAll(
            "SELECT au.veredicto, COUNT(*) AS c
               FROM auditorias au
               JOIN habitaciones h ON h.id = au.habitacion_id
              WHERE h.hotel_id = ? AND DATE(au.created_at) = ?
           GROUP BY au.veredicto",
            [$hotelId, $hoy]
        );
        $audAprobadas = 0;
        $audObs = 0;
        $audRech = 0;
        foreach ($auditoriasFila as $r) {
            $v = (string) $r['veredicto'];
            $c = (int) $r['c'];
            if ($v === 'aprobado') $audAprobadas = $c;
            elseif ($v === 'aprobado_con_observacion') $audObs = $c;
            elseif ($v === 'rechazado') $audRech = $c;
        }

        // Trabajadores: en turno hoy, disponibles (con turno, sin cola pendiente), fuera de turno
        $enTurno = (int) (Database::fetchOne(
            "SELECT COUNT(DISTINCT u.id) AS c
               FROM usuarios u
               JOIN usuarios_turnos ut ON ut.usuario_id = u.id
              WHERE ut.fecha = ? AND u.activo = 1
                AND (u.hotel_default = (SELECT codigo FROM hoteles WHERE id = ?) OR u.hotel_default = 'ambos')",
            [$hoy, $hotelId]
        )['c'] ?? 0);

        $disponibles = (int) (Database::fetchOne(
            "SELECT COUNT(*) AS c FROM alertas_activas WHERE tipo = 'trabajador_disponible' AND (hotel_id = ? OR hotel_id IS NULL)",
            [$hotelId]
        )['c'] ?? 0);

        // Tickets abiertos
        $ticketsAbiertos = (int) (Database::fetchOne(
            "SELECT COUNT(*) AS c FROM tickets WHERE hotel_id = ? AND estado IN ('abierto','en_progreso')",
            [$hotelId]
        )['c'] ?? 0);

        // Tiempo promedio (min) de ejecuciones cerradas hoy en este hotel
        $tiempoProm = Database::fetchOne(
            "SELECT AVG((julianday(e.timestamp_fin) - julianday(e.timestamp_inicio)) * 1440) AS prom
               FROM ejecuciones_checklist e
               JOIN habitaciones h ON h.id = e.habitacion_id
              WHERE h.hotel_id = ?
                AND e.timestamp_fin IS NOT NULL
                AND DATE(e.timestamp_fin) = ?",
            [$hotelId, $hoy]
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
     * @param array<string, mixed> $metricas
     * @return array<string, mixed>
     */
    private function kpisHotel(int $hotelId, string $hoy, array $metricas): array
    {
        // KPI 1: tiempo promedio
        $metaTiempo = 30;
        $tiempoValor = $metricas['tiempo_promedio_minutos'];
        if ($tiempoValor === null) {
            $tiempoEstado = 'SIN_DATOS';
            $tiempoPct = 0;
        } else {
            if ($tiempoValor <= $metaTiempo) $tiempoEstado = 'OK';
            elseif ($tiempoValor <= $metaTiempo * 1.05) $tiempoEstado = 'ALERTA';
            else $tiempoEstado = 'CRITICO';
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
            "SELECT COUNT(*) AS c
               FROM asignaciones a
               JOIN habitaciones h ON h.id = a.habitacion_id
              WHERE h.hotel_id = ? AND a.fecha = ? AND a.activa = 1",
            [$hotelId, $hoy]
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
                'contexto' => $totalAud === 0 ? 'Sin auditorías hoy' : ($rech . ' rechazadas de ' . $totalAud . ' auditadas'),
            ],
            'eficiencia_equipo' => [
                'valor' => $eficiencia,
                'unidad' => '%',
                'meta' => $metaEf,
                'estado' => $efEstado,
                'contexto' => $asignadas === 0 ? 'Sin asignaciones hoy' : ($completadas . ' completadas de ' . $asignadas . ' asignadas'),
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $porHotel
     * @return array<string, mixed>
     */
    private function consolidarMetricas(array $porHotel): array
    {
        $habs = ['limpias' => 0, 'en_progreso' => 0, 'pendientes' => 0, 'por_auditar' => 0, 'no_asignadas' => 0, 'total' => 0];
        $auds = ['aprobadas' => 0, 'con_observacion' => 0, 'rechazadas' => 0, 'total' => 0];
        $trab = ['en_turno' => 0, 'disponibles' => 0];
        $tickets = 0;
        $tiempos = [];
        foreach ($porHotel as $m) {
            foreach ($habs as $k => $_) $habs[$k] += (int) $m['habitaciones'][$k];
            foreach ($auds as $k => $_) $auds[$k] += (int) $m['auditorias'][$k];
            foreach ($trab as $k => $_) $trab[$k] += (int) $m['trabajadores'][$k];
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
     * @param array<string, array<string, mixed>> $kpisPorHotel
     * @param array<string, array<string, mixed>> $metricasPorHotel
     * @return array<string, mixed>
     */
    private function consolidarKpis(array $kpisPorHotel, array $metricasPorHotel): array
    {
        $consolidado = $this->consolidarMetricas($metricasPorHotel);
        return $this->kpisConsolidadoDesdeMetricas($consolidado);
    }

    /**
     * @param array<string, mixed> $metricas
     * @return array<string, mixed>
     */
    private function kpisConsolidadoDesdeMetricas(array $metricas): array
    {
        $metaTiempo = 30;
        $tiempoValor = $metricas['tiempo_promedio_minutos'];
        if ($tiempoValor === null) {
            $tiempoEstado = 'SIN_DATOS'; $tiempoPct = 0;
        } else {
            if ($tiempoValor <= $metaTiempo) $tiempoEstado = 'OK';
            elseif ($tiempoValor <= $metaTiempo * 1.05) $tiempoEstado = 'ALERTA';
            else $tiempoEstado = 'CRITICO';
            $tiempoPct = $tiempoValor > 0 ? min(100, (int) round($metaTiempo * 100 / $tiempoValor)) : 0;
        }

        $totalAud = (int) $metricas['auditorias']['total'];
        $rech = (int) $metricas['auditorias']['rechazadas'];
        $tasa = $totalAud > 0 ? round($rech * 100 / $totalAud, 1) : 0.0;
        if ($totalAud === 0) $rechEstado = 'SIN_DATOS';
        elseif ($tasa <= 5.0) $rechEstado = 'OK';
        elseif ($tasa <= 7.0) $rechEstado = 'ALERTA';
        else $rechEstado = 'CRITICO';

        $completadas = (int) $metricas['habitaciones']['limpias'] + (int) $metricas['habitaciones']['por_auditar'];
        $enProgreso = (int) $metricas['habitaciones']['en_progreso'];
        $pendientes = (int) $metricas['habitaciones']['pendientes'];
        $asignadas = $completadas + $enProgreso + $pendientes;
        $eficiencia = $asignadas > 0 ? (int) round($completadas * 100 / $asignadas) : 0;
        if ($asignadas === 0) $efEstado = 'SIN_DATOS';
        elseif ($eficiencia >= 85) $efEstado = 'OK';
        elseif ($eficiencia >= 75) $efEstado = 'ALERTA';
        else $efEstado = 'CRITICO';

        return [
            'tiempo_promedio' => [
                'valor' => $tiempoValor, 'unidad' => 'min', 'meta' => $metaTiempo,
                'estado' => $tiempoEstado, 'porcentaje' => $tiempoPct,
            ],
            'tasa_rechazo' => [
                'valor' => $tasa, 'unidad' => '%', 'meta' => 5.0, 'estado' => $rechEstado,
                'contexto' => $totalAud === 0 ? 'Sin auditorías hoy' : ($rech . ' rechazadas de ' . $totalAud . ' auditadas'),
            ],
            'eficiencia_equipo' => [
                'valor' => $eficiencia, 'unidad' => '%', 'meta' => 85, 'estado' => $efEstado,
                'contexto' => $asignadas === 0 ? 'Sin asignaciones hoy' : ($completadas . ' completadas de ' . $asignadas . ' asignadas'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sistemaCloudbeds(): array
    {
        $fila = Database::fetchOne(
            "SELECT * FROM cloudbeds_sync_historial ORDER BY id DESC LIMIT 1"
        );
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
            'ultima_sync_relativa' => $minutos === null ? null : ($minutos <= 1 ? 'hace un momento' : 'hace ' . $minutos . ' min'),
            'resultado' => $resultado !== '' ? $resultado : null,
            'minutos_desde_ultima' => $minutos,
            'errores_count' => (int) ($fila['errores_count'] ?? 0),
            'error_mensaje' => $fila['error_mensaje'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sistemaErroresLogs(string $hoy): array
    {
        $fila = Database::fetchOne(
            "SELECT
                SUM(CASE WHEN nivel = 'ERROR' THEN 1 ELSE 0 END) AS errores,
                SUM(CASE WHEN nivel = 'WARNING' THEN 1 ELSE 0 END) AS warnings,
                MAX(created_at) AS ultimo
               FROM logs_eventos
              WHERE DATE(created_at) = ? AND nivel IN ('ERROR','WARNING')",
            [$hoy]
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
     * @return array<string, mixed>
     */
    private function sistemaBaseDatos(): array
    {
        $dbPath = (string) Config::get('DB_PATH', 'database/atankalama.db');
        if (!str_starts_with($dbPath, '/') && !preg_match('/^[A-Za-z]:/', $dbPath)) {
            $dbPath = Config::basePath() . DIRECTORY_SEPARATOR . $dbPath;
        }
        $tamanoBytes = @filesize($dbPath);
        $tamanoMb = ($tamanoBytes === false) ? null : (float) round($tamanoBytes / 1024 / 1024, 1);
        $limiteMb = (int) Config::getInt('DB_LIMITE_MB', self::DB_LIMITE_MB_DEFAULT);
        $pct = ($tamanoMb === null || $limiteMb <= 0) ? 0 : (int) round($tamanoMb * 100 / $limiteMb);
        if ($pct < 70) $estado = 'OK';
        elseif ($pct < 85) $estado = 'ALERTA';
        else $estado = 'CRITICO';
        return [
            'tamano_mb' => $tamanoMb,
            'limite_mb' => $limiteMb,
            'porcentaje_usado' => $pct,
            'estado' => $estado,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sistemaUsuariosActivos(int $adminId): array
    {
        $ahora = date('Y-m-d H:i:s');
        $sesiones = Database::fetchAll(
            "SELECT s.usuario_id, u.nombre, MAX(s.created_at) AS created_at
               FROM sesiones s
               JOIN usuarios u ON u.id = s.usuario_id
              WHERE s.expires_at > ? AND s.usuario_id <> ? AND u.activo = 1
           GROUP BY s.usuario_id, u.nombre
           ORDER BY created_at DESC",
            [$ahora, $adminId]
        );
        $lista = [];
        foreach ($sesiones as $s) {
            $roles = Database::fetchAll(
                "SELECT r.nombre FROM roles r JOIN usuarios_roles ur ON ur.rol_id = r.id WHERE ur.usuario_id = ?",
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
     * @return array<string, mixed>
     */
    private function sistemaVersionApp(): array
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

    /**
     * @param array<string, mixed>|null $sistema
     */
    private function calcularIndicadorGlobal(?array $sistema): string
    {
        if ($sistema === null) {
            return 'OK';
        }
        $estados = [
            $sistema['cloudbeds']['estado'] ?? 'OK',
            $sistema['base_datos']['estado'] ?? 'OK',
        ];
        $severidad = $sistema['errores_logs']['severidad'] ?? 'baja';
        if ($severidad === 'alta') {
            $estados[] = 'ERROR';
        } elseif ($severidad === 'media') {
            $estados[] = 'ALERTA';
        }
        if (in_array('ERROR', $estados, true) || in_array('CRITICO', $estados, true)) {
            return 'ERROR';
        }
        if (in_array('ALERTA', $estados, true)) {
            return 'ALERTA';
        }
        return 'OK';
    }
}
