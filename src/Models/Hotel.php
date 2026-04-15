<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Models;

final class Hotel
{
    public function __construct(
        public readonly int $id,
        public readonly string $codigo,
        public readonly string $nombre,
        public readonly ?string $cloudbedsPropertyId,
        public readonly bool $activo,
    ) {
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id: (int) $fila['id'],
            codigo: (string) $fila['codigo'],
            nombre: (string) $fila['nombre'],
            cloudbedsPropertyId: $fila['cloudbeds_property_id'] !== null ? (string) $fila['cloudbeds_property_id'] : null,
            activo: ((int) $fila['activo']) === 1,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'cloudbeds_property_id' => $this->cloudbedsPropertyId,
            'activo' => $this->activo,
        ];
    }
}
