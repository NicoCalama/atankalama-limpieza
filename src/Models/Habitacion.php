<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Models;

final class Habitacion
{
    public const ESTADO_SUCIA = 'sucia';
    public const ESTADO_EN_PROGRESO = 'en_progreso';
    public const ESTADO_COMPLETADA_PENDIENTE_AUDITORIA = 'completada_pendiente_auditoria';
    public const ESTADO_APROBADA = 'aprobada';
    public const ESTADO_APROBADA_CON_OBSERVACION = 'aprobada_con_observacion';
    public const ESTADO_RECHAZADA = 'rechazada';

    public const ESTADOS_VALIDOS = [
        self::ESTADO_SUCIA,
        self::ESTADO_EN_PROGRESO,
        self::ESTADO_COMPLETADA_PENDIENTE_AUDITORIA,
        self::ESTADO_APROBADA,
        self::ESTADO_APROBADA_CON_OBSERVACION,
        self::ESTADO_RECHAZADA,
    ];

    public function __construct(
        public readonly int $id,
        public readonly int $hotelId,
        public readonly string $numero,
        public readonly int $tipoHabitacionId,
        public readonly ?string $cloudbedsRoomId,
        public readonly string $estado,
        public readonly bool $activa,
    ) {
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id: (int) $fila['id'],
            hotelId: (int) $fila['hotel_id'],
            numero: (string) $fila['numero'],
            tipoHabitacionId: (int) $fila['tipo_habitacion_id'],
            cloudbedsRoomId: $fila['cloudbeds_room_id'] !== null ? (string) $fila['cloudbeds_room_id'] : null,
            estado: (string) $fila['estado'],
            activa: ((int) $fila['activa']) === 1,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'hotel_id' => $this->hotelId,
            'numero' => $this->numero,
            'tipo_habitacion_id' => $this->tipoHabitacionId,
            'cloudbeds_room_id' => $this->cloudbedsRoomId,
            'estado' => $this->estado,
            'activa' => $this->activa,
        ];
    }

    public function estaEnEstadoTerminal(): bool
    {
        return in_array($this->estado, [
            self::ESTADO_APROBADA,
            self::ESTADO_APROBADA_CON_OBSERVACION,
            self::ESTADO_RECHAZADA,
        ], true);
    }
}
