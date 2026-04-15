<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Models;

final class AlertaActiva
{
    public const TIPO_CLOUDBEDS_SYNC_FAILED = 'cloudbeds_sync_failed';
    public const TIPO_TRABAJADOR_EN_RIESGO = 'trabajador_en_riesgo';
    public const TIPO_HABITACION_RECHAZADA = 'habitacion_rechazada';
    public const TIPO_FIN_TURNO_PENDIENTES = 'fin_turno_pendientes';
    public const TIPO_TRABAJADOR_DISPONIBLE = 'trabajador_disponible';
    public const TIPO_TICKET_NUEVO = 'ticket_nuevo';

    public const TIPOS_VALIDOS = [
        self::TIPO_CLOUDBEDS_SYNC_FAILED,
        self::TIPO_TRABAJADOR_EN_RIESGO,
        self::TIPO_HABITACION_RECHAZADA,
        self::TIPO_FIN_TURNO_PENDIENTES,
        self::TIPO_TRABAJADOR_DISPONIBLE,
        self::TIPO_TICKET_NUEVO,
    ];

    public const PRIORIDAD_POR_TIPO = [
        self::TIPO_CLOUDBEDS_SYNC_FAILED => 0,
        self::TIPO_TRABAJADOR_EN_RIESGO => 1,
        self::TIPO_HABITACION_RECHAZADA => 1,
        self::TIPO_FIN_TURNO_PENDIENTES => 1,
        self::TIPO_TRABAJADOR_DISPONIBLE => 2,
        self::TIPO_TICKET_NUEVO => 2,
    ];

    public function __construct(
        public readonly int $id,
        public readonly string $tipo,
        public readonly int $prioridad,
        public readonly string $titulo,
        public readonly string $descripcion,
        /** @var array<string, mixed> */
        public readonly array $contexto,
        public readonly ?int $hotelId,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        $contexto = [];
        $json = $fila['contexto_json'] ?? null;
        if (is_string($json) && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $contexto = $decoded;
            }
        }
        return new self(
            id: (int) $fila['id'],
            tipo: (string) $fila['tipo'],
            prioridad: (int) $fila['prioridad'],
            titulo: (string) $fila['titulo'],
            descripcion: (string) $fila['descripcion'],
            contexto: $contexto,
            hotelId: isset($fila['hotel_id']) && $fila['hotel_id'] !== null ? (int) $fila['hotel_id'] : null,
            createdAt: (string) $fila['created_at'],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'prioridad' => $this->prioridad,
            'titulo' => $this->titulo,
            'descripcion' => $this->descripcion,
            'contexto' => $this->contexto,
            'hotel_id' => $this->hotelId,
            'created_at' => $this->createdAt,
        ];
    }
}
