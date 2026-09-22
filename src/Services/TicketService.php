<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\AlertaActiva;
use Atankalama\Limpieza\Models\Ticket;
use Atankalama\Limpieza\Models\TicketAdjunto;
use Atankalama\Limpieza\Models\TicketComentario;

final class TicketService
{
    /** Tope de largo de un comentario — mismo criterio que otros textos libres del módulo. */
    private const COMENTARIO_MAX_LARGO = 2000;

    public function __construct(
        private readonly AlertasService $alertas = new AlertasService(),
        private readonly NovedadesSyncService $novedadesSync = new NovedadesSyncService(),
        private readonly PushService $push = new PushService(),
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
        int|array|null $asignadoA = null,
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

        // Validar responsables ANTES de insertar: si alguno no existe o está inactivo, asignar()
        // fallaría después de crear el ticket (con su alerta y su audit) y cada reintento del
        // usuario lo duplicaría.
        if ($asignadoA !== null) {
            $this->validarResponsables($asignadoA);
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

        // Asignación inmediata (Admin/Supervisor, gateado en el controller por
        // tickets.ver_todos — mismo permiso que ya rige asignar() post-creación).
        // Reusa asignar() tal cual: mismo audit log ('ticket.asignar'), mismas
        // validaciones (usuario activo). El ticket recién creado nunca está cerrado,
        // así que no pisa el chequeo TICKET_CERRADO de arriba.
        if ($asignadoA !== null) {
            $this->asignar($id, $asignadoA, $levantadoPor);
        }

        // La sincronización con Novedades se dispara desde el controller, DESPUÉS de
        // procesar las fotos adjuntas — acá el ticket recién creado todavía no las tiene.
        // Ver notificarCreacionANovedades().
        return $this->obtenerOFallar($id);
    }

    /**
     * Normaliza los ids de responsables (enteros > 0, sin repetir) y exige que todos existan y
     * estén activos. La usan asignar() y crear() — este último ANTES de insertar el ticket.
     *
     * @param int|list<int> $usuarios
     * @return list<int>
     */
    private function validarResponsables(int|array $usuarios): array
    {
        $uids = $this->normalizarIds($usuarios);

        if ($uids !== []) {
            $placeholders = implode(',', array_fill(0, count($uids), '?'));
            $encontrados = Database::fetchAll(
                "SELECT id FROM #__usuarios WHERE id IN ({$placeholders}) AND activo = 1",
                $uids
            );
            if (count($encontrados) !== count($uids)) {
                throw new TicketException('USUARIO_NO_ENCONTRADO', 'Uno o más usuarios destino no fueron encontrados o están inactivos.', 404);
            }
        }
        return $uids;
    }

    /**
     * @param int|list<int> $usuarios
     * @return list<int> enteros > 0, sin repetir, en el orden recibido
     */
    private function normalizarIds(int|array $usuarios): array
    {
        $uids = is_array($usuarios) ? $usuarios : [$usuarios];
        return array_values(array_unique(array_filter(array_map('intval', $uids), static fn(int $id) => $id > 0)));
    }

    /**
     * De estos ids, cuáles NO corresponden a un usuario activo (inactivos o inexistentes).
     *
     * @param list<int> $uids
     * @return list<int>
     */
    private function idsInactivos(array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $activos = array_map(
            static fn(array $r): int => (int) $r['id'],
            Database::fetchAll("SELECT id FROM #__usuarios WHERE id IN ({$placeholders}) AND activo = 1", $uids)
        );
        return array_values(array_diff($uids, $activos));
    }

    public function obtener(int $id): ?Ticket
    {
        $fila = Database::fetchOne(
            'SELECT t.*, ua.nombre AS asignado_a_nombre
               FROM #__tickets t
          LEFT JOIN #__usuarios ua ON ua.id = t.asignado_a
              WHERE t.id = ?',
            [$id]
        );
        if ($fila === null) {
            return null;
        }

        $responsables = [];
        try {
            $asignados = Database::fetchAll(
                'SELECT ta.usuario_id AS id, u.nombre
                   FROM #__tickets_asignados ta
                   JOIN #__usuarios u ON u.id = ta.usuario_id
                  WHERE ta.ticket_id = ?
               ORDER BY u.nombre ASC',
                [$id]
            );
            // Solo id y nombre: el ticket lo ve también quien lo levantó (p. ej. un trabajador),
            // y el email de los responsables no le hace falta a nadie en la UI.
            $responsables = array_map(static fn(array $a): array => [
                'id' => (int) $a['id'],
                'nombre' => (string) $a['nombre'],
            ], $asignados);
        } catch (\Throwable) {
            $responsables = [];
        }

        if (empty($responsables) && $fila['asignado_a'] !== null) {
            $responsables = [[
                'id' => (int) $fila['asignado_a'],
                'nombre' => (string) ($fila['asignado_a_nombre'] ?? 'Responsable'),
            ]];
        }

        return Ticket::desdeFila($fila, $responsables);
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
            if ($estado === Ticket::ESTADO_CERRADO) {
                // El chip "Cerrados" de la UI agrupa resuelto+cerrado
                $sql .= ' AND t.estado IN (?, ?)';
                $params[] = Ticket::ESTADO_RESUELTO;
                $params[] = Ticket::ESTADO_CERRADO;
            } else {
                $sql .= ' AND t.estado = ?';
                $params[] = $estado;
            }
        }
        $levantadoPor = $filtros['levantado_por'] ?? null;
        if (is_int($levantadoPor)) {
            $sql .= ' AND t.levantado_por = ?';
            $params[] = $levantadoPor;
        }
        // "Mis tickets": asignados a mí (soporta asignado_a y tickets_asignados)
        $asignadoA = $filtros['asignado_a'] ?? null;
        if (is_int($asignadoA)) {
            $sql .= ' AND (t.asignado_a = ? OR EXISTS (SELECT 1 FROM #__tickets_asignados ta WHERE ta.ticket_id = t.id AND ta.usuario_id = ?))';
            $params[] = $asignadoA;
            $params[] = $asignadoA;
        }
        // Complemento de asignado_a: los que todavía no tiene nadie
        if (($filtros['sin_asignar'] ?? false) === true) {
            $sql .= ' AND t.asignado_a IS NULL AND NOT EXISTS (SELECT 1 FROM #__tickets_asignados ta WHERE ta.ticket_id = t.id)';
        }
        // prioridad es VARCHAR ('baja'|'normal'|'alta'|'urgente'): un ORDER BY alfabético
        // dejaba 'alta' al final (después de 'baja'), no segunda tras 'urgente'. El CASE
        // fuerza el orden real de severidad.
        $sql .= " ORDER BY CASE t.prioridad
                      WHEN 'urgente' THEN 4
                      WHEN 'alta' THEN 3
                      WHEN 'normal' THEN 2
                      ELSE 1
                  END DESC, t.created_at DESC";
        $filas = Database::fetchAll($sql, $params);
        if ($filas === []) {
            return [];
        }

        // Cargar responsables múltiples en lote (batch) para evitar N+1
        $ticketIds = array_column($filas, 'id');
        $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
        $porTicket = [];
        try {
            $asignados = Database::fetchAll(
                "SELECT ta.ticket_id, ta.usuario_id, u.nombre
                   FROM #__tickets_asignados ta
                   JOIN #__usuarios u ON u.id = ta.usuario_id
                  WHERE ta.ticket_id IN ({$placeholders})
               ORDER BY u.nombre ASC",
                $ticketIds
            );
            foreach ($asignados as $a) {
                $porTicket[(int) $a['ticket_id']][] = [
                    'id' => (int) $a['usuario_id'],
                    'nombre' => (string) $a['nombre'],
                ];
            }
        } catch (\Throwable) {
            $porTicket = [];
        }

        foreach ($filas as &$f) {
            $tid = (int) $f['id'];
            $f['responsables'] = $porTicket[$tid] ?? (
                $f['asignado_a'] !== null ? [[
                    'id' => (int) $f['asignado_a'],
                    'nombre' => (string) ($f['asignado_a_nombre'] ?? 'Responsable'),
                ]] : []
            );
        }
        unset($f);

        return $filas;
    }

    /**
     * Asigna uno o múltiples responsables a un ticket.
     * Soporta: int $usuarioId | list<int> $usuarioIds.
     *
     * @param int $ticketId
     * @param int|list<int> $usuarios
     * @param int $asignadoPor
     */
    public function asignar(int $ticketId, int|array $usuarios, int $asignadoPor): Ticket
    {
        $ticket = $this->obtenerOFallar($ticketId);
        if ($ticket->estado === Ticket::ESTADO_CERRADO) {
            throw new TicketException('TICKET_CERRADO', 'No se puede modificar un ticket cerrado.', 409);
        }

        // Responsables actuales (tickets_asignados; asignado_a cubre tickets asignados antes de
        // la tabla). Sirven para validar solo a los que se agregan y para avisar solo a los nuevos.
        $actuales = Database::fetchAll(
            'SELECT usuario_id FROM #__tickets_asignados WHERE ticket_id = ?',
            [$ticketId]
        );
        $idsActuales = array_map(static fn(array $r): int => (int) $r['usuario_id'], $actuales);
        if ($ticket->asignadoA !== null && !in_array($ticket->asignadoA, $idsActuales, true)) {
            $idsActuales[] = $ticket->asignadoA;
        }

        // La lista que llega es la COMPLETA nueva. Los que se AGREGAN deben existir y estar
        // activos. Los que ya eran responsables y hoy están inactivos se sacan sin error: no
        // pueden trabajar el ticket, y la UI no los muestra, así que nadie podría desmarcarlos.
        $uids = $this->normalizarIds($usuarios);
        $this->validarResponsables(array_values(array_diff($uids, $idsActuales)));
        $inactivos = $this->idsInactivos(array_values(array_intersect($uids, $idsActuales)));
        $uids = array_values(array_diff($uids, $inactivos));

        // asignado_a guarda un responsable "principal" (compatibilidad: reportes y código que
        // leen una sola columna). Si el principal actual sigue en la lista se conserva, para que
        // agregar o quitar co-responsables no lo cambie en silencio ni reinicie asignado_at
        // (la lista llega ordenada por nombre desde la UI, así que "el primero" no es estable).
        $primerId = ($ticket->asignadoA !== null && in_array($ticket->asignadoA, $uids, true))
            ? $ticket->asignadoA
            : ($uids[0] ?? null);
        $cambioPrincipal = $primerId !== $ticket->asignadoA;

        // Reemplazo atómico: si algo falla a mitad, el ticket no queda sin responsables ni con
        // la lista a medias.
        Database::transaction(function () use ($ticketId, $uids, $asignadoPor, $primerId, $cambioPrincipal): void {
            Database::execute('DELETE FROM #__tickets_asignados WHERE ticket_id = ?', [$ticketId]);
            foreach ($uids as $uid) {
                Database::execute(
                    "INSERT INTO #__tickets_asignados (ticket_id, usuario_id, asignado_por, created_at)
                     VALUES (?, ?, ?, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))",
                    [$ticketId, $uid, $asignadoPor]
                );
            }
            if ($cambioPrincipal) {
                Database::execute(
                    "UPDATE #__tickets
                        SET asignado_a = ?,
                            asignado_at = " . ($primerId !== null ? "strftime('%Y-%m-%dT%H:%M:%fZ', 'now')" : "NULL") . ",
                            updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
                      WHERE id = ?",
                    [$primerId, $ticketId]
                );
            } else {
                Database::execute(
                    "UPDATE #__tickets
                        SET updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
                      WHERE id = ?",
                    [$ticketId]
                );
            }
        });

        Logger::audit($asignadoPor, 'ticket.asignar', 'ticket', $ticketId, ['asignados' => $uids]);

        // Notificar por Push / Campanita a los nuevos asignados (excepto autoasignación)
        $nuevosParaNotificar = array_diff($uids, $idsActuales, [$asignadoPor]);
        if ($nuevosParaNotificar !== []) {
            // La asignación ya quedó guardada: si el aviso falla, se registra y se sigue (un 500
            // acá haría reintentar al usuario, y en crear() eso duplicaba el ticket).
            try {
                $this->push->notificar(
                    array_values(array_map('intval', $nuevosParaNotificar)),
                    'Ticket asignado',
                    "Se te ha asignado el ticket: {$ticket->titulo}",
                    '/tickets?ticket=' . $ticketId,
                    [],
                    false,
                    'ticket_asignado'
                );
            } catch (\Throwable $e) {
                Logger::warning('tickets', 'No se pudo avisar la asignación del ticket', [
                    'ticket_id' => $ticketId, 'error' => $e->getMessage(),
                ]);
            }
        }

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

    public function cambiarPrioridad(int $ticketId, string $prioridad, int $editadoPor): Ticket
    {
        if (!in_array($prioridad, Ticket::PRIORIDADES_VALIDAS, true)) {
            throw new TicketException('PRIORIDAD_INVALIDA', "Prioridad inválida: {$prioridad}.", 400);
        }
        $ticket = $this->obtenerOFallar($ticketId);
        if ($ticket->estado === Ticket::ESTADO_CERRADO) {
            throw new TicketException('TICKET_CERRADO', 'No se puede modificar un ticket cerrado.', 409);
        }
        Database::execute(
            "UPDATE #__tickets SET prioridad = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
            [$prioridad, $ticketId]
        );
        Logger::audit($editadoPor, 'ticket.cambiar_prioridad', 'ticket', $ticketId, ['prioridad' => $prioridad]);
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
        $novedadId = $this->novedadesSync->sincronizar($ticket, $this->adjuntosDe($ticketId));

        // Se guarda para poder vincular, al cerrar el ticket, la novedad de cierre con
        // esta (ver NovedadesSyncService::armarPayloadCierre). Si la sincronización
        // falló, $novedadId llega null y no se toca la fila — sincronizarCierre()
        // simplemente no manda el vínculo, igual que antes de esta funcionalidad.
        if ($novedadId !== null) {
            Database::execute('UPDATE #__tickets SET novedad_id = ? WHERE id = ?', [$novedadId, $ticketId]);
        }
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
     * ¿El usuario destino tiene perfil Trabajador? Se compara por NOMBRE de rol y no por
     * permiso porque "perfil" acá es literalmente el rol del catálogo (roles.es_sistema = 1).
     * Un usuario con varios roles pasa si uno de ellos es Trabajador.
     */
    public function esTrabajador(int $usuarioId): bool
    {
        $fila = Database::fetchOne(
            'SELECT 1 AS ok
               FROM #__usuarios_roles ur
               JOIN #__roles r ON r.id = ur.rol_id
              WHERE ur.usuario_id = ? AND r.nombre = ?
              LIMIT 1',
            [$usuarioId, 'Trabajador']
        );
        return $fila !== null;
    }

    /** Orden de precedencia cuando un usuario tiene más de un rol: el primero que calce manda. */
    private const JERARQUIA_PERFILES = ['Admin', 'Supervisora', 'Recepción', 'Trabajador'];

    /**
     * Usuarios activos disponibles para "Asignar responsable" — endpoint acotado a
     * tickets.ver_todos (no usuarios.ver) para no darle a Supervisora/Recepción acceso al
     * módulo completo de Usuarios solo por poder asignar un ticket. Ver Kernel.php.
     *
     * $soloTrabajadores refleja la regla de TicketsController::asignar(): quien no tiene
     * tickets.asignar_a_cualquier_perfil no debe siquiera ver a los demás perfiles.
     *
     * @return list<array{id: int, nombre: string, perfil: string}>
     */
    public function usuariosActivos(bool $soloTrabajadores = false): array
    {
        // Left join a usuarios_roles/roles: un usuario con varios roles sale en varias filas,
        // se colapsa abajo eligiendo el perfil de mayor jerarquía (JERARQUIA_PERFILES).
        $filas = Database::fetchAll(
            'SELECT u.id, u.nombre, r.nombre AS rol
               FROM #__usuarios u
          LEFT JOIN #__usuarios_roles ur ON ur.usuario_id = u.id
          LEFT JOIN #__roles r ON r.id = ur.rol_id
              WHERE u.activo = 1'
        );

        $porUsuario = [];
        foreach ($filas as $fila) {
            $id = (int) $fila['id'];
            if (!isset($porUsuario[$id])) {
                $porUsuario[$id] = ['id' => $id, 'nombre' => (string) $fila['nombre'], 'roles' => []];
            }
            if ($fila['rol'] !== null) {
                $porUsuario[$id]['roles'][] = (string) $fila['rol'];
            }
        }

        $resultado = [];
        foreach ($porUsuario as $u) {
            if ($soloTrabajadores && !in_array('Trabajador', $u['roles'], true)) {
                continue;
            }
            $perfil = 'Sin perfil';
            foreach (self::JERARQUIA_PERFILES as $candidato) {
                if (in_array($candidato, $u['roles'], true)) {
                    $perfil = $candidato;
                    break;
                }
            }
            if ($perfil === 'Sin perfil' && $u['roles'] !== []) {
                // Rol propio creado en RBAC, fuera de la jerarquía fija de arriba.
                $perfil = $u['roles'][0];
            }
            $resultado[] = [
                'id' => $u['id'],
                'nombre' => $u['nombre'],
                'perfil' => $perfil,
                'roles' => $u['roles'],
            ];
        }
        return $resultado;
    }

    /**
     * Obtiene los IDs de usuarios activos asignados a un rol determinado por su nombre.
     *
     * @return list<int>
     */
    public function obtenerIdsPorRol(string $nombreRol): array
    {
        $filas = Database::fetchAll(
            'SELECT DISTINCT u.id
               FROM #__usuarios u
               JOIN #__usuarios_roles ur ON ur.usuario_id = u.id
               JOIN #__roles r ON r.id = ur.rol_id
              WHERE LOWER(r.nombre) = LOWER(?) AND u.activo = 1',
            [trim($nombreRol)]
        );
        return array_map(static fn(array $f): int => (int) $f['id'], $filas);
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

    /**
     * Agrega un comentario al historial del ticket (solo-append: sin edición ni borrado).
     * Si $avisarSupervisora, notifica a todo usuario activo con rol Supervisora o Admin —
     * mismo patrón de consulta por nombre de rol que esTrabajador(). El aviso va por
     * PushService::notificar() (mismo mecanismo que usa AuditoriaService para el rechazo):
     * persiste en la campanita a todos los destinatarios y además intenta push real a
     * quien tenga suscripción y no esté fuera de turno — se degrada en silencio si faltan
     * las claves VAPID o el destinatario no tiene suscripción.
     *
     * @return list<array<string, mixed>>
     */
    public function comentar(int $ticketId, int $usuarioId, string $comentario, bool $avisarSupervisora): array
    {
        $this->obtenerOFallar($ticketId); // valida que exista
        $comentario = trim($comentario);
        if ($comentario === '' || mb_strlen($comentario) > self::COMENTARIO_MAX_LARGO) {
            throw new TicketException(
                'COMENTARIO_INVALIDO',
                'El comentario no puede estar vacío ni superar ' . self::COMENTARIO_MAX_LARGO . ' caracteres.',
                400
            );
        }
        Database::execute(
            'INSERT INTO #__tickets_comentarios (ticket_id, usuario_id, comentario, avisado) VALUES (?, ?, ?, ?)',
            [$ticketId, $usuarioId, $comentario, $avisarSupervisora ? 1 : 0]
        );
        Logger::audit($usuarioId, 'ticket.comentar', 'ticket', $ticketId, ['avisado' => $avisarSupervisora]);

        if ($avisarSupervisora) {
            $destinatarioIds = array_column(
                Database::fetchAll(
                    "SELECT DISTINCT u.id FROM #__usuarios u
                       JOIN #__usuarios_roles ur ON ur.usuario_id = u.id
                       JOIN #__roles r ON r.id = ur.rol_id
                      WHERE r.nombre IN ('Supervisora', 'Admin') AND u.activo = 1"
                ),
                'id'
            );
            if ($destinatarioIds !== []) {
                // $url va app-relative: PushService::notificar() antepone BASE_PATH una
                // sola vez (Url::a interno) — anteponerlo acá también lo duplicaría.
                $this->push->notificar(
                    array_map('intval', $destinatarioIds),
                    'Nuevo comentario en un ticket',
                    mb_substr($comentario, 0, 140),
                    '/tickets?ticket=' . $ticketId,
                    [],
                    false,
                    'ticket_comentario'
                );
            }
        }
        return $this->comentariosDe($ticketId);
    }

    /** @return list<array<string, mixed>> */
    public function comentariosDe(int $ticketId): array
    {
        $filas = Database::fetchAll(
            'SELECT c.*, u.nombre AS usuario_nombre
               FROM #__tickets_comentarios c
               JOIN #__usuarios u ON u.id = c.usuario_id
              WHERE c.ticket_id = ?
              ORDER BY c.created_at ASC',
            [$ticketId]
        );
        return array_map(fn (array $f): array => TicketComentario::desdeFila($f)->toArray(), $filas);
    }
}
