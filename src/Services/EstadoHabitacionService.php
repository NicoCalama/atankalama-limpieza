<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Models\Habitacion;

final class EstadoHabitacionService
{
    /**
     * Matriz de transiciones permitidas.
     * Clave = estado actual, valor = lista de estados destino válidos.
     *
     * Reglas (ver docs/habitaciones.md §3):
     * - sucia → en_progreso
     * - en_progreso → completada_pendiente_auditoria | sucia (reset excepcional por supervisora)
     * - completada_pendiente_auditoria → aprobada | aprobada_con_observacion | rechazada
     * - rechazada / aprobada* → sucia (solo por sync Cloudbeds en nuevo ciclo)
     */
    private const TRANSICIONES = [
        Habitacion::ESTADO_SUCIA => [
            Habitacion::ESTADO_EN_PROGRESO,
        ],
        Habitacion::ESTADO_EN_PROGRESO => [
            Habitacion::ESTADO_COMPLETADA_PENDIENTE_AUDITORIA,
            Habitacion::ESTADO_SUCIA,
        ],
        Habitacion::ESTADO_COMPLETADA_PENDIENTE_AUDITORIA => [
            Habitacion::ESTADO_APROBADA,
            Habitacion::ESTADO_APROBADA_CON_OBSERVACION,
            Habitacion::ESTADO_RECHAZADA,
        ],
        Habitacion::ESTADO_APROBADA => [Habitacion::ESTADO_SUCIA],
        Habitacion::ESTADO_APROBADA_CON_OBSERVACION => [Habitacion::ESTADO_SUCIA],
        Habitacion::ESTADO_RECHAZADA => [Habitacion::ESTADO_SUCIA],
    ];

    public function puedeTransicionar(string $actual, string $destino): bool
    {
        if (!in_array($actual, Habitacion::ESTADOS_VALIDOS, true)) {
            return false;
        }
        if (!in_array($destino, Habitacion::ESTADOS_VALIDOS, true)) {
            return false;
        }
        return in_array($destino, self::TRANSICIONES[$actual] ?? [], true);
    }

    public function aserciarTransicion(string $actual, string $destino): void
    {
        if (!$this->puedeTransicionar($actual, $destino)) {
            throw new HabitacionException(
                'TRANSICION_INVALIDA',
                "No se puede pasar de '{$actual}' a '{$destino}'.",
                409
            );
        }
    }
}
