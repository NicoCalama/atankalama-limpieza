<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Models;

final class TicketAdjunto
{
    public const CONTEXTO_CREACION = 'creacion';
    public const CONTEXTO_CIERRE = 'cierre';
    public const CONTEXTOS_VALIDOS = [self::CONTEXTO_CREACION, self::CONTEXTO_CIERRE];

    public function __construct(
        public readonly int $id,
        public readonly int $ticketId,
        public readonly string $ruta,
        public readonly ?string $nombreOriginal,
        public readonly int $tamanoBytes,
        public readonly string $contexto,
        public readonly int $subidoPor,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id: (int) $fila['id'],
            ticketId: (int) $fila['ticket_id'],
            ruta: (string) $fila['ruta'],
            nombreOriginal: $fila['nombre_original'] !== null ? (string) $fila['nombre_original'] : null,
            tamanoBytes: (int) $fila['tamano_bytes'],
            contexto: (string) $fila['contexto'],
            subidoPor: (int) $fila['subido_por'],
            createdAt: (string) $fila['created_at'],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticketId,
            'ruta' => $this->ruta,
            'nombre_original' => $this->nombreOriginal,
            'tamano_bytes' => $this->tamanoBytes,
            'contexto' => $this->contexto,
            'subido_por' => $this->subidoPor,
            'created_at' => $this->createdAt,
        ];
    }
}
