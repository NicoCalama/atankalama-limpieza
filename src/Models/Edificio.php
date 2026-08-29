<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Models;

class Edificio
{
    public function __construct(
        public readonly int $id,
        public readonly int $hotelId,
        public readonly string $nombre,
        public readonly int $pisos,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id: (int) $fila['id'],
            hotelId: (int) $fila['hotel_id'],
            nombre: (string) $fila['nombre'],
            pisos: (int) ($fila['pisos'] ?? 1),
            createdAt: (string) $fila['created_at'],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'hotel_id' => $this->hotelId,
            'nombre' => $this->nombre,
            'pisos' => $this->pisos,
            'created_at' => $this->createdAt,
        ];
    }
}
