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
    // Cierre de día automático (cron 23:55): habitación que quedó completada_pendiente_auditoria
    // sin que un supervisor la auditara a tiempo. Ver scripts/aprobar-pendientes-cierre-dia.php.
    public const ESTADO_APROBADA_AUTOMATICA = 'aprobada_automatica';
    public const ESTADO_RECHAZADA = 'rechazada';

    public const ESTADOS_VALIDOS = [
        self::ESTADO_SUCIA,
        self::ESTADO_EN_PROGRESO,
        self::ESTADO_COMPLETADA_PENDIENTE_AUDITORIA,
        self::ESTADO_APROBADA,
        self::ESTADO_APROBADA_CON_OBSERVACION,
        self::ESTADO_APROBADA_AUTOMATICA,
        self::ESTADO_RECHAZADA,
    ];

    public function __construct(
        public readonly int $id,
        public readonly int $hotelId,
        public readonly string $numero,
        public readonly ?string $edificio,
        public readonly ?int $piso,
        public readonly int $tipoHabitacionId,
        public readonly ?string $cloudbedsRoomId,
        public readonly ?string $cloudbedsRoomName,
        public readonly string $estado,
        public readonly bool $activa,
        public readonly bool $esEspacioComun = false,
        public readonly bool $esNochero = false,
        public readonly ?string $nocheroHasta = null,
        public readonly ?string $notaRecepcion = null,
        public readonly ?int $notaRecepcionAutorId = null,
        public readonly ?string $notaRecepcionAt = null,
    ) {
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id: (int) $fila['id'],
            hotelId: (int) $fila['hotel_id'],
            numero: (string) $fila['numero'],
            edificio: isset($fila['edificio']) ? (string) $fila['edificio'] : null,
            piso: isset($fila['piso']) ? (int) $fila['piso'] : null,
            tipoHabitacionId: (int) $fila['tipo_habitacion_id'],
            cloudbedsRoomId: $fila['cloudbeds_room_id'] !== null ? (string) $fila['cloudbeds_room_id'] : null,
            cloudbedsRoomName: isset($fila['cloudbeds_room_name']) ? (string) $fila['cloudbeds_room_name'] : null,
            estado: (string) $fila['estado'],
            activa: ((int) $fila['activa']) === 1,
            esEspacioComun: ((int) ($fila['es_espacio_comun'] ?? 0)) === 1,
            esNochero: ((int) ($fila['es_nochero'] ?? 0)) === 1,
            nocheroHasta: isset($fila['nochero_hasta']) ? (string) $fila['nochero_hasta'] : null,
            notaRecepcion: isset($fila['nota_recepcion']) ? (string) $fila['nota_recepcion'] : null,
            notaRecepcionAutorId: isset($fila['nota_recepcion_autor_id']) ? (int) $fila['nota_recepcion_autor_id'] : null,
            notaRecepcionAt: isset($fila['nota_recepcion_at']) ? (string) $fila['nota_recepcion_at'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'hotel_id' => $this->hotelId,
            'numero' => $this->numero,
            'edificio' => $this->edificio,
            'piso' => $this->piso,
            'tipo_habitacion_id' => $this->tipoHabitacionId,
            'cloudbeds_room_id' => $this->cloudbedsRoomId,
            'cloudbeds_room_name' => $this->cloudbedsRoomName,
            'estado' => $this->estado,
            'activa' => $this->activa,
            'es_espacio_comun' => $this->esEspacioComun,
            'es_nochero' => $this->esNochero,
            'nochero_hasta' => $this->nocheroHasta,
            'nota_recepcion' => $this->notaRecepcion,
            'nota_recepcion_autor_id' => $this->notaRecepcionAutorId,
            'nota_recepcion_at' => $this->notaRecepcionAt,
        ];
    }

    public function estaEnEstadoTerminal(): bool
    {
        return in_array($this->estado, [
            self::ESTADO_APROBADA,
            self::ESTADO_APROBADA_CON_OBSERVACION,
            self::ESTADO_APROBADA_AUTOMATICA,
            self::ESTADO_RECHAZADA,
        ], true);
    }
}
