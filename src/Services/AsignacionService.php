<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\Asignacion;
use Atankalama\Limpieza\Models\Habitacion;

final class AsignacionService
{
    public function asignarManual(int $habitacionId, int $usuarioId, string $fecha, ?int $asignadoPor = null): Asignacion
    {
        $this->validarFecha($fecha);
        $this->desactivarAsignacionesActivas($habitacionId);
        $orden = $this->siguienteOrdenCola($usuarioId, $fecha);

        Database::execute(
            'INSERT INTO asignaciones (habitacion_id, usuario_id, asignado_por, orden_cola, fecha, activa) VALUES (?, ?, ?, ?, ?, 1)',
            [$habitacionId, $usuarioId, $asignadoPor, $orden, $fecha]
        );
        $id = Database::lastInsertId();

        Logger::audit($asignadoPor, 'asignacion.crear_manual', 'asignacion', $id, [
            'habitacion_id' => $habitacionId, 'usuario_id' => $usuarioId, 'fecha' => $fecha,
        ]);

        return $this->obtener($id)
            ?? throw new AsignacionException('ASIGNACION_NO_CREADA', 'Error al crear asignación.', 500);
    }

    /**
     * Asignación múltiple manual (supervisora selecciona varias habitaciones para un trabajador).
     *
     * @param list<int> $habitacionIds
     * @return list<Asignacion>
     */
    public function asignarMultiple(array $habitacionIds, int $usuarioId, string $fecha, ?int $asignadoPor = null): array
    {
        $creadas = [];
        foreach ($habitacionIds as $habitacionId) {
            $creadas[] = $this->asignarManual($habitacionId, $usuarioId, $fecha, $asignadoPor);
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
                     FROM habitaciones h
                     JOIN hoteles ho ON ho.id = h.hotel_id
                LEFT JOIN asignaciones a
                       ON a.habitacion_id = h.id AND a.fecha = ? AND a.activa = 1
                    WHERE h.estado = ?
                      AND h.activa = 1
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
                     FROM usuarios u
                     JOIN usuarios_turnos ut ON ut.usuario_id = u.id
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
     * Reordena la cola de un trabajador (array de habitacion_ids en orden deseado).
     *
     * @param list<int> $ordenHabitaciones
     */
    public function reordenarCola(int $usuarioId, string $fecha, array $ordenHabitaciones, ?int $actorId = null): void
    {
        foreach ($ordenHabitaciones as $idx => $habitacionId) {
            Database::execute(
                'UPDATE asignaciones SET orden_cola = ? WHERE usuario_id = ? AND fecha = ? AND habitacion_id = ? AND activa = 1',
                [$idx + 1, $usuarioId, $fecha, $habitacionId]
            );
        }
        Logger::audit($actorId, 'asignacion.reordenar_cola', 'usuario', $usuarioId, [
            'fecha' => $fecha, 'orden' => $ordenHabitaciones,
        ]);
    }

    public function obtener(int $id): ?Asignacion
    {
        $fila = Database::fetchOne('SELECT * FROM asignaciones WHERE id = ?', [$id]);
        return $fila === null ? null : Asignacion::desdeFila($fila);
    }

    public function obtenerActivaDeHabitacion(int $habitacionId): ?Asignacion
    {
        $fila = Database::fetchOne(
            'SELECT * FROM asignaciones WHERE habitacion_id = ? AND activa = 1 ORDER BY id DESC LIMIT 1',
            [$habitacionId]
        );
        return $fila === null ? null : Asignacion::desdeFila($fila);
    }

    /**
     * Cola del trabajador para una fecha, ordenada por orden_cola.
     *
     * @return list<array<string, mixed>>
     */
    public function colaDelTrabajador(int $usuarioId, string $fecha): array
    {
        return Database::fetchAll(
            'SELECT a.*, h.numero, h.estado, ho.codigo AS hotel_codigo, th.nombre AS tipo_nombre
               FROM asignaciones a
               JOIN habitaciones h ON h.id = a.habitacion_id
               JOIN hoteles ho ON ho.id = h.hotel_id
               JOIN tipos_habitacion th ON th.id = h.tipo_habitacion_id
              WHERE a.usuario_id = ? AND a.fecha = ? AND a.activa = 1
              ORDER BY a.orden_cola, a.id',
            [$usuarioId, $fecha]
        );
    }

    public function esHabitacionAsignadaA(int $habitacionId, int $usuarioId, string $fecha): bool
    {
        $fila = Database::fetchOne(
            'SELECT 1 FROM asignaciones WHERE habitacion_id = ? AND usuario_id = ? AND fecha = ? AND activa = 1',
            [$habitacionId, $usuarioId, $fecha]
        );
        return $fila !== null;
    }

    private function desactivarAsignacionesActivas(int $habitacionId): void
    {
        Database::execute('UPDATE asignaciones SET activa = 0 WHERE habitacion_id = ? AND activa = 1', [$habitacionId]);
    }

    private function siguienteOrdenCola(int $usuarioId, string $fecha): int
    {
        $fila = Database::fetchOne(
            'SELECT COALESCE(MAX(orden_cola), 0) + 1 AS siguiente FROM asignaciones WHERE usuario_id = ? AND fecha = ? AND activa = 1',
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
}
