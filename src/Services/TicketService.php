<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\AlertaActiva;
use Atankalama\Limpieza\Models\Ticket;
use Atankalama\Limpieza\Models\TicketAdjunto;

final class TicketService
{
    public function __construct(
        private readonly AlertasService $alertas = new AlertasService(),
        private readonly NovedadesSyncService $novedadesSync = new NovedadesSyncService(),
    ) {
    }

    public function crear(
        int $hotelId,
        string $titulo,
        string $descripcion,
        string $prioridad,
        int $levantadoPor,
        ?int $habitacionId = null,
        ?string $idempotencyKey = null,
    ): Ticket {
        // Idempotencia (fase 1 del plan "eliminar No pudimos conectar con el servidor"):
        // el cliente genera un UUID por intento de envío y lo reusa en sus reintentos. Si
        // ya existe un ticket con esta key, la respuesta original se perdió por red pero
        // el ticket sí se creó — se devuelve ese, sin duplicar ni re-disparar alertas/audit.
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existente = Database::fetchOne('SELECT id FROM #__tickets WHERE idempotency_key = ?', [$idempotencyKey]);
            if ($existente !== null) {
                return $this->obtenerOFallar((int) $existente['id']);
            }
        }

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
        $hotel = Database::fetchOne('SELECT id FROM #__hoteles WHERE id = ?', [$hotelId]);
        if ($hotel === null) {
            throw new TicketException('HOTEL_NO_ENCONTRADO', 'Hotel no encontrado.', 404);
        }
        if ($habitacionId !== null) {
            $hab = Database::fetchOne('SELECT id FROM #__habitaciones WHERE id = ? AND hotel_id = ?', [$habitacionId, $hotelId]);
            if ($hab === null) {
                throw new TicketException('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada en este hotel.', 404);
            }
        }

        try {
            Database::execute(
                'INSERT INTO #__tickets (habitacion_id, hotel_id, titulo, descripcion, prioridad, levantado_por, idempotency_key) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$habitacionId, $hotelId, $titulo, $descripcion, $prioridad, $levantadoPor, $idempotencyKey]
            );
        } catch (\PDOException $e) {
            // Carrera: dos intentos con la misma idempotency_key llegaron casi a la vez y
            // ambos pasaron el chequeo de arriba antes de que el primero hiciera commit. El
            // índice UNIQUE (migrate-add-tickets-idempotency-key.php) frena al segundo acá
            // — se devuelve el ticket que sí quedó creado, en vez de duplicarlo o reventar.
            if ($idempotencyKey !== null && $idempotencyKey !== ''
                && (stripos($e->getMessage(), 'idempotency_key') !== false || stripos($e->getMessage(), 'Duplicate') !== false)) {
                $existente = Database::fetchOne('SELECT id FROM #__tickets WHERE idempotency_key = ?', [$idempotencyKey]);
                if ($existente !== null) {
                    return $this->obtenerOFallar((int) $existente['id']);
                }
            }
            throw $e;
        }
        $id = Database::lastInsertId();

        $usuarioFila = Database::fetchOne('SELECT nombre FROM #__usuarios WHERE id = ?', [$levantadoPor]);
        $habFila = $habitacionId !== null
            ? Database::fetchOne('SELECT numero FROM #__habitaciones WHERE id = ?', [$habitacionId])
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

        // La sincronización con Novedades se dispara desde el controller, DESPUÉS de
        // procesar las fotos adjuntas — acá el ticket recién creado todavía no las tiene.
        // Ver notificarCreacionANovedades().
        return $this->obtenerOFallar($id);
    }

    public function obtener(int $id): ?Ticket
    {
        $fila = Database::fetchOne('SELECT * FROM #__tickets WHERE id = ?', [$id]);
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
     * @param array{hotel?: ?string, estado?: ?string, levantado_por?: ?int, asignado_a?: ?int, sin_asignar?: bool} $filtros
     * @return list<array<string, mixed>>
     */
    public function listar(array $filtros = []): array
    {
        $sql = 'SELECT t.*, h.codigo AS hotel_codigo, hab.numero AS habitacion_numero,
                       u.nombre AS levantado_por_nombre, ua.nombre AS asignado_a_nombre
                  FROM #__tickets t
                  JOIN #__hoteles h ON h.id = t.hotel_id
             LEFT JOIN #__habitaciones hab ON hab.id = t.habitacion_id
                  JOIN #__usuarios u ON u.id = t.levantado_por
             LEFT JOIN #__usuarios ua ON ua.id = t.asignado_a
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
        $levantadoPor = $filtros['levantado_por'] ?? null;
        if (is_int($levantadoPor)) {
            $sql .= ' AND t.levantado_por = ?';
            $params[] = $levantadoPor;
        }
        // "Mis tickets" (permiso tickets.ver_propios, ver TicketsController::listar): son los
        // ASIGNADOS a mí, no los que yo creé — decisión explícita, ver conversación de soporte.
        $asignadoA = $filtros['asignado_a'] ?? null;
        if (is_int($asignadoA)) {
            $sql .= ' AND t.asignado_a = ?';
            $params[] = $asignadoA;
        }
        // Complemento de asignado_a: los que TODAVÍA no tiene nadie — "todos los tickets
        // pueden ser tomados por cualquier persona". Es el filtro "Sin asignar" en la UI,
        // hermano de "Asignado a mí" (que usa asignado_a arriba). Ver TicketsController::listar.
        if (($filtros['sin_asignar'] ?? false) === true) {
            $sql .= ' AND t.asignado_a IS NULL';
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
        $u = Database::fetchOne('SELECT id FROM #__usuarios WHERE id = ? AND activo = 1', [$usuarioId]);
        if ($u === null) {
            throw new TicketException('USUARIO_NO_ENCONTRADO', 'Usuario destino no encontrado o inactivo.', 404);
        }
        // asignado_at solo se resetea cuando el destinatario CAMBIA de verdad — reasignar al
        // mismo usuario (ej. re-click de "Tomar") no debe reiniciar el cronómetro que después
        // alimenta el reporte de tiempo de resolución (asignado_at → resuelto_at).
        if ($ticket->asignadoA === $usuarioId) {
            Database::execute(
                "UPDATE #__tickets SET updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                [$ticketId]
            );
        } else {
            Database::execute(
                "UPDATE #__tickets
                    SET asignado_a = ?, asignado_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now'),
                        updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
                  WHERE id = ?",
                [$usuarioId, $ticketId]
            );
        }
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
            "UPDATE #__tickets
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

    /**
     * Notifica la creación a Novedades (ver NovedadesSyncService::sincronizar). Llamar
     * DESPUÉS de procesar los adjuntos de creación, para que las fotos recién subidas
     * ya estén en adjuntosDe() y viajen en el mismo POST que crea la novedad.
     */
    public function notificarCreacionANovedades(int $ticketId): void
    {
        $ticket = $this->obtenerOFallar($ticketId);
        $this->novedadesSync->sincronizar($ticket, $this->adjuntosDe($ticketId));
    }

    /**
     * Notifica el cierre a Novedades como novedad de seguimiento (ver
     * NovedadesSyncService::sincronizarCierre) — no hace nada si el ticket no quedó
     * cerrado. Llamar DESPUÉS de procesar los adjuntos de cierre, para que las fotos
     * recién subidas ya estén en adjuntosDe().
     */
    public function notificarCierreANovedades(int $ticketId): void
    {
        $ticket = $this->obtenerOFallar($ticketId);
        if ($ticket->estado !== Ticket::ESTADO_CERRADO) {
            return;
        }
        $this->novedadesSync->sincronizarCierre($ticket, $this->adjuntosDe($ticketId));
    }

    /**
     * Registra una foto ya procesada (WebP, redimensionada) contra un ticket.
     * El archivo físico lo deja listo ImagenAdjuntoService — acá solo se persiste la fila.
     */
    public function agregarAdjunto(
        int $ticketId,
        string $ruta,
        ?string $nombreOriginal,
        int $tamanoBytes,
        int $subidoPor,
        string $contexto,
    ): TicketAdjunto {
        if (!in_array($contexto, TicketAdjunto::CONTEXTOS_VALIDOS, true)) {
            throw new TicketException('CONTEXTO_INVALIDO', "Contexto de adjunto inválido: {$contexto}.", 400);
        }
        $this->obtenerOFallar($ticketId);

        Database::execute(
            'INSERT INTO #__tickets_adjuntos (ticket_id, ruta, nombre_original, tamano_bytes, contexto, subido_por) VALUES (?, ?, ?, ?, ?, ?)',
            [$ticketId, $ruta, $nombreOriginal, $tamanoBytes, $contexto, $subidoPor]
        );
        $id = Database::lastInsertId();

        $fila = Database::fetchOne('SELECT * FROM #__tickets_adjuntos WHERE id = ?', [$id]);
        return TicketAdjunto::desdeFila($fila);
    }

    /**
     * Usuarios activos disponibles para "Asignar responsable" — endpoint acotado a
     * tickets.ver_todos (no usuarios.ver) para no darle a Supervisora/Recepción acceso al
     * módulo completo de Usuarios solo por poder asignar un ticket. Ver Kernel.php.
     *
     * @return list<array{id: int, nombre: string}>
     */
    public function usuariosActivos(): array
    {
        return Database::fetchAll('SELECT id, nombre FROM #__usuarios WHERE activo = 1 ORDER BY nombre');
    }

    /** @return list<array<string, mixed>> */
    public function adjuntosDe(int $ticketId): array
    {
        $filas = Database::fetchAll(
            'SELECT * FROM #__tickets_adjuntos WHERE ticket_id = ? ORDER BY created_at ASC',
            [$ticketId]
        );
        return array_map(fn (array $f): array => TicketAdjunto::desdeFila($f)->toArray(), $filas);
    }
}
