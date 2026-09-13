<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Models;

final class TicketComentario
{
    public function __construct(
        public readonly int $id,
        public readonly int $ticketId,
        public readonly int $usuarioId,
        public readonly string $comentario,
        public readonly bool $avisado,
        public readonly string $createdAt,
        public readonly ?string $usuarioNombre = null,
    ) {
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id: (int) $fila['id'],
            ticketId: (int) $fila['ticket_id'],
            usuarioId: (int) $fila['usuario_id'],
            comentario: (string) $fila['comentario'],
            avisado: (bool) $fila['avisado'],
            createdAt: (string) $fila['created_at'],
            usuarioNombre: isset($fila['usuario_nombre']) ? (string) $fila['usuario_nombre'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticketId,
            'usuario_id' => $this->usuarioId,
            'comentario' => $this->comentario,
            'avisado' => $this->avisado,
            'created_at' => $this->createdAt,
            'usuario_nombre' => $this->usuarioNombre,
        ];
    }
}
