<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Models;

final class Ticket
{
    public const PRIORIDAD_BAJA = 'baja';
    public const PRIORIDAD_NORMAL = 'normal';
    public const PRIORIDAD_ALTA = 'alta';
    public const PRIORIDAD_URGENTE = 'urgente';
    public const PRIORIDADES_VALIDAS = [
        self::PRIORIDAD_BAJA, self::PRIORIDAD_NORMAL, self::PRIORIDAD_ALTA, self::PRIORIDAD_URGENTE,
    ];

    public const ESTADO_ABIERTO = 'abierto';
    public const ESTADO_EN_PROGRESO = 'en_progreso';
    public const ESTADO_RESUELTO = 'resuelto';
    public const ESTADO_CERRADO = 'cerrado';
    public const ESTADOS_VALIDOS = [
        self::ESTADO_ABIERTO, self::ESTADO_EN_PROGRESO, self::ESTADO_RESUELTO, self::ESTADO_CERRADO,
    ];

    public function __construct(
        public readonly int $id,
        public readonly ?int $habitacionId,
        public readonly int $hotelId,
        public readonly string $titulo,
        public readonly string $descripcion,
        public readonly string $prioridad,
        public readonly string $estado,
        public readonly int $levantadoPor,
        public readonly ?int $asignadoA,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $resueltoAt,
    ) {
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id: (int) $fila['id'],
            habitacionId: isset($fila['habitacion_id']) && $fila['habitacion_id'] !== null ? (int) $fila['habitacion_id'] : null,
            hotelId: (int) $fila['hotel_id'],
            titulo: (string) $fila['titulo'],
            descripcion: (string) $fila['descripcion'],
            prioridad: (string) $fila['prioridad'],
            estado: (string) $fila['estado'],
            levantadoPor: (int) $fila['levantado_por'],
            asignadoA: isset($fila['asignado_a']) && $fila['asignado_a'] !== null ? (int) $fila['asignado_a'] : null,
            createdAt: (string) $fila['created_at'],
            updatedAt: (string) $fila['updated_at'],
            resueltoAt: $fila['resuelto_at'] !== null ? (string) $fila['resuelto_at'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'habitacion_id' => $this->habitacionId,
            'hotel_id' => $this->hotelId,
            'titulo' => $this->titulo,
            'descripcion' => $this->descripcion,
            'prioridad' => $this->prioridad,
            'estado' => $this->estado,
            'levantado_por' => $this->levantadoPor,
            'asignado_a' => $this->asignadoA,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'resuelto_at' => $this->resueltoAt,
        ];
    }
}
