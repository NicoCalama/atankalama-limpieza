<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\Habitacion;

final class HabitacionService
{
    public function __construct(
        private readonly EstadoHabitacionService $estados = new EstadoHabitacionService(),
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
        $where = ['h.activa = 1'];
        $params = [];

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

        $sql = 'SELECT h.*, ho.codigo AS hotel_codigo, ho.nombre AS hotel_nombre, th.nombre AS tipo_nombre
                  FROM habitaciones h
                  JOIN hoteles ho ON ho.id = h.hotel_id
                  JOIN tipos_habitacion th ON th.id = h.tipo_habitacion_id
                 WHERE ' . implode(' AND ', $where) . '
              ORDER BY ho.codigo, h.numero';

        return Database::fetchAll($sql, $params);
    }

    public function obtener(int $id): ?Habitacion
    {
        $fila = Database::fetchOne('SELECT * FROM habitaciones WHERE id = ?', [$id]);
        return $fila === null ? null : Habitacion::desdeFila($fila);
    }

    /**
     * Detalle enriquecido (hotel, tipo). null si no existe.
     *
     * @return array<string, mixed>|null
     */
    public function obtenerDetalle(int $id): ?array
    {
        return Database::fetchOne(
            'SELECT h.*, ho.codigo AS hotel_codigo, ho.nombre AS hotel_nombre, th.nombre AS tipo_nombre
               FROM habitaciones h
               JOIN hoteles ho ON ho.id = h.hotel_id
               JOIN tipos_habitacion th ON th.id = h.tipo_habitacion_id
              WHERE h.id = ?',
            [$id]
        );
    }

    public function buscarPorCloudbedsRoomId(int $hotelId, string $cloudbedsRoomId): ?Habitacion
    {
        $fila = Database::fetchOne(
            'SELECT * FROM habitaciones WHERE hotel_id = ? AND cloudbeds_room_id = ?',
            [$hotelId, $cloudbedsRoomId]
        );
        return $fila === null ? null : Habitacion::desdeFila($fila);
    }

    /**
     * Cambia el estado de una habitación validando la transición.
     * Lanza HabitacionException si la transición no es válida.
     */
    public function cambiarEstado(int $id, string $nuevoEstado, ?int $usuarioId = null, string $origen = 'ui'): Habitacion
    {
        $habitacion = $this->obtener($id);
        if ($habitacion === null) {
            throw new HabitacionException('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada.', 404);
        }
        $this->estados->aserciarTransicion($habitacion->estado, $nuevoEstado);

        Database::execute(
            "UPDATE habitaciones SET estado = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
            [$nuevoEstado, $id]
        );

        Logger::info('habitaciones', 'cambio de estado', [
            'habitacion_id' => $id,
            'desde' => $habitacion->estado,
            'hasta' => $nuevoEstado,
        ], $usuarioId);

        Logger::audit($usuarioId, 'habitacion.cambiar_estado', 'habitacion', $id, [
            'desde' => $habitacion->estado,
            'hasta' => $nuevoEstado,
        ], $origen);

        return new Habitacion(
            id: $habitacion->id,
            hotelId: $habitacion->hotelId,
            numero: $habitacion->numero,
            tipoHabitacionId: $habitacion->tipoHabitacionId,
            cloudbedsRoomId: $habitacion->cloudbedsRoomId,
            estado: $nuevoEstado,
            activa: $habitacion->activa,
        );
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
            'SELECT * FROM habitaciones WHERE hotel_id = ? AND numero = ?',
            [$hotelId, $numero]
        );
        if ($porNumero !== null) {
            Database::execute(
                "UPDATE habitaciones SET cloudbeds_room_id = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                [$cloudbedsRoomId, (int) $porNumero['id']]
            );
            return $this->obtener((int) $porNumero['id']);
        }
        Database::execute(
            'INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, cloudbeds_room_id, estado) VALUES (?, ?, ?, ?, ?)',
            [$hotelId, $numero, $tipoHabitacionId, $cloudbedsRoomId, Habitacion::ESTADO_SUCIA]
        );
        return $this->obtener(Database::lastInsertId());
    }
}
