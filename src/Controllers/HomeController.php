<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

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
}
