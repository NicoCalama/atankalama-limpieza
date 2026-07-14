<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Core\Url;
use Atankalama\Limpieza\Models\AlertaActiva;
use Atankalama\Limpieza\Models\Asignacion;
use Atankalama\Limpieza\Models\Habitacion;

final class AsignacionService
{
    public function __construct(
        private readonly NotificacionesService $notificaciones = new NotificacionesService(),
        private readonly AlertasService $alertas = new AlertasService(),
        private readonly SabanasService $sabanas = new SabanasService(),
    ) {
    }

    public function asignarManual(int $habitacionId, int $usuarioId, string $fecha, ?int $asignadoPor = null, ?string $franja = null): Asignacion
    {
        $this->validarFecha($fecha);
        $franja = $this->validarFranja($franja);
        $this->desactivarAsignacionesActivas($habitacionId);
        $orden = $this->siguienteOrdenCola($usuarioId, $fecha);

        // Si la habitación estaba en un estado terminal (rechazada / aprobada*), al (re)asignarla
        // vuelve a 'sucia' para que el nuevo trabajador pueda iniciar limpieza. Esta es la primitiva
        // de "re-abrir on-demand": la usa la reasignación tras rechazo, el re-pedir limpieza de un
        // espacio (área común) y —a futuro— la 2ª limpieza del día (feature F). La auditoría
        // histórica permanece inmutable (queda ligada a su ejecución_checklist).
        $estadoActual = Database::fetchOne('SELECT estado FROM #__habitaciones WHERE id = ?', [$habitacionId]);
        $estadoTerminal = $estadoActual !== null && in_array($estadoActual['estado'], [
            Habitacion::ESTADO_RECHAZADA,
            Habitacion::ESTADO_APROBADA,
            Habitacion::ESTADO_APROBADA_CON_OBSERVACION,
        ], true);
        if ($estadoTerminal) {
            Database::execute(
                "UPDATE #__habitaciones SET estado = 'sucia', updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                [$habitacionId]
            );
            Logger::info('habitaciones', 'terminal→sucia por (re)asignación', [
                'habitacion_id' => $habitacionId, 'desde' => $estadoActual['estado'], 'asignado_por' => $asignadoPor,
            ]);
            // Si venía de un rechazo, la alerta P1 ya cumplió su propósito (la supervisora reasignó):
            // se resuelve para que no quede colgada tras re-limpiar y re-aprobar.
            if ($estadoActual['estado'] === Habitacion::ESTADO_RECHAZADA) {
                $this->alertas->resolverPorDedupe(
                    AlertaActiva::TIPO_HABITACION_RECHAZADA,
                    "habitacion:{$habitacionId}"
                );
            }
        }

        Database::execute(
            'INSERT INTO #__asignaciones (habitacion_id, usuario_id, asignado_por, orden_cola, fecha, franja, activa) VALUES (?, ?, ?, ?, ?, ?, 1)',
            [$habitacionId, $usuarioId, $asignadoPor, $orden, $fecha, $franja]
        );
        $id = Database::lastInsertId();

        Logger::audit($asignadoPor, 'asignacion.crear_manual', 'asignacion', $id, [
            'habitacion_id' => $habitacionId, 'usuario_id' => $usuarioId, 'fecha' => $fecha,
        ]);

        $asignacion = $this->obtener($id)
            ?? throw new AsignacionException('ASIGNACION_NO_CREADA', 'Error al crear asignación.', 500);

        $hab = Database::fetchOne('SELECT numero FROM #__habitaciones WHERE id = ?', [$habitacionId]);
        if ($hab !== null) {
            $this->notificaciones->crear(
                $usuarioId,
                'asignacion',
                'Nueva habitación asignada',
                "Se te asignó la habitación #{$hab['numero']} para hoy.",
                // La URL viaja al navegador tal cual (el popup no re-prefija):
                // se antepone BASE_PATH acá, igual que hace PushService::notificar.
                Url::a("/habitaciones/{$habitacionId}")
            );
        }

        return $asignacion;
    }

    /**
     * Asignación múltiple manual (supervisora selecciona varias habitaciones para un trabajador).
     *
     * @param list<int> $habitacionIds
     * @return list<Asignacion>
     */
    public function asignarMultiple(array $habitacionIds, int $usuarioId, string $fecha, ?int $asignadoPor = null, ?string $franja = null): array
    {
        $creadas = [];
        foreach ($habitacionIds as $habitacionId) {
            $creadas[] = $this->asignarManual($habitacionId, $usuarioId, $fecha, $asignadoPor, $franja);
        }
        return $creadas;
    }

    /**
     * Round-robin: reparte habitaciones sucias entre trabajadores con turno para esa fecha.
     *
     * @return array{asignaciones: list<Asignacion>, habitaciones: int, trabajadores: int}
     */
    public function autoAsignar(string $hotelCodigo, string $fecha): array
    {
        $this->validarFecha($fecha);

        $filtroHotel = ($hotelCodigo === 'ambos') ? null : $hotelCodigo;

        $sqlHab = 'SELECT h.id
                     FROM #__habitaciones h
                     JOIN #__hoteles ho ON ho.id = h.hotel_id
                LEFT JOIN #__asignaciones a
                       ON a.habitacion_id = h.id AND a.fecha = ? AND a.activa = 1
                    WHERE h.estado = ?
                      AND h.activa = 1
                      AND h.es_espacio_comun = 0
                      AND a.id IS NULL';
        $paramsHab = [$fecha, Habitacion::ESTADO_SUCIA];
        if ($filtroHotel !== null) {
            $sqlHab .= ' AND ho.codigo = ?';
            $paramsHab[] = $filtroHotel;
        }
        $sqlHab .= ' ORDER BY ho.codigo, h.numero';
        $habitaciones = array_map(static fn(array $f) => (int) $f['id'], Database::fetchAll($sqlHab, $paramsHab));

        // Trabajadores con turno ese día. Filtrar por hotel_default compatible.
        $sqlUsr = 'SELECT u.id
                     FROM #__usuarios u
                     JOIN #__usuarios_turnos ut ON ut.usuario_id = u.id
                    WHERE ut.fecha = ?
                      AND u.activo = 1';
        $paramsUsr = [$fecha];
        if ($filtroHotel !== null) {
            $sqlUsr .= " AND (u.hotel_default = ? OR u.hotel_default = 'ambos')";
            $paramsUsr[] = $filtroHotel;
        }
        $sqlUsr .= ' ORDER BY u.id';
        $trabajadores = array_map(static fn(array $f) => (int) $f['id'], Database::fetchAll($sqlUsr, $paramsUsr));

        if ($trabajadores === []) {
            throw new AsignacionException('SIN_TRABAJADORES', 'No hay trabajadores con turno asignado para la fecha.', 409);
        }
        if ($habitaciones === []) {
            return ['asignaciones' => [], 'habitaciones' => 0, 'trabajadores' => count($trabajadores)];
        }

        $creadas = [];
        $n = count($trabajadores);
        foreach ($habitaciones as $i => $habitacionId) {
            $usuarioId = $trabajadores[$i % $n];
            $creadas[] = $this->asignarManual($habitacionId, $usuarioId, $fecha, null);
        }

        Logger::info('asignaciones', 'round-robin ejecutado', [
            'fecha' => $fecha, 'hotel' => $hotelCodigo,
            'habitaciones' => count($habitaciones), 'trabajadores' => $n,
        ]);

        return ['asignaciones' => $creadas, 'habitaciones' => count($habitaciones), 'trabajadores' => $n];
    }

    /**
     * Reasignar habitación (típicamente rechazada) a otro trabajador.
     */
    public function reasignar(int $habitacionId, int $usuarioId, string $fecha, string $motivo, ?int $asignadoPor = null): Asignacion
    {
        $asignacion = $this->asignarManual($habitacionId, $usuarioId, $fecha, $asignadoPor);
        Logger::audit($asignadoPor, 'asignacion.reasignar', 'asignacion', $asignacion->id, [
            'habitacion_id' => $habitacionId, 'usuario_id' => $usuarioId, 'motivo' => $motivo,
        ]);
        return $asignacion;
    }

    /**
     * Desasigna la habitación: la saca de la cola del trabajador sin asignársela
     * a nadie (activa = 0), y vuelve al pool "Sin asignar" de la vista.
     *
     * Solo estados no terminales (sucia / en_progreso / rechazada): una pieza ya
     * completada o aprobada no se desasigna (409) — su asignación es el registro
     * de quién la limpió hoy.
     *
     * Si estaba en_progreso vuelve a 'sucia' (nadie la está limpiando ya); la
     * ejecución en curso queda huérfana, igual que al reasignar (ver comentario
     * en ChecklistService::iniciarEjecucion), y la próxima asignación arranca
     * una ejecución nueva desde cero.
     */
    public function desasignar(int $habitacionId, string $fecha, ?int $actorId = null): void
    {
        $this->validarFecha($fecha);

        $asignacion = Database::fetchOne(
            'SELECT * FROM #__asignaciones WHERE habitacion_id = ? AND fecha = ? AND activa = 1 ORDER BY id DESC LIMIT 1',
            [$habitacionId, $fecha]
        );
        if ($asignacion === null) {
            throw new AsignacionException(
                'ASIGNACION_NO_ENCONTRADA',
                'La habitación no tiene una asignación activa para esa fecha.',
                404
            );
        }

        $hab = Database::fetchOne('SELECT numero, estado FROM #__habitaciones WHERE id = ?', [$habitacionId]);
        if ($hab === null) {
            throw new AsignacionException('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada.', 404);
        }
        $desasignables = [Habitacion::ESTADO_SUCIA, Habitacion::ESTADO_EN_PROGRESO, Habitacion::ESTADO_RECHAZADA];
        if (!in_array($hab['estado'], $desasignables, true)) {
            throw new AsignacionException(
                'ESTADO_NO_DESASIGNABLE',
                'La habitación ya fue completada: no se puede desasignar.',
                409
            );
        }

        Database::transaction(function () use ($habitacionId, $hab): void {
            Database::execute('UPDATE #__asignaciones SET activa = 0 WHERE habitacion_id = ? AND activa = 1', [$habitacionId]);
            if ($hab['estado'] === Habitacion::ESTADO_EN_PROGRESO) {
                Database::execute(
                    "UPDATE #__habitaciones SET estado = 'sucia', updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                    [$habitacionId]
                );
            }
        });

        Logger::audit($actorId, 'asignacion.desasignar', 'asignacion', (int) $asignacion['id'], [
            'habitacion_id' => $habitacionId,
            'usuario_id' => (int) $asignacion['usuario_id'],
            'fecha' => $fecha,
            'estado_previo' => $hab['estado'],
        ]);

        // Simetría con asignarManual: el trabajador se entera de que la pieza salió de su cola.
        $this->notificaciones->crear(
            (int) $asignacion['usuario_id'],
            'asignacion',
            'Habitación retirada de tu cola',
            "La habitación #{$hab['numero']} ya no está asignada a ti.",
            Url::a('/home')
        );
    }

    /**
     * Reordena la cola de un trabajador (array de habitacion_ids en orden deseado).
     *
     * @param list<int> $ordenHabitaciones
     */
    public function reordenarCola(int $usuarioId, string $fecha, array $ordenHabitaciones, ?int $actorId = null): void
    {
        foreach ($ordenHabitaciones as $idx => $habitacionId) {
            Database::execute(
                'UPDATE #__asignaciones SET orden_cola = ? WHERE usuario_id = ? AND fecha = ? AND habitacion_id = ? AND activa = 1',
                [$idx + 1, $usuarioId, $fecha, $habitacionId]
            );
        }
        Logger::audit($actorId, 'asignacion.reordenar_cola', 'usuario', $usuarioId, [
            'fecha' => $fecha, 'orden' => $ordenHabitaciones,
        ]);
    }

    /**
     * Manda la habitación al final de la cola del trabajador (mayor orden_cola).
     * Usado por la válvula de escape "No puedo terminar ahora".
     */
    public function enviarAlFinalDeCola(int $habitacionId, int $usuarioId, string $fecha, ?int $actorId = null): void
    {
        $siguiente = $this->siguienteOrdenCola($usuarioId, $fecha);
        Database::execute(
            'UPDATE #__asignaciones SET orden_cola = ? WHERE habitacion_id = ? AND usuario_id = ? AND fecha = ? AND activa = 1',
            [$siguiente, $habitacionId, $usuarioId, $fecha]
        );
        Logger::audit($actorId, 'asignacion.enviar_al_final', 'asignacion', $habitacionId, [
            'usuario_id' => $usuarioId, 'fecha' => $fecha, 'nuevo_orden' => $siguiente,
        ]);
    }

    public function obtener(int $id): ?Asignacion
    {
        $fila = Database::fetchOne('SELECT * FROM #__asignaciones WHERE id = ?', [$id]);
        return $fila === null ? null : Asignacion::desdeFila($fila);
    }

    public function obtenerActivaDeHabitacion(int $habitacionId): ?Asignacion
    {
        $fila = Database::fetchOne(
            'SELECT * FROM #__asignaciones WHERE habitacion_id = ? AND activa = 1 ORDER BY id DESC LIMIT 1',
            [$habitacionId]
        );
        return $fila === null ? null : Asignacion::desdeFila($fila);
    }

    /**
     * Vista consolidada para la página de Asignaciones:
     *   - habitaciones "sucia" sin asignar hoy (agrupadas por hotel)
     *   - trabajadores con turno hoy, con su cola (habitaciones + estados)
     *
     * @param string $hotel código del hotel ('ambos', '1_sur', 'inn', etc.)
     * @param string $fecha YYYY-MM-DD
     * @return array{
     *     fecha: string,
     *     hotel: string,
     *     sin_asignar: array<int, array<string, mixed>>,
     *     re_limpiar: array<int, array<string, mixed>>,
     *     trabajadores: list<array<string, mixed>>
     * }
     */
    public function vistaConsolidada(string $hotel, string $fecha): array
    {
        $filtroHotel = ($hotel === 'ambos') ? null : $hotel;

        // Habitaciones sucias o rechazadas SIN asignación activa hoy
        // (las rechazadas necesitan reasignarse — al hacerlo, asignarManual las pasa a 'sucia')
        $sqlSin = 'SELECT h.id, h.numero, h.estado, ho.codigo AS hotel_codigo, ho.nombre AS hotel_nombre, th.nombre AS tipo_nombre
                     FROM #__habitaciones h
                     JOIN #__hoteles ho ON ho.id = h.hotel_id
                     JOIN #__tipos_habitacion th ON th.id = h.tipo_habitacion_id
                LEFT JOIN #__asignaciones a ON a.habitacion_id = h.id AND a.fecha = ? AND a.activa = 1
                    WHERE h.activa = 1
                      AND h.es_espacio_comun = 0
                      AND h.estado IN (\'sucia\', \'rechazada\')
                      AND a.id IS NULL';
        $paramsSin = [$fecha];
        if ($filtroHotel !== null) {
            $sqlSin .= ' AND ho.codigo = ?';
            $paramsSin[] = $filtroHotel;
        }
        $sqlSin .= ' ORDER BY ho.codigo, h.numero';
        $sinAsignar = Database::fetchAll($sqlSin, $paramsSin);

        // Piezas ya limpias HOY (aprobadas) sin asignación activa: candidatas a una 2ª limpieza en
        // otra ventana (día/noche). Al pedirles limpieza, asignarManual las resetea a 'sucia' y la
        // nueva limpieza arranca de cero. Ver docs/limpiezas-multiples-dia.md
        // Piezas ASIGNADAS hoy que ya quedaron limpias: se scoping por la asignación activa de la
        // fecha (su fecha es local, a diferencia de created_at que va en UTC). Una pieza recién
        // limpiada conserva su asignación (completada) activa; al pedir otra limpieza, asignarManual
        // la desactiva y crea la nueva. Excluye aprobadas de días anteriores (sin asignación de hoy).
        $sqlRe = 'SELECT h.id, h.numero, h.estado, ho.codigo AS hotel_codigo, ho.nombre AS hotel_nombre, th.nombre AS tipo_nombre
                     FROM #__habitaciones h
                     JOIN #__hoteles ho ON ho.id = h.hotel_id
                     JOIN #__tipos_habitacion th ON th.id = h.tipo_habitacion_id
                    WHERE h.activa = 1
                      AND h.es_espacio_comun = 0
                      AND h.estado IN (\'aprobada\', \'aprobada_con_observacion\')
                      AND EXISTS (SELECT 1 FROM #__asignaciones a WHERE a.habitacion_id = h.id AND a.fecha = ? AND a.activa = 1)';
        $paramsRe = [$fecha];
        if ($filtroHotel !== null) {
            $sqlRe .= ' AND ho.codigo = ?';
            $paramsRe[] = $filtroHotel;
        }
        $sqlRe .= ' ORDER BY ho.codigo, h.numero';
        $reLimpiar = Database::fetchAll($sqlRe, $paramsRe);

        // Trabajadores con turno hoy (filtrados por hotel si aplica)
        $sqlTr = 'SELECT u.id, u.nombre, u.rut, u.hotel_default
                    FROM #__usuarios u
                    JOIN #__usuarios_turnos ut ON ut.usuario_id = u.id
                   WHERE ut.fecha = ?
                     AND u.activo = 1';
        $paramsTr = [$fecha];
        if ($filtroHotel !== null) {
            $sqlTr .= " AND (u.hotel_default = ? OR u.hotel_default = 'ambos')";
            $paramsTr[] = $filtroHotel;
        }
        $sqlTr .= ' ORDER BY u.nombre';
        $trabajadores = Database::fetchAll($sqlTr, $paramsTr);

        $trabajadoresVista = [];
        foreach ($trabajadores as $tr) {
            $cola = $this->colaDelTrabajador((int) $tr['id'], $fecha);
            if ($filtroHotel !== null) {
                $cola = array_values(array_filter(
                    $cola,
                    static fn(array $h) => ($h['hotel_codigo'] ?? null) === $filtroHotel
                ));
            }
            $pendientes = 0;
            $enProgreso = 0;
            $completadas = 0;
            $rechazadas = 0;
            foreach ($cola as $h) {
                $estado = $h['estado'] ?? '';
                if ($estado === 'sucia') {
                    $pendientes++;
                } elseif ($estado === 'en_progreso') {
                    $enProgreso++;
                } elseif (in_array($estado, ['completada_pendiente_auditoria', 'aprobada', 'aprobada_con_observacion'], true)) {
                    $completadas++;
                } elseif ($estado === 'rechazada') {
                    $rechazadas++;
                }
            }
            $trabajadoresVista[] = [
                'usuario' => [
                    'id' => (int) $tr['id'],
                    'nombre' => $tr['nombre'],
                    'rut' => $tr['rut'],
                    'hotel_default' => $tr['hotel_default'],
                ],
                'cola' => $cola,
                'progreso' => [
                    'pendientes' => $pendientes,
                    'en_progreso' => $enProgreso,
                    'completadas' => $completadas,
                    'rechazadas' => $rechazadas,
                    'total' => count($cola),
                ],
            ];
        }

        return [
            'fecha' => $fecha,
            'hotel' => $hotel,
            'sin_asignar' => $sinAsignar,
            're_limpiar' => $reLimpiar,
            'trabajadores' => $trabajadoresVista,
        ];
    }

    /**
     * Cola del trabajador para una fecha, ordenada por orden_cola.
     *
     * @return list<array<string, mixed>>
     */
    public function colaDelTrabajador(int $usuarioId, string $fecha): array
    {
        $filas = Database::fetchAll(
            'SELECT a.*, h.numero, h.estado, h.cb_frontdesk_status, h.cb_arrival_date,
                    ho.codigo AS hotel_codigo, ho.sabanas_cada_n_dias, th.nombre AS tipo_nombre
               FROM #__asignaciones a
               JOIN #__habitaciones h ON h.id = a.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
               JOIN #__tipos_habitacion th ON th.id = h.tipo_habitacion_id
              WHERE a.usuario_id = ? AND a.fecha = ? AND a.activa = 1
              ORDER BY a.orden_cola, a.id',
            [$usuarioId, $fecha]
        );
        return array_map(fn(array $f) => $this->sabanas->anotarFila($f), $filas);
    }

    /**
     * Habitación "actual" del trabajador en el flujo una-a-la-vez: la primera de
     * la cola (orden_cola) que NO está completada — en_progreso, sucia o rechazada.
     * Devuelve null si no queda ninguna pendiente (cola vacía o todo completado).
     *
     * Misma selección que HomeController::trabajador para mantener una sola fuente
     * de verdad de "cuál es la habitación que le toca ahora".
     *
     * @return array<string, mixed>|null Fila de la cola (forma de colaDelTrabajador).
     */
    public function habitacionActualDeCola(int $usuarioId, string $fecha): ?array
    {
        foreach ($this->colaDelTrabajador($usuarioId, $fecha) as $item) {
            if (in_array($item['estado'], ['en_progreso', 'sucia', 'rechazada'], true)) {
                return $item;
            }
        }
        return null;
    }

    public function esHabitacionAsignadaA(int $habitacionId, int $usuarioId, string $fecha): bool
    {
        $fila = Database::fetchOne(
            'SELECT 1 FROM #__asignaciones WHERE habitacion_id = ? AND usuario_id = ? AND fecha = ? AND activa = 1',
            [$habitacionId, $usuarioId, $fecha]
        );
        return $fila !== null;
    }

    private function desactivarAsignacionesActivas(int $habitacionId): void
    {
        Database::execute('UPDATE #__asignaciones SET activa = 0 WHERE habitacion_id = ? AND activa = 1', [$habitacionId]);
    }

    private function siguienteOrdenCola(int $usuarioId, string $fecha): int
    {
        $fila = Database::fetchOne(
            'SELECT COALESCE(MAX(orden_cola), 0) + 1 AS siguiente FROM #__asignaciones WHERE usuario_id = ? AND fecha = ? AND activa = 1',
            [$usuarioId, $fecha]
        );
        return (int) ($fila['siguiente'] ?? 1);
    }

    private function validarFecha(string $fecha): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            throw new AsignacionException('FECHA_INVALIDA', 'La fecha debe tener formato YYYY-MM-DD.', 400);
        }
    }

    /** Normaliza y valida la franja (ventana de limpieza). NULL/'' = sin etiqueta. */
    private function validarFranja(?string $franja): ?string
    {
        if ($franja === null || $franja === '') {
            return null;
        }
        if (!in_array($franja, Asignacion::FRANJAS, true)) {
            throw new AsignacionException('FRANJA_INVALIDA', 'La franja debe ser mañana, tarde o noche.', 400);
        }
        return $franja;
    }
}
