<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Models;

final class TipoHabitacion
{
    public function __construct(
        public readonly int $id,
        public readonly string $nombre,
        public readonly ?string $descripcion,
    ) {
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id: (int) $fila['id'],
            nombre: (string) $fila['nombre'],
            descripcion: $fila['descripcion'] !== null ? (string) $fila['descripcion'] : null,
        );
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'nombre' => $this->nombre, 'descripcion' => $this->descripcion];
    }
}
