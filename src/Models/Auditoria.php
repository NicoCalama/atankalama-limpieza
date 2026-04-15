<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Models;

final class Auditoria
{
    public const VEREDICTO_APROBADO = 'aprobado';
    public const VEREDICTO_APROBADO_CON_OBSERVACION = 'aprobado_con_observacion';
    public const VEREDICTO_RECHAZADO = 'rechazado';

    public const VEREDICTOS_VALIDOS = [
        self::VEREDICTO_APROBADO,
        self::VEREDICTO_APROBADO_CON_OBSERVACION,
        self::VEREDICTO_RECHAZADO,
    ];

    public function __construct(
        public readonly int $id,
        public readonly int $ejecucionId,
        public readonly int $habitacionId,
        public readonly int $auditorId,
        public readonly string $veredicto,
        public readonly ?string $comentario,
        /** @var list<int> */
        public readonly array $itemsDesmarcados,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        $items = [];
        $json = $fila['items_desmarcados_json'] ?? null;
        if (is_string($json) && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $items = array_values(array_map('intval', $decoded));
            }
        }
        return new self(
            id: (int) $fila['id'],
            ejecucionId: (int) $fila['ejecucion_id'],
            habitacionId: (int) $fila['habitacion_id'],
            auditorId: (int) $fila['auditor_id'],
            veredicto: (string) $fila['veredicto'],
            comentario: $fila['comentario'] !== null ? (string) $fila['comentario'] : null,
            itemsDesmarcados: $items,
            createdAt: (string) $fila['created_at'],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'ejecucion_id' => $this->ejecucionId,
            'habitacion_id' => $this->habitacionId,
            'auditor_id' => $this->auditorId,
            'veredicto' => $this->veredicto,
            'comentario' => $this->comentario,
            'items_desmarcados' => $this->itemsDesmarcados,
            'created_at' => $this->createdAt,
        ];
    }
}
