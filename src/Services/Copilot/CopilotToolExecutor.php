<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services\Copilot;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\Ticket;
use Atankalama\Limpieza\Models\Usuario;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AlertasService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\HabitacionService;
use Atankalama\Limpieza\Services\TicketService;
use Atankalama\Limpieza\Services\TurnoService;

/**
 * Ejecuta una tool del copilot delegando a los services existentes.
 * Re-valida permisos antes de ejecutar (defensa en profundidad).
 */
final class CopilotToolExecutor
{
    public function __construct(
        private readonly HabitacionService $habitaciones = new HabitacionService(),
        private readonly AlertasService $alertas = new AlertasService(),
        private readonly TicketService $tickets = new TicketService(),
        private readonly AsignacionService $asignaciones = new AsignacionService(),
        private readonly ChecklistService $checklists = new ChecklistService(),
        private readonly TurnoService $turnos = new TurnoService(),
    ) {
    }

    /**
     * @param array<string,mixed> $input Payload de la tool.
     * @return array{ok:bool, resultado:mixed, error:?string}
     */
    public function ejecutar(string $toolName, array $input, Usuario $usuario): array
    {
        // Re-validar permisos (defensa en profundidad)
        $toolDef = CopilotToolRegistry::buscarPorNombre($toolName);
        if ($toolDef === null) {
            return ['ok' => false, 'resultado' => null, 'error' => "Tool desconocida: {$toolName}"];
        }
        foreach ($toolDef['permisos'] as $p) {
            if (!$usuario->tienePermiso($p)) {
                return ['ok' => false, 'resultado' => null, 'error' => 'No tienes permiso para esta acción.'];
            }
        }
        if ($toolDef['nivel2'] && !$usuario->tienePermiso('copilot.usar_nivel_2_acciones')) {
            return ['ok' => false, 'resultado' => null, 'error' => 'No tienes permiso para ejecutar acciones.'];
        }

        try {
            $resultado = match ($toolName) {
                'listar_mis_habitaciones' => $this->listarMisHabitaciones($usuario),
                'listar_habitaciones_hotel' => $this->listarHabitacionesHotel($input),
                'listar_alertas_activas' => $this->listarAlertas($input),
                'listar_tickets' => $this->listarTickets($input, $usuario),
                'ver_estado_equipo' => $this->verEstadoEquipo($input),
                'asignar_habitacion' => $this->asignarHabitacion($input, $usuario),
                'crear_ticket' => $this->crearTicket($input, $usuario),
                'completar_habitacion' => $this->completarHabitacion($input, $usuario),
                default => throw new \RuntimeException("Tool no implementada: {$toolName}"),
            };

            // Audit log para acciones nivel 2
            if ($toolDef['nivel2']) {
                Logger::audit($usuario->id, "copilot.{$toolName}", null, null, $input, 'copilot');
            }

            return ['ok' => true, 'resultado' => $resultado, 'error' => null];
        } catch (\Throwable $e) {
            Logger::warning('copilot', "Error ejecutando tool {$toolName}: {$e->getMessage()}", [
                'usuario_id' => $usuario->id,
            ]);
            return ['ok' => false, 'resultado' => null, 'error' => $e->getMessage()];
        }
    }

    private function listarMisHabitaciones(Usuario $usuario): array
    {
        $asignaciones = Database::fetchAll(
            "SELECT a.id, a.habitacion_id, h.numero, h.estado, ho.nombre AS hotel
               FROM asignaciones a
               JOIN habitaciones h ON h.id = a.habitacion_id
               JOIN hoteles ho ON ho.id = h.hotel_id
              WHERE a.usuario_id = ? AND a.fecha = date('now') AND a.completada = 0
              ORDER BY a.orden",
            [$usuario->id]
        );
        return ['habitaciones' => $asignaciones, 'total' => count($asignaciones)];
    }

    /** @param array<string,mixed> $input */
    private function listarHabitacionesHotel(array $input): array
    {
        $hotel = (string) ($input['hotel'] ?? 'ambos');
        $filtros = [];
        if ($hotel !== 'ambos') {
            $filtros['hotel'] = $hotel;
        }
        $habitaciones = $this->habitaciones->listar($filtros);
        return ['habitaciones' => $habitaciones, 'total' => count($habitaciones)];
    }

    /** @param array<string,mixed> $input */
    private function listarAlertas(array $input): array
    {
        $hotel = isset($input['hotel']) ? (string) $input['hotel'] : null;
        $alertas = $this->alertas->listarActivas($hotel);
        return ['alertas' => $alertas, 'total' => count($alertas)];
    }

    /** @param array<string,mixed> $input */
    private function listarTickets(array $input, Usuario $usuario): array
    {
        $filtros = [];
        if (isset($input['estado'])) {
            $filtros['estado'] = (string) $input['estado'];
        }
        if (!$usuario->tienePermiso('tickets.ver_todos')) {
            $filtros['levantado_por'] = $usuario->id;
        }
        $tickets = $this->tickets->listar($filtros);
        return ['tickets' => $tickets, 'total' => count($tickets)];
    }

    /** @param array<string,mixed> $input */
    private function verEstadoEquipo(array $input): array
    {
        $fecha = isset($input['fecha']) && is_string($input['fecha']) ? $input['fecha'] : date('Y-m-d');
        $turnos = $this->turnos->turnosDelDia($fecha);

        $completadas = (int) Database::fetchOne(
            "SELECT COUNT(*) AS n FROM asignaciones WHERE fecha = ? AND completada = 1",
            [$fecha]
        )['n'];
        $pendientes = (int) Database::fetchOne(
            "SELECT COUNT(*) AS n FROM asignaciones WHERE fecha = ? AND completada = 0",
            [$fecha]
        )['n'];

        return [
            'fecha' => $fecha,
            'trabajadores_en_turno' => count($turnos),
            'turnos' => $turnos,
            'habitaciones_completadas' => $completadas,
            'habitaciones_pendientes' => $pendientes,
        ];
    }

    /** @param array<string,mixed> $input */
    private function asignarHabitacion(array $input, Usuario $usuario): array
    {
        $habitacionId = (int) ($input['habitacion_id'] ?? 0);
        $usuarioId = (int) ($input['usuario_id'] ?? 0);
        $id = $this->asignaciones->asignarManual($habitacionId, $usuarioId, $usuario->id);
        return ['asignacion_id' => $id, 'mensaje' => 'Habitación asignada correctamente.'];
    }

    /** @param array<string,mixed> $input */
    private function crearTicket(array $input, Usuario $usuario): array
    {
        $ticket = $this->tickets->crear(
            (int) ($input['hotel_id'] ?? 0),
            (string) ($input['titulo'] ?? ''),
            (string) ($input['descripcion'] ?? ''),
            (string) ($input['prioridad'] ?? Ticket::PRIORIDAD_NORMAL),
            $usuario->id,
            isset($input['habitacion_id']) ? (int) $input['habitacion_id'] : null,
        );
        return ['ticket' => $ticket->toArray(), 'mensaje' => 'Ticket creado correctamente.'];
    }

    /** @param array<string,mixed> $input */
    private function completarHabitacion(array $input, Usuario $usuario): array
    {
        $habitacionId = (int) ($input['habitacion_id'] ?? 0);
        $this->checklists->completar($habitacionId, $usuario->id);
        return ['mensaje' => 'Habitación marcada como completada.'];
    }
}
