<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

/**
 * Regla de cambio de sábanas para huéspedes que siguen (stayover). Ver docs/ocupacion-y-sabanas.md
 *
 * La cadencia (N noches) la configura cada hotel (hoteles.sabanas_cada_n_dias); Cloudbeds no expone
 * su regla, la replicamos acá. Las fechas de ocupación salen del sync (getHousekeepingStatus).
 *
 * Modelo MVP: cadencia desde la llegada. El aviso aparece los días N, 2N, 3N… de estadía
 * (noches % N == 0), literal a "cada N días avisa". No se rastrea el cambio real (arrival-based);
 * refinarlo (rastrear el último cambio, ítem de sábanas opcional) es una mejora futura que ripplearía
 * en los créditos (conteo de obligatorios).
 */
final class SabanasService
{
    /**
     * ¿Toca cambio de sábanas hoy? Solo aplica a piezas en 'stayover' (el huésped sigue): en
     * check-in/check-out/turnover el aseo ya trae sábanas frescas (huésped nuevo), no es "aviso".
     *
     * Cadencia desde la llegada: toca los días N, 2N, 3N… (noches % N == 0, con noches > 0).
     */
    public function tocaCambioSabanas(
        ?string $frontdeskStatus,
        ?string $arrivalDate,
        int $nDias,
        string $hoy,
    ): bool {
        if ($frontdeskStatus !== 'stayover' || $arrivalDate === null || $arrivalDate === '') {
            return false;
        }
        $n = max(1, $nDias);
        $noches = $this->diasEntre($arrivalDate, $hoy);
        return $noches > 0 && $noches % $n === 0;
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
        $arrival   = isset($fila['cb_arrival_date']) && $fila['cb_arrival_date'] !== '' ? (string) $fila['cb_arrival_date'] : null;
        $frontdesk = isset($fila['cb_frontdesk_status']) && $fila['cb_frontdesk_status'] !== '' ? (string) $fila['cb_frontdesk_status'] : null;
        $n         = (int) ($fila['sabanas_cada_n_dias'] ?? 4);

        $fila['noches_estadia'] = $this->nochesEstadia($arrival, $hoy);
        $fila['toca_sabanas'] = $this->tocaCambioSabanas($frontdesk, $arrival, $n, $hoy);
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
        $d1 = strtotime($desde . ' 00:00:00');
        $d2 = strtotime($hasta . ' 00:00:00');
        if ($d1 === false || $d2 === false) {
            return 0;
        }
        return (int) floor(($d2 - $d1) / 86400);
    }
}
