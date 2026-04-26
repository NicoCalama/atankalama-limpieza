<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\HomeService;

final class HomeController
{
    public function __construct(
        private readonly HomeService $home = new HomeService(),
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
        $cola = $this->home->colaTrabajador($usuario->id, $hoy);

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

        $avisoEnviado = $this->home->avisoDisponibilidadEnviado($usuario->id, $hoy);

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

        $registrado = $this->home->registrarAvisoDisponibilidad($usuario->id, $hoy);
        if (!$registrado) {
            return Response::error('YA_AVISADO', 'Ya enviaste un aviso de disponibilidad hoy.', 409);
        }

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

        $trabajadores = $this->home->trabajadoresEnTurno($hoy);

        $equipo = [];
        $globalCompletadas = 0;
        $globalEnProgreso = 0;
        $globalPendientes = 0;
        $globalRechazadas = 0;
        $globalTotal = 0;

        foreach ($trabajadores as $t) {
            $usuarioId = (int) $t['id'];
            $cola = $this->home->colaTrabajador($usuarioId, $hoy);

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
            if ($this->home->trabajadorEnRiesgo($usuarioId, $hoy)) {
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

        // Hoteles disponibles (para claves ambos / por hotel)
        $hotelesPorCodigo = $this->home->hotelesPorCodigo();

        // Alertas (mezcla técnicas + operativas P0-P1)
        $alertas = [];
        $alertasTotal = 0;
        if ($puedeAlertas) {
            [$alertas, $alertasTotal] = $this->home->alertasOperativasAdmin($hotel);
        }

        // Métricas operativas y KPIs por hotel
        $metricasPorHotel = [];
        $kpisPorHotel = [];
        foreach ($hotelesPorCodigo as $codigo => $info) {
            $metricasPorHotel[$codigo] = $this->home->metricasOperativasHotel((int) $info['id'], $hoy);
            $kpisPorHotel[$codigo] = $this->home->kpisHotel((int) $info['id'], $hoy, $metricasPorHotel[$codigo]);
        }
        $metricasConsolidado = $this->home->consolidarMetricas($metricasPorHotel);
        $kpisConsolidado = $this->home->consolidarKpis($kpisPorHotel, $metricasPorHotel);

        // Sistema (salud técnica)
        $sistema = null;
        if ($puedeSistema) {
            $sistema = [
                'cloudbeds' => $this->home->sistemaCloudbeds(),
                'errores_logs' => $this->home->sistemaErroresLogs($hoy),
                'base_datos' => $this->home->sistemaBaseDatos(),
                'usuarios_activos' => $this->home->sistemaUsuariosActivos($usuario->id),
                'version_app' => $this->home->sistemaVersionApp(),
            ];
        }

        // Indicador global de estado del sistema (header)
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

    // -----------------------------------------------------------------------
    // Helpers de presentación (sin BD)
    // -----------------------------------------------------------------------

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
            'inn' => 'Atankalama INN',
            '1_sur' => 'Atankalama',
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
