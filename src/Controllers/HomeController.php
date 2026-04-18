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
}
