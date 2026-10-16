<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

/**
 * Regla de cambio de sábanas. Ver docs/ocupacion-y-sabanas.md
 *
 * Cloudbeds no expone su regla: las fechas y la ocupación del día salen del sync
 * (getHousekeepingStatus) y la regla la aplica la app. Toca cambio de sábanas cuando:
 *  - el huésped sigue (stayover) y hoy cumple N, 2N, 3N… noches desde la llegada (la cadencia N
 *    la configura cada hotel en hoteles.sabanas_cada_n_dias), o
 *  - el huésped se va hoy (check-out), o se va uno y llega otro el mismo día (turnover). Desde la
 *    v7.7 (decisión de Nicolás, 15/10/2026); antes la salida no avisaba.
 * «Llega hoy» (check-in) y la pieza libre (unused) no: la cama ya se hizo con sábanas limpias en la
 * limpieza de salida del huésped anterior.
 *
 * No se rastrea el cambio real: si el aviso del día N se pasa por alto, no reaparece hasta 2N.
 */
final class SabanasService
{
    /**
     * ¿Toca cambio de sábanas hoy? Es la regla del aviso «Sábanas hoy» de las tarjetas.
     *
     * Huésped que sigue: cadencia desde la llegada, toca los días N, 2N, 3N… (noches % N == 0, con
     * noches > 0). Salida o turnover: siempre.
     */
    public function tocaCambioSabanas(
        ?string $frontdeskStatus,
        ?string $arrivalDate,
        int $nDias,
        string $hoy,
    ): bool {
        if ($frontdeskStatus === 'check-out' || $frontdeskStatus === 'turnover') {
            return true;
        }
        if ($frontdeskStatus !== 'stayover' || $arrivalDate === null || $arrivalDate === '') {
            return false;
        }
        $n = max(1, $nDias);
        $noches = $this->diasEntre($arrivalDate, $hoy);
        return $noches > 0 && $noches % $n === 0;
    }

    /**
     * ¿La limpieza que empieza ahora lleva cambio de sábanas? Decide qué ítems del checklist ve la
     * trabajadora (los marcados «Sábanas» se ocultan si no lleva). v7.7, ver docs/checklist.md.
     *
     * Misma regla que el aviso, pero ante la duda SÍ lleva (es lo que pasaba antes de la v7.7): sin
     * ocupación de Cloudbeds, con una lectura que no es de hoy (el sync no anduvo y la ocupación
     * puede ser la de ayer) o con un huésped que sigue sin fecha de llegada.
     *
     * @param array<string, mixed> $fila cb_frontdesk_status, cb_arrival_date, cb_ocupacion_sync_at
     *                                   y sabanas_cada_n_dias de la habitación y su hotel
     */
    public function llevaCambioSabanas(array $fila, string $hoy): bool
    {
        $frontdesk = $this->texto($fila['cb_frontdesk_status'] ?? null);
        $arrival   = $this->texto($fila['cb_arrival_date'] ?? null);
        $leidaEl   = $this->fechaLocal($this->texto($fila['cb_ocupacion_sync_at'] ?? null));
        if ($frontdesk === null || $leidaEl !== $hoy) {
            return true;
        }
        if ($frontdesk === 'stayover' && $arrival === null) {
            return true;
        }
        return $this->tocaCambioSabanas($frontdesk, $arrival, (int) ($fila['sabanas_cada_n_dias'] ?? 4), $hoy);
    }

    /**
     * Anota una fila de habitación con `noches_estadia` + `toca_sabanas` a partir de sus columnas
     * `cb_frontdesk_status`, `cb_arrival_date` y `sabanas_cada_n_dias`. Si la fila no trae esas
     * columnas, quedan en null/false.
     *
     * @param array<string, mixed> $fila
     * @return array<string, mixed>
     */
    public function anotarFila(array $fila, ?string $hoy = null): array
    {
        $hoy ??= date('Y-m-d');
        $arrival   = $this->texto($fila['cb_arrival_date'] ?? null);
        $frontdesk = $this->texto($fila['cb_frontdesk_status'] ?? null);
        $n         = (int) ($fila['sabanas_cada_n_dias'] ?? 4);

        $fila['noches_estadia'] = $this->nochesEstadia($arrival, $hoy);
        // v7.8: la primera limpieza después de una mantención o de cerrada también lleva sábanas.
        $fila['toca_sabanas'] = ((int) ($fila['limpieza_completa_pendiente'] ?? 0)) === 1
            || $this->tocaCambioSabanas($frontdesk, $arrival, $n, $hoy);
        return $fila;
    }

    /** Noches de estadía del huésped actual (hoy − arrival), o null si no hay arrival. */
    public function nochesEstadia(?string $arrivalDate, string $hoy): ?int
    {
        if ($arrivalDate === null || $arrivalDate === '') {
            return null;
        }
        return max(0, $this->diasEntre($arrivalDate, $hoy));
    }

    private function diasEntre(string $desde, string $hasta): int
    {
        // En UTC: en America/Santiago el día del cambio de horario de septiembre dura 23 h, y
        // con la hora local toda estadía que lo cruzaba contaba una noche menos (el aviso salía
        // un día tarde durante toda la estadía).
        $d1 = strtotime($desde . ' 00:00:00 UTC');
        $d2 = strtotime($hasta . ' 00:00:00 UTC');
        if ($d1 === false || $d2 === false) {
            return 0;
        }
        return (int) floor(($d2 - $d1) / 86400);
    }

    private function texto(mixed $valor): ?string
    {
        return $valor === null || $valor === '' ? null : (string) $valor;
    }

    /** Día local (zona de la app) de un instante ISO en UTC, como lo guarda la base; null si no se entiende. */
    private function fechaLocal(?string $instanteUtc): ?string
    {
        if ($instanteUtc === null) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($instanteUtc))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }
}
