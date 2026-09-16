<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\Habitacion;

final class HabitacionService
{
    /** nota_recepcion es TEXT, pero un texto sin límite en la UI es poco práctico para leer rápido. */
    private const NOTA_MAX = 500;

    /** Tope del historial de movimientos exportado a Excel — anti-catástrofe, no un límite de negocio real. */
    private const MOVIMIENTOS_EXPORT_MAX = 5000;

    public function __construct(
        private readonly EstadoHabitacionService $estados = new EstadoHabitacionService(),
        private readonly SabanasService $sabanas = new SabanasService(),
        private readonly AsignacionService $asignaciones = new AsignacionService(),
        private readonly PushService $push = new PushService(),
    ) {
    }

    /**
     * Lista habitaciones con filtros opcionales.
     *
     * @param 'ambos'|'1_sur'|'inn'|null $hotel    código del hotel ('ambos' = sin filtro)
     * @param string|null                $estado  estado específico
     * @return array<int, array<string, mixed>>   filas con hotel_codigo y tipo_nombre incluidos
     */
    public function listar(?string $hotel = 'ambos', ?string $estado = null): array
    {
        // es_espacio_comun = 0: el listado de habitaciones es solo piezas de huésped; las áreas
        // comunes tienen su propia pantalla (EspacioService). Ver docs/areas-comunes.md
        $where = ['h.activa = 1', 'h.es_espacio_comun = 0'];
        // Asignación activa de HOY, para mostrar a quién le corresponde cada pieza (mismo
        // patrón que AsignacionService::vistaConsolidada). Puede haber varias asignaciones
        // activas de una habitación en fechas distintas (planificación a futuro), pero como
        // este JOIN fija fecha = hoy, sigue habiendo como máximo una por habitación y el
        // LEFT JOIN no duplica filas.
        $params = [date('Y-m-d')];

        if ($hotel !== null && $hotel !== 'ambos') {
            $where[] = 'ho.codigo = ?';
            $params[] = $hotel;
        }

        if ($estado !== null) {
            if (!in_array($estado, Habitacion::ESTADOS_VALIDOS, true)) {
                throw new HabitacionException('ESTADO_INVALIDO', "Estado inválido: {$estado}.", 400);
            }
            $where[] = 'h.estado = ?';
            $params[] = $estado;
        }

        $sql = 'SELECT h.*, ho.codigo AS hotel_codigo, ho.nombre AS hotel_nombre, th.nombre AS tipo_nombre,
                       ho.sabanas_cada_n_dias, ua.nombre AS asignado_a_nombre
                  FROM #__habitaciones h
                  JOIN #__hoteles ho ON ho.id = h.hotel_id
                  JOIN #__tipos_habitacion th ON th.id = h.tipo_habitacion_id
             LEFT JOIN #__asignaciones asig ON asig.habitacion_id = h.id AND asig.fecha = ? AND asig.activa = 1
             LEFT JOIN #__usuarios ua ON ua.id = asig.usuario_id
                 WHERE ' . implode(' AND ', $where) . '
              ORDER BY ho.codigo, h.numero';

        $filas = Database::fetchAll($sql, $params);
        return array_map(function (array $f) {
            // Cast explícito: a diferencia de Edificio::desdeFila() (que sí castea su id),
            // esta fila viaja cruda desde PDO. edificios.php compara edificio_id/piso con
            // === contra el id tipado de /api/edificios — sin este cast, un driver que
            // devuelva estas columnas como string rompe esa comparación en silencio.
            $f['id'] = (int) $f['id'];
            $f['hotel_id'] = (int) $f['hotel_id'];
            $f['edificio_id'] = $f['edificio_id'] !== null ? (int) $f['edificio_id'] : null;
            $f['piso'] = $f['piso'] !== null ? (int) $f['piso'] : null;
            // Mismo problema que arriba pero con booleanos: MariaDB devuelve TINYINT como
            // string ("0"/"1"), y "0" es truthy en JS — el botón "N" del listado se pintaría
            // activo en TODAS las habitaciones sin este cast. Ver docs/nocheros.md
            $f['es_nochero'] = ((int) ($f['es_nochero'] ?? 0)) === 1;
            return $this->sabanas->anotarFila($f);
        }, $filas);
    }

    public function obtener(int $id): ?Habitacion
    {
        $fila = Database::fetchOne('SELECT * FROM #__habitaciones WHERE id = ?', [$id]);
        return $fila === null ? null : Habitacion::desdeFila($fila);
    }

    /**
     * Detalle enriquecido (hotel, tipo). null si no existe.
     *
     * @return array<string, mixed>|null
     */
    public function obtenerDetalle(int $id): ?array
    {
        $fila = Database::fetchOne(
            'SELECT h.*, ho.codigo AS hotel_codigo, ho.nombre AS hotel_nombre, th.nombre AS tipo_nombre
               FROM #__habitaciones h
               JOIN #__hoteles ho ON ho.id = h.hotel_id
               JOIN #__tipos_habitacion th ON th.id = h.tipo_habitacion_id
              WHERE h.id = ?',
            [$id]
        );
        if ($fila === null) {
            return null;
        }
        // Mismo cast que listar()/es_nochero: MariaDB devuelve TINYINT como string ("0"/"1"),
        // truthy en JS. null se preserva (pieza nunca sincronizada con Cloudbeds).
        $fila['cb_ocupada'] = isset($fila['cb_ocupada'])
            ? ((int) $fila['cb_ocupada']) === 1
            : null;
        return $fila;
    }

    /**
     * Guarda la ocupación sincronizada desde Cloudbeds (getHousekeepingStatus + getReservationAssignments).
     * Es contexto para priorizar y para la regla de sábanas; NO cambia el 'estado' de limpieza. Ver docs/ocupacion-y-sabanas.md
     *
     * @param string|null $frontdeskStatus check-in|check-out|stayover|turnover|unused (o null si desconocido)
     * @param string|null $huesped         guestName de getReservationAssignments (texto libre, puede incluir
     *                                     empresa); null si no hay reserva activa asignada a la pieza.
     */
    public function actualizarOcupacionCloudbeds(
        int $id,
        ?string $frontdeskStatus,
        ?bool $ocupada,
        ?string $arrivalDate,
        ?string $departureDate,
        ?string $huesped = null,
        ?string $cloudbedsRoomName = null,
    ): void {
        Database::execute(
            "UPDATE #__habitaciones
                SET cb_frontdesk_status = ?, cb_ocupada = ?, cb_arrival_date = ?, cb_departure_date = ?,
                    cb_huesped = ?, cloudbeds_room_name = ?, cb_ocupacion_sync_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE id = ?",
            [
                $frontdeskStatus,
                $ocupada === null ? null : ($ocupada ? 1 : 0),
                $arrivalDate,
                $departureDate,
                $huesped,
                $cloudbedsRoomName,
                $id,
            ]
        );
    }

    public function actualizarEstructura(int $id, ?int $edificioId, ?string $edificio, ?int $piso, ?int $usuarioId = null): void
    {
        $habitacion = $this->obtener($id);
        if ($habitacion === null) {
            throw new HabitacionException('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada.', 404);
        }

        Database::execute(
            "UPDATE #__habitaciones
                SET edificio_id = ?, edificio = ?, piso = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE id = ?",
            [
                $edificioId,
                $edificio,
                $piso,
                $id,
            ]
        );

        Logger::info('habitaciones', 'estructura_actualizada', [
            'habitacion_id' => $id,
            'edificio_id' => $edificioId,
            'edificio' => $edificio,
            'piso' => $piso,
        ], $usuarioId);
    }

    /**
     * Marca (o actualiza la vigencia de) una habitación como "nochero": pieza con huésped de
     * turno día Y turno noche (hotel minero), que necesita aseo dos veces al día. Mientras esté
     * vigente, el cron de las 16:00 la revierte a 'sucia' si ya quedó en un estado terminal
     * (ver listarNocherosVigentesEnEstadoTerminal). Ver docs/nocheros.md.
     *
     * @param string $hasta último día vigente ('YYYY-MM-DD'), desde hoy en adelante (sin
     *                       vigencia mínima: puede ser 1 día, 4, 7, 15... según lo pida el huésped).
     */
    public function marcarNochero(int $id, string $hasta, ?int $usuarioId = null): Habitacion
    {
        $habitacion = $this->obtener($id);
        if ($habitacion === null) {
            throw new HabitacionException('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada.', 404);
        }

        $fechaHasta = \DateTime::createFromFormat('Y-m-d', $hasta);
        if ($fechaHasta === false || $fechaHasta->format('Y-m-d') !== $hasta) {
            throw new HabitacionException('FECHA_INVALIDA', 'Fecha inválida, usa el formato YYYY-MM-DD.', 400);
        }
        $hoy = date('Y-m-d');
        if ($hasta < $hoy) {
            throw new HabitacionException(
                'NOCHERO_FECHA_PASADA',
                'La fecha de vigencia no puede ser anterior a hoy.',
                400
            );
        }

        Database::execute(
            "UPDATE #__habitaciones SET es_nochero = 1, nochero_hasta = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
            [$hasta, $id]
        );

        Logger::info('habitaciones', 'nochero_marcado', ['habitacion_id' => $id, 'hasta' => $hasta], $usuarioId);
        Logger::audit($usuarioId, 'habitacion.marcar_nochero', 'habitacion', $id, ['hasta' => $hasta], 'ui');

        return $this->obtener($id);
    }

    /** Quita la marca de nochero de una habitación. No requiere motivo (decisión de UX: un solo paso). */
    public function desmarcarNochero(int $id, ?int $usuarioId = null): Habitacion
    {
        $habitacion = $this->obtener($id);
        if ($habitacion === null) {
            throw new HabitacionException('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada.', 404);
        }

        Database::execute(
            "UPDATE #__habitaciones SET es_nochero = 0, nochero_hasta = NULL, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
            [$id]
        );

        Logger::audit($usuarioId, 'habitacion.desmarcar_nochero', 'habitacion', $id, [], 'ui');

        return $this->obtener($id);
    }

    /**
     * Deja (o reemplaza) la nota de Recepción para la mucama: instrucción puntual para la
     * próxima limpieza de esta habitación (ej. "cliente pidió cama extra"). Una nota activa
     * por habitación, no historial — se autolimpia al completar la ejecución
     * (ver ChecklistService::completar()). Avisa al trabajador con la asignación activa de
     * hoy, si ya tiene una (mismo patrón que AuditoriaService::crearAlertaRechazo).
     */
    public function agregarNota(int $id, string $nota, int $actorId): Habitacion
    {
        $habitacion = $this->obtener($id);
        if ($habitacion === null) {
            throw new HabitacionException('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada.', 404);
        }

        $nota = trim($nota);
        if ($nota === '') {
            throw new HabitacionException('NOTA_VACIA', 'La nota no puede estar vacía.', 400);
        }
        if (mb_strlen($nota) > self::NOTA_MAX) {
            throw new HabitacionException(
                'NOTA_MUY_LARGA',
                'La nota no puede superar los ' . self::NOTA_MAX . ' caracteres.',
                400
            );
        }

        Database::execute(
            "UPDATE #__habitaciones
                SET nota_recepcion = ?, nota_recepcion_autor_id = ?,
                    nota_recepcion_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now'),
                    updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE id = ?",
            [$nota, $actorId, $id]
        );

        // El texto viaja en el audit_log (no solo el largo) para que el historial de
        // movimientos de la ficha pueda mostrar el mensaje después de que la nota se borre
        // sola al completar la limpieza. Ver obtenerMovimientos().
        Logger::audit($actorId, 'habitacion.agregar_nota', 'habitacion', $id, ['nota' => $nota], 'ui');

        // Si nadie la tiene asignada todavía, la nota queda igual guardada y la verá quien la
        // abra (o al asignarse) sin notificación push — no es un error, solo no hay a quién avisar aún.
        $asignacion = $this->asignaciones->obtenerActivaDeHabitacion($id);
        if ($asignacion !== null) {
            // PushService::notificar() persiste en la campanita Y manda push real al
            // dispositivo (si está suscrito y en turno) — antes solo quedaba en la campanita.
            $this->push->notificar(
                [$asignacion->usuarioId],
                "Mensaje de Recepción — Hab. {$habitacion->numero}",
                $nota,
                "/habitaciones/{$id}",
                [],
                true,
                'nota_recepcion',
            );
        }

        return $this->obtener($id);
    }

    /** Quita la nota de Recepción sin esperar a que se complete la limpieza (ej. se dejó por error). */
    public function quitarNota(int $id, ?int $actorId = null): Habitacion
    {
        $habitacion = $this->obtener($id);
        if ($habitacion === null) {
            throw new HabitacionException('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada.', 404);
        }

        Database::execute(
            "UPDATE #__habitaciones
                SET nota_recepcion = NULL, nota_recepcion_autor_id = NULL, nota_recepcion_at = NULL,
                    updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE id = ?",
            [$id]
        );

        Logger::audit($actorId, 'habitacion.quitar_nota', 'habitacion', $id, [], 'ui');

        return $this->obtener($id);
    }

    /**
     * Apaga es_nochero de las habitaciones cuya vigencia ya venció (nochero_hasta < $hoy). No
     * toca 'estado' — solo limpia la marca para que el botón no siga viéndose activado solo.
     * Llamado desde el cron de las 16:00 (ver scripts/sync-cloudbeds.php). Ver docs/nocheros.md
     *
     * @return int cantidad de habitaciones desactivadas
     */
    public function desactivarNocherosVencidos(string $hoy): int
    {
        return Database::execute(
            "UPDATE #__habitaciones SET es_nochero = 0, nochero_hasta = NULL,
                    updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE es_nochero = 1 AND nochero_hasta < ?",
            [$hoy]
        );
    }

    /**
     * Habitaciones "nochero" vigentes hoy que ya quedaron en un estado terminal (aprobada,
     * aprobada_con_observacion, aprobada_automatica, rechazada) — candidatas a revertir a
     * 'sucia' desde las 16:00. No
     * incluye 'en_progreso' ni 'completada_pendiente_auditoria': no se interrumpe un aseo en
     * curso ni se salta una auditoría pendiente por el cron. Ver Habitacion::estaEnEstadoTerminal().
     *
     * Excluye las que YA se revirtieron hoy (nochero_ultima_reversion = hoy): el cron tickea cada
     * 10 min, y si el trabajador termina la segunda limpieza y la aprueban de nuevo antes de que
     * termine el día, no debe volver a mandarla a 'sucia' — el nochero dispara UNA reversión por
     * día, no una por cada vez que la pieza vuelve a quedar aprobada.
     *
     * @return list<Habitacion>
     */
    public function listarNocherosVigentesEnEstadoTerminal(string $hoy): array
    {
        $filas = Database::fetchAll(
            'SELECT * FROM #__habitaciones
              WHERE activa = 1 AND es_nochero = 1 AND nochero_hasta >= ?
                AND (nochero_ultima_reversion IS NULL OR nochero_ultima_reversion < ?)
                AND estado IN (?, ?, ?, ?)',
            [
                $hoy,
                $hoy,
                Habitacion::ESTADO_APROBADA,
                Habitacion::ESTADO_APROBADA_CON_OBSERVACION,
                Habitacion::ESTADO_APROBADA_AUTOMATICA,
                Habitacion::ESTADO_RECHAZADA,
            ]
        );
        return array_map(fn(array $f) => Habitacion::desdeFila($f), $filas);
    }

    /**
     * Registra que HOY ya se disparó el barrido de nochero para esta habitación (revertida a
     * 'sucia' por el cron de las 16:00). Ver listarNocherosVigentesEnEstadoTerminal().
     */
    public function marcarBarridoNocheroHoy(int $id, string $hoy): void
    {
        Database::execute(
            "UPDATE #__habitaciones SET nochero_ultima_reversion = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
            [$hoy, $id]
        );
    }

    public function buscarPorCloudbedsRoomId(int $hotelId, string $cloudbedsRoomId): ?Habitacion
    {
        $fila = Database::fetchOne(
            'SELECT * FROM #__habitaciones WHERE hotel_id = ? AND cloudbeds_room_id = ?',
            [$hotelId, $cloudbedsRoomId]
        );
        return $fila === null ? null : Habitacion::desdeFila($fila);
    }

    /**
     * Cambia el estado de una habitación validando la transición.
     * Lanza HabitacionException si la transición no es válida.
     *
     * @param bool $forzar Salta la matriz de transiciones (EstadoHabitacionService) y solo
     *                      valida que $nuevoEstado exista. USO EXCLUSIVO de correcciones desde
     *                      un sistema externo (ver CloudbedsSyncService::sincronizar() — Cloudbeds
     *                      como fuente madre del estado real). Nunca desde la UI/controladores.
     */
    public function cambiarEstado(int $id, string $nuevoEstado, ?int $usuarioId = null, string $origen = 'ui', bool $forzar = false): Habitacion
    {
        $habitacion = $this->obtener($id);
        if ($habitacion === null) {
            throw new HabitacionException('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada.', 404);
        }
        if ($forzar) {
            if (!in_array($nuevoEstado, Habitacion::ESTADOS_VALIDOS, true)) {
                throw new HabitacionException('ESTADO_INVALIDO', "Estado inválido: {$nuevoEstado}.", 400);
            }
        } else {
            $this->estados->aserciarTransicion($habitacion->estado, $nuevoEstado);
        }

        $contexto = [
            'habitacion_id' => $id,
            'desde' => $habitacion->estado,
            'hasta' => $nuevoEstado,
        ];

        try {
            Database::execute(
                "UPDATE #__habitaciones SET estado = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                [$nuevoEstado, $id]
            );
        } catch (\PDOException $e) {
            Logger::error(
                'habitaciones',
                'cambio de estado fallido',
                array_merge($contexto, ['error' => $e->getMessage()]),
                $usuarioId
            );
            throw $e;
        }

        Logger::info('habitaciones', 'cambio de estado', $contexto, $usuarioId);

        Logger::audit($usuarioId, 'habitacion.cambiar_estado', 'habitacion', $id, [
            'desde' => $habitacion->estado,
            'hasta' => $nuevoEstado,
        ], $origen);

        return new Habitacion(
            id: $habitacion->id,
            hotelId: $habitacion->hotelId,
            numero: $habitacion->numero,
            edificio: $habitacion->edificio,
            piso: $habitacion->piso,
            tipoHabitacionId: $habitacion->tipoHabitacionId,
            cloudbedsRoomId: $habitacion->cloudbedsRoomId,
            cloudbedsRoomName: $habitacion->cloudbedsRoomName,
            estado: $nuevoEstado,
            activa: $habitacion->activa,
            esEspacioComun: $habitacion->esEspacioComun,
            esNochero: $habitacion->esNochero,
            nocheroHasta: $habitacion->nocheroHasta,
        );
    }

    /**
     * Historial de movimientos de una habitación (audit_log): cambios de estado
     * ('habitacion.cambiar_estado') y mensajes de Recepción ('habitacion.agregar_nota').
     * Ambos ya se escriben en audit_log en cada evento, así que no hace falta una tabla
     * nueva: esto solo lee y formatea lo que ya existe. Ver formatearMovimientos().
     *
     * @return array<int, array<string, mixed>>
     */
    public function obtenerMovimientos(int $id, int $limite = 20): array
    {
        $limite = max(1, min(100, $limite));
        return $this->formatearMovimientos($this->consultarMovimientos($id, $limite));
    }

    /**
     * Historial completo de movimientos, sin el tope de pantalla — para exportar a Excel
     * (botón "Descargar todo" en la ficha, ver habitacion-detalle.php). Mismo permiso y misma
     * consulta que obtenerMovimientos(), solo sin el LIMIT de 20/100.
     *
     * @return array<int, array<string, mixed>>
     */
    public function obtenerMovimientosCompleto(int $id): array
    {
        return $this->formatearMovimientos($this->consultarMovimientos($id, self::MOVIMIENTOS_EXPORT_MAX));
    }

    /** @return array<int, array<string, mixed>> */
    private function consultarMovimientos(int $id, int $limite): array
    {
        return Database::fetchAll(
            "SELECT al.id, al.accion, al.detalles_json, al.origen, al.created_at, u.nombre AS usuario_nombre
               FROM #__audit_log al
          LEFT JOIN #__usuarios u ON u.id = al.usuario_id
              WHERE al.entidad = 'habitacion' AND al.entidad_id = ?
                AND al.accion IN ('habitacion.cambiar_estado', 'habitacion.agregar_nota')
              ORDER BY al.id DESC
              LIMIT {$limite}",
            [$id]
        );
    }

    /**
     * @param array<int, array<string, mixed>> $filas
     * @return array<int, array<string, mixed>>
     */
    private function formatearMovimientos(array $filas): array
    {
        $movimientos = [];
        foreach ($filas as $fila) {
            $detalles = null;
            if (!empty($fila['detalles_json'])) {
                $decoded = json_decode((string) $fila['detalles_json'], true);
                if (is_array($decoded)) {
                    $detalles = $decoded;
                }
            }
            $esNota = $fila['accion'] === 'habitacion.agregar_nota';
            $movimientos[] = [
                'id' => (int) $fila['id'],
                'tipo' => $esNota ? 'nota' : 'estado',
                'desde' => $esNota ? null : ($detalles['desde'] ?? null),
                'hasta' => $esNota ? null : ($detalles['hasta'] ?? null),
                'mensaje' => $esNota ? ($detalles['nota'] ?? null) : null,
                'usuario_nombre' => $fila['usuario_nombre'] ?? null,
                'origen' => (string) $fila['origen'],
                'created_at' => (string) $fila['created_at'],
            ];
        }

        return $movimientos;
    }

    /**
     * Crea o actualiza una habitación proveniente del catálogo de Cloudbeds.
     * Usado por la sincronización entrante.
     */
    public function upsertDesdeCloudbeds(int $hotelId, string $cloudbedsRoomId, string $numero, int $tipoHabitacionId): Habitacion
    {
        $existente = $this->buscarPorCloudbedsRoomId($hotelId, $cloudbedsRoomId);
        if ($existente !== null) {
            return $existente;
        }
        $porNumero = Database::fetchOne(
            'SELECT * FROM #__habitaciones WHERE hotel_id = ? AND numero = ?',
            [$hotelId, $numero]
        );
        if ($porNumero !== null) {
            Database::execute(
                "UPDATE #__habitaciones SET cloudbeds_room_id = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                [$cloudbedsRoomId, (int) $porNumero['id']]
            );
            return $this->obtener((int) $porNumero['id']);
        }
        Database::execute(
            'INSERT INTO #__habitaciones (hotel_id, numero, tipo_habitacion_id, cloudbeds_room_id, estado) VALUES (?, ?, ?, ?, ?)',
            [$hotelId, $numero, $tipoHabitacionId, $cloudbedsRoomId, Habitacion::ESTADO_SUCIA]
        );
        return $this->obtener(Database::lastInsertId());
    }
}
