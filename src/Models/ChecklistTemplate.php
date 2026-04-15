<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Models;

final class ChecklistTemplate
{
    public function __construct(
        public readonly int $id,
        public readonly int $tipoHabitacionId,
        public readonly string $nombre,
        public readonly bool $activo,
    ) {
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id: (int) $fila['id'],
            tipoHabitacionId: (int) $fila['tipo_habitacion_id'],
            nombre: (string) $fila['nombre'],
            activo: ((int) $fila['activo']) === 1,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tipo_habitacion_id' => $this->tipoHabitacionId,
            'nombre' => $this->nombre,
            'activo' => $this->activo,
        ];
    }
}
