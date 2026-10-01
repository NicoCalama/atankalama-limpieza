<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;

/**
 * Columnas de la planilla «KPI ASEO» de RRHH (parte de los bonos de aseo) calculadas desde la app,
 * con las correcciones que confirmó RRHH el 01/10/2026. Ver docs/kpis-sueldos.md, sección
 * «Planilla KPI ASEO de RRHH».
 *
 * Único dato manual: el CORTE de habitaciones diarias de jornada completa (la base; parcial = la mitad).
 * Depende de la ocupación, así que se guarda POR MES; un mes sin valor propio hereda el del último mes
 * que lo tenga, y si no hay ninguno, CORTE_DEFAULT. Se guarda en alertas_config (mismo key-value que
 * las metas de Reportes), una clave por mes: «bono_corte_hab_dia_YYYY-MM».
 */
final class BonoAseoService
{
    public const CORTE_DEFAULT = 18.0;
    public const CORTE_MIN = 1.0;
    public const CORTE_MAX = 100.0;
    private const CLAVE_PREFIJO = 'bono_corte_hab_dia_';

    /**
     * Corte vigente para el mes y de dónde sale.
     *
     * @return array{valor: float, mes_origen: ?string, propio: bool}  mes_origen 'YYYY-MM' (null = default)
     */
    public function corte(int $anio, int $mes): array
    {
        $mesClave = sprintf('%04d-%02d', $anio, $mes);
        // Las claves llevan el mes con ceros a la izquierda: el orden de texto es el orden de fechas.
        $fila = Database::fetchOne(
            'SELECT clave, valor FROM #__alertas_config WHERE clave LIKE ? AND clave <= ? ORDER BY clave DESC LIMIT 1',
            [self::CLAVE_PREFIJO . '%', self::CLAVE_PREFIJO . $mesClave]
        );
        if ($fila === null) {
            return ['valor' => self::CORTE_DEFAULT, 'mes_origen' => null, 'propio' => false];
        }
        $origen = substr((string) $fila['clave'], strlen(self::CLAVE_PREFIJO));
        return ['valor' => (float) $fila['valor'], 'mes_origen' => $origen, 'propio' => $origen === $mesClave];
    }

    /**
     * Fija el corte de un mes. Hasta un decimal (p. ej. 15,5), entre CORTE_MIN y CORTE_MAX.
     *
     * @return array{valor: float, mes_origen: ?string, propio: bool}
     */
    public function guardarCorte(int $anio, int $mes, float $valor, int $usuarioId): array
    {
        if ($mes < 1 || $mes > 12 || $anio < 2000 || $anio > 2100) {
            throw new \InvalidArgumentException('Mes inválido.');
        }
        $valor = round($valor, 1);
        if ($valor < self::CORTE_MIN || $valor > self::CORTE_MAX) {
            throw new \InvalidArgumentException('El corte debe estar entre 1 y 100 habitaciones por día.');
        }
        $clave = self::CLAVE_PREFIJO . sprintf('%04d-%02d', $anio, $mes);
        $existe = Database::fetchOne('SELECT clave FROM #__alertas_config WHERE clave = ?', [$clave]);
        if ($existe === null) {
            Database::execute(
                'INSERT INTO #__alertas_config (clave, valor, descripcion, updated_by) VALUES (?, ?, ?, ?)',
                [$clave, (string) $valor, 'Corte de habitaciones diarias (jornada completa) del bono de aseo', $usuarioId]
            );
        } else {
            Database::execute(
                "UPDATE #__alertas_config SET valor = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), updated_by = ? WHERE clave = ?",
                [(string) $valor, $usuarioId, $clave]
            );
        }
        Logger::audit($usuarioId, 'reportes.corte_actualizar', 'alertas_config', null, ['clave' => $clave, 'valor' => $valor]);
        return $this->corte($anio, $mes);
    }

    /**
     * Fórmulas de la planilla de RRHH para UNA persona (columnas F, L, M, N, O, J, I), ya corregidas:
     *   base        = corte (jornada completa) | corte ÷ 2 (parcial)
     *   act_dia  F  = hab. hechas ÷ días trabajados
     *   eficacia L  = min(F ÷ base, 1)                      — con la base DE SU JORNADA (corrección RRHH)
     *   observ.  M  = observaciones ÷ hab. hechas           — tope 100 % (ver abajo)
     *   logro    N  = (1 − M) × L
     *   factor   O  = N ≤ 0,7 → 0,427·N ; N > 0,7 → 2,333·N − 1,333
     *   resultado J = N × O
     *   extras   I  = max(F − base, 0) × días = max(hab. hechas − base × días, 0)  — × días (corrección RRHH)
     *
     * Sin jornada definida no hay base: eficacia, logro, factor, resultado y extras quedan null (no se
     * inventa la jornada). Sin días o sin habitaciones hechas, lo que depende de ellos queda null (la
     * planilla deja la celda vacía).
     *
     * M con tope 100 %: las observaciones son casillas desmarcadas por el auditor (varias por pieza y
     * también en piezas rechazadas, que no están en «hab. hechas»), y se dividen por habitaciones como
     * en la planilla; sin tope, M podría pasar de 100 % y el logro saldría negativo.
     *
     * Porcentajes en 0–100 con un decimal; factor con tres decimales; extras con un decimal.
     *
     * @return array{base: ?float, act_dia: ?float, observadas_pct: ?float, eficacia_pct: ?float, logro_pct: ?float, factor_peso: ?float, resultado_pct: ?float, extras: ?float}
     */
    public static function calcular(int $habHechas, int $dias, ?string $jornada, int $observaciones, float $corte): array
    {
        $base = match ($jornada) {
            'completa' => $corte,
            'parcial'  => $corte / 2,
            default    => null,
        };
        $f = $dias > 0 ? $habHechas / $dias : null;
        $m = $habHechas > 0 ? min($observaciones / $habHechas, 1.0) : null;
        $l = ($f !== null && $base !== null) ? min($f / $base, 1.0) : null;
        $n = ($l !== null && $m !== null) ? (1 - $m) * $l : null;
        $o = $n === null ? null : ($n <= 0.7 ? 0.427 * $n : 2.333 * $n - 1.333);
        $j = ($n !== null && $o !== null) ? $n * $o : null;
        $i = ($f !== null && $base !== null) ? max($f - $base, 0.0) * $dias : null;

        $pct = static fn (?float $v): ?float => $v === null ? null : round($v * 100, 1);
        return [
            'base'           => $base,
            'act_dia'        => $f === null ? null : round($f, 1),
            'observadas_pct' => $pct($m),
            'eficacia_pct'   => $pct($l),
            'logro_pct'      => $pct($n),
            'factor_peso'    => $o === null ? null : round($o, 3),
            'resultado_pct'  => $pct($j),
            'extras'         => $i === null ? null : round($i, 1),
        ];
    }
}
