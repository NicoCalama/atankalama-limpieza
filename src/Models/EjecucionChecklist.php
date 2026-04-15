<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Models;

final class EjecucionChecklist
{
    public const ESTADO_EN_PROGRESO = 'en_progreso';
    public const ESTADO_COMPLETADA = 'completada';
    public const ESTADO_AUDITADA = 'auditada';

    public function __construct(
        public readonly int $id,
        public readonly int $habitacionId,
        public readonly int $asignacionId,
        public readonly int $usuarioId,
        public readonly int $templateId,
        public readonly string $estado,
        public readonly string $timestampInicio,
        public readonly ?string $timestampFin,
    ) {
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id: (int) $fila['id'],
            habitacionId: (int) $fila['habitacion_id'],
            asignacionId: (int) $fila['asignacion_id'],
            usuarioId: (int) $fila['usuario_id'],
            templateId: (int) $fila['template_id'],
            estado: (string) $fila['estado'],
            timestampInicio: (string) $fila['timestamp_inicio'],
            timestampFin: $fila['timestamp_fin'] !== null ? (string) $fila['timestamp_fin'] : null,
        );
    }

    /** Oculta timestamps al trabajador — usar toArrayPublico() en endpoints. */
    public function toArrayPublico(): array
    {
        return [
            'id' => $this->id,
            'habitacion_id' => $this->habitacionId,
            'asignacion_id' => $this->asignacionId,
            'usuario_id' => $this->usuarioId,
            'template_id' => $this->templateId,
            'estado' => $this->estado,
        ];
    }
}
