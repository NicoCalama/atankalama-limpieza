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
     *   (áreas comunes pasan por el mismo camino — ya no hay auto-cierre directo a aprobada;
     *   ver docs/areas-comunes.md)
     * - completada_pendiente_auditoria → aprobada | aprobada_con_observacion | aprobada_automatica | rechazada
     *   (aprobada_automatica = cierre de día 23:55, sin auditoría real; ver scripts/aprobar-pendientes-cierre-dia.php)
     * - rechazada / aprobada* → sucia (sync Cloudbeds en nuevo ciclo, o re-pedir limpieza de un espacio)
     *
     * ESTADO_APROBADA no aparece como destino directo de EN_PROGRESO: las únicas vías a "aprobada"
     * sin pasar por auditoría usan forzar:true, que se salta esta matriz a propósito —
     * ver HabitacionesController::marcarSinAseoCliente() y CloudbedsSyncService (línea ~201).
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
            Habitacion::ESTADO_APROBADA_AUTOMATICA,
            Habitacion::ESTADO_RECHAZADA,
        ],
        Habitacion::ESTADO_APROBADA => [Habitacion::ESTADO_SUCIA],
        Habitacion::ESTADO_APROBADA_CON_OBSERVACION => [Habitacion::ESTADO_SUCIA],
        Habitacion::ESTADO_APROBADA_AUTOMATICA => [Habitacion::ESTADO_SUCIA],
        // + EN_PROGRESO: el trabajador retoma la limpieza directo desde rechazada, sin pasar por
        // 'sucia' (ChecklistService::iniciarEjecucion() ya lo acepta — ver AuditoriaService.php,
        // comentario junto a la notificación de rechazo). Sin esto, aserciarTransicion() bloqueaba
        // esa transición con TRANSICION_INVALIDA, una HabitacionException que el controller no
        // atrapa y que el usuario veía como "Ocurrió un error inesperado.".
        Habitacion::ESTADO_RECHAZADA => [Habitacion::ESTADO_SUCIA, Habitacion::ESTADO_EN_PROGRESO],
    ];

    public function puedeTransicionar(string $actual, string $destino): bool
    {
        if (!in_array($actual, Habitacion::ESTADOS_VALIDOS, true)) {
            return false;
        }
        if (!in_array($destino, Habitacion::ESTADOS_VALIDOS, true)) {
            return false;
        }
        return in_array($destino, self::TRANSICIONES[$actual], true);
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
