<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Models;

final class Asignacion
{
    public function __construct(
        public readonly int $id,
        public readonly int $habitacionId,
        public readonly int $usuarioId,
        public readonly ?int $asignadoPor,
        public readonly int $ordenCola,
        public readonly string $fecha,
        public readonly bool $activa,
    ) {
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id: (int) $fila['id'],
            habitacionId: (int) $fila['habitacion_id'],
            usuarioId: (int) $fila['usuario_id'],
            asignadoPor: $fila['asignado_por'] !== null ? (int) $fila['asignado_por'] : null,
            ordenCola: (int) $fila['orden_cola'],
            fecha: (string) $fila['fecha'],
            activa: ((int) $fila['activa']) === 1,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'habitacion_id' => $this->habitacionId,
            'usuario_id' => $this->usuarioId,
            'asignado_por' => $this->asignadoPor,
            'orden_cola' => $this->ordenCola,
            'fecha' => $this->fecha,
            'activa' => $this->activa,
        ];
    }
}
