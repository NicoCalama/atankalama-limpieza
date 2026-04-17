<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\AlertaActiva;
use Atankalama\Limpieza\Models\Ticket;

final class TicketService
{
    public function __construct(
        private readonly AlertasService $alertas = new AlertasService(),
    ) {
    }

    public function crear(
        int $hotelId,
        string $titulo,
        string $descripcion,
        string $prioridad,
        int $levantadoPor,
        ?int $habitacionId = null,
    ): Ticket {
        $titulo = trim($titulo);
        $descripcion = trim($descripcion);
        if ($titulo === '' || strlen($titulo) > 200) {
            throw new TicketException('TITULO_INVALIDO', 'Título debe tener entre 1 y 200 caracteres.', 400);
        }
        if ($descripcion === '') {
            throw new TicketException('DESCRIPCION_INVALIDA', 'Descripción es requerida.', 400);
        }
        if (!in_array($prioridad, Ticket::PRIORIDADES_VALIDAS, true)) {
            throw new TicketException('PRIORIDAD_INVALIDA', "Prioridad inválida: {$prioridad}.", 400);
        }
        $hotel = Database::fetchOne('SELECT id FROM hoteles WHERE id = ?', [$hotelId]);
        if ($hotel === null) {
            throw new TicketException('HOTEL_NO_ENCONTRADO', 'Hotel no encontrado.', 404);
        }
        if ($habitacionId !== null) {
            $hab = Database::fetchOne('SELECT id FROM habitaciones WHERE id = ? AND hotel_id = ?', [$habitacionId, $hotelId]);
            if ($hab === null) {
                throw new TicketException('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada en este hotel.', 404);
            }
        }

        Database::execute(
            'INSERT INTO tickets (habitacion_id, hotel_id, titulo, descripcion, prioridad, levantado_por) VALUES (?, ?, ?, ?, ?, ?)',
            [$habitacionId, $hotelId, $titulo, $descripcion, $prioridad, $levantadoPor]
        );
        $id = Database::lastInsertId();

        $usuarioFila = Database::fetchOne('SELECT nombre FROM usuarios WHERE id = ?', [$levantadoPor]);
        $habFila = $habitacionId !== null
            ? Database::fetchOne('SELECT numero FROM habitaciones WHERE id = ?', [$habitacionId])
            : null;
        $contextoHab = $habFila !== null ? "habitación {$habFila['numero']}" : 'el sistema';
        $this->alertas->levantar(
            AlertaActiva::TIPO_TICKET_NUEVO,
            "Ticket {$prioridad}: {$titulo}",
            ($usuarioFila['nombre'] ?? 'Un usuario') . " levantó un ticket en {$contextoHab}.",
            ['ticket_id' => $id, 'prioridad_ticket' => $prioridad, 'habitacion_id' => $habitacionId],
            $hotelId,
            "ticket:{$id}",
        );

        Logger::audit($levantadoPor, 'ticket.crear', 'ticket', $id, [
            'prioridad' => $prioridad, 'habitacion_id' => $habitacionId,
        ]);

        return $this->obtenerOFallar($id);
    }

    public function obtener(int $id): ?Ticket
    {
        $fila = Database::fetchOne('SELECT * FROM tickets WHERE id = ?', [$id]);
        return $fila === null ? null : Ticket::desdeFila($fila);
    }

    public function obtenerOFallar(int $id): Ticket
    {
        $t = $this->obtener($id);
        if ($t === null) {
            throw new TicketException('TICKET_NO_ENCONTRADO', 'Ticket no encontrado.', 404);
        }
        return $t;
    }

    /**
     * @param array{hotel?: ?string, estado?: ?string, levantado_por?: ?int} $filtros
     * @return list<array<string, mixed>>
     */
    public function listar(array $filtros = []): array
    {
        $sql = 'SELECT t.*, h.codigo AS hotel_codigo, hab.numero AS habitacion_numero, u.nombre AS levantado_por_nombre
                  FROM tickets t
                  JOIN hoteles h ON h.id = t.hotel_id
             LEFT JOIN habitaciones hab ON hab.id = t.habitacion_id
                  JOIN usuarios u ON u.id = t.levantado_por
                 WHERE 1=1';
        $params = [];
        $hotel = $filtros['hotel'] ?? null;
        if (is_string($hotel) && $hotel !== '' && $hotel !== 'ambos') {
            $sql .= ' AND h.codigo = ?';
            $params[] = $hotel;
        }
        $estado = $filtros['estado'] ?? null;
        if (is_string($estado) && $estado !== '') {
            $sql .= ' AND t.estado = ?';
            $params[] = $estado;
        }
        $usuarioId = $filtros['levantado_por'] ?? null;
        if (is_int($usuarioId)) {
            $sql .= ' AND t.levantado_por = ?';
            $params[] = $usuarioId;
        }
        $sql .= ' ORDER BY t.prioridad DESC, t.created_at DESC';
        return Database::fetchAll($sql, $params);
    }

    public function asignar(int $ticketId, int $usuarioId, int $asignadoPor): Ticket
    {
        $ticket = $this->obtenerOFallar($ticketId);
        if ($ticket->estado === Ticket::ESTADO_CERRADO) {
            throw new TicketException('TICKET_CERRADO', 'No se puede modificar un ticket cerrado.', 409);
        }
        $u = Database::fetchOne('SELECT id FROM usuarios WHERE id = ? AND activo = 1', [$usuarioId]);
        if ($u === null) {
            throw new TicketException('USUARIO_NO_ENCONTRADO', 'Usuario destino no encontrado o inactivo.', 404);
        }
        Database::execute(
            "UPDATE tickets SET asignado_a = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
            [$usuarioId, $ticketId]
        );
        Logger::audit($asignadoPor, 'ticket.asignar', 'ticket', $ticketId, ['asignado_a' => $usuarioId]);
        return $this->obtenerOFallar($ticketId);
    }

    public function cambiarEstado(int $ticketId, string $nuevoEstado, int $usuarioId): Ticket
    {
        if (!in_array($nuevoEstado, Ticket::ESTADOS_VALIDOS, true)) {
            throw new TicketException('ESTADO_INVALIDO', "Estado inválido: {$nuevoEstado}.", 400);
        }
        $ticket = $this->obtenerOFallar($ticketId);
        if ($ticket->estado === $nuevoEstado) {
            return $ticket;
        }
        if ($ticket->estado === Ticket::ESTADO_CERRADO) {
            throw new TicketException('TICKET_CERRADO', 'No se puede cambiar el estado de un ticket cerrado.', 409);
        }

        $resueltoAt = $ticket->resueltoAt;
        if ($nuevoEstado === Ticket::ESTADO_RESUELTO && $ticket->resueltoAt === null) {
            $resueltoAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        }

        Database::execute(
            "UPDATE tickets
                SET estado = ?,
                    resuelto_at = ?,
                    updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE id = ?",
            [$nuevoEstado, $resueltoAt, $ticketId]
        );

        Logger::audit($usuarioId, 'ticket.cambiar_estado', 'ticket', $ticketId, [
            'desde' => $ticket->estado, 'hasta' => $nuevoEstado,
        ]);

        if (in_array($nuevoEstado, [Ticket::ESTADO_RESUELTO, Ticket::ESTADO_CERRADO], true)) {
            $this->alertas->resolverPorDedupe(AlertaActiva::TIPO_TICKET_NUEVO, "ticket:{$ticketId}");
        }

        return $this->obtenerOFallar($ticketId);
    }
}
