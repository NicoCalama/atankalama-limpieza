<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Models;

final class ItemChecklist
{
    public function __construct(
        public readonly int $id,
        public readonly int $templateId,
        public readonly int $orden,
        public readonly string $descripcion,
        public readonly bool $obligatorio,
        public readonly bool $activo,
    ) {
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id: (int) $fila['id'],
            templateId: (int) $fila['template_id'],
            orden: (int) $fila['orden'],
            descripcion: (string) $fila['descripcion'],
            obligatorio: ((int) $fila['obligatorio']) === 1,
            activo: ((int) $fila['activo']) === 1,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'template_id' => $this->templateId,
            'orden' => $this->orden,
            'descripcion' => $this->descripcion,
            'obligatorio' => $this->obligatorio,
            'activo' => $this->activo,
        ];
    }
}
