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
 * Único dato manual: el CORTE (la base). Depende de la ocupación, así que se guarda POR MES; un mes sin
 * valor propio hereda el del último mes que lo tenga. Se guarda en alertas_config (mismo key-value que las
 * metas de Reportes), una clave por mes:
 *  - hasta octubre de 2026, habitaciones por DÍA de jornada completa (parcial = la mitad), clave
 *    «bono_corte_hab_dia_YYYY-MM», default CORTE_DEFAULT;
 *  - desde noviembre de 2026 (v7.18, POR_HORA_DESDE), habitaciones por HORA, la misma base para jornada
 *    completa y parcial, clave «bono_corte_hab_hora_YYYY-MM». Sin ninguno guardado se deriva del corte por
 *    día vigente ÷ HORAS_POR_DIA (18 → 2,40).
 */
final class BonoAseoService
{
    public const CORTE_DEFAULT = 18.0;
    public const CORTE_MIN = 1.0;
    public const CORTE_MAX = 100.0;
    private const CLAVE_PREFIJO = 'bono_corte_hab_dia_';

    /** Primer mes que se mide por hora (decisión de Nicolás, 07/11/2026). Los anteriores quedan como se calcularon. */
    public const POR_HORA_DESDE = '2026-11';
    /** Rango angosto a propósito: escribir 18 por costumbre tiene que dar error, no dejar a todas en 13 %. */
    public const CORTE_HORA_MIN = 0.5;
    public const CORTE_HORA_MAX = 6.0;
    /** Horas de un día de jornada completa (08–16 menos la colación): para pasar un corte por día a uno por hora. */
    public const HORAS_POR_DIA = 7.5;
    private const CLAVE_PREFIJO_HORA = 'bono_corte_hab_hora_';

    public static function esPorHora(int $anio, int $mes): bool
    {
        return sprintf('%04d-%02d', $anio, $mes) >= self::POR_HORA_DESDE;
    }

    /** Un período se mide por hora si empieza desde POR_HORA_DESDE; si empieza antes, sigue por día. */
    public static function periodoPorHora(string $desde): bool
    {
        return substr($desde, 0, 7) >= self::POR_HORA_DESDE;
    }

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
     * El corte que se aplica a un mes, en la unidad de ese mes. Hasta octubre es corte(), sin cambios;
     * desde noviembre agrega la unidad y si el valor salió del corte por día (derivado).
     *
     * @return array{valor: float, mes_origen: ?string, propio: bool, unidad?: string, derivado?: bool}
     */
    public function corteDelMes(int $anio, int $mes): array
    {
        if (!self::esPorHora($anio, $mes)) {
            return $this->corte($anio, $mes);
        }
        $mesClave = sprintf('%04d-%02d', $anio, $mes);
        $fila = Database::fetchOne(
            'SELECT clave, valor FROM #__alertas_config WHERE clave LIKE ? AND clave <= ? ORDER BY clave DESC LIMIT 1',
            [self::CLAVE_PREFIJO_HORA . '%', self::CLAVE_PREFIJO_HORA . $mesClave]
        );
        if ($fila === null) {
            return [
                'valor'      => round($this->corte($anio, $mes)['valor'] / self::HORAS_POR_DIA, 2),
                'mes_origen' => null,
                'propio'     => false,
                'unidad'     => 'hab_hora',
                'derivado'   => true,
            ];
        }
        $origen = substr((string) $fila['clave'], strlen(self::CLAVE_PREFIJO_HORA));
        return [
            'valor'      => (float) $fila['valor'],
            'mes_origen' => $origen,
            'propio'     => $origen === $mesClave,
            'unidad'     => 'hab_hora',
            'derivado'   => false,
        ];
    }

    /**
     * Fija el corte de un mes, en la unidad de ese mes: por día con hasta un decimal (p. ej. 15,5), entre
     * CORTE_MIN y CORTE_MAX; desde noviembre de 2026, por hora con hasta dos decimales (p. ej. 2,4), entre
     * CORTE_HORA_MIN y CORTE_HORA_MAX.
     *
     * @return array{valor: float, mes_origen: ?string, propio: bool, unidad?: string, derivado?: bool}
     */
    public function guardarCorte(int $anio, int $mes, float $valor, int $usuarioId): array
    {
        if ($mes < 1 || $mes > 12 || $anio < 2000 || $anio > 2100) {
            throw new \InvalidArgumentException('Mes inválido.');
        }
        $mesClave = sprintf('%04d-%02d', $anio, $mes);
        if (self::esPorHora($anio, $mes)) {
            $valor = round($valor, 2);
            if ($valor < self::CORTE_HORA_MIN || $valor > self::CORTE_HORA_MAX) {
                throw new \InvalidArgumentException('Este corte va por hora: escribe un número entre 0,5 y 6, por ejemplo 2,4.');
            }
            $this->escribirCorte(self::CLAVE_PREFIJO_HORA . $mesClave, $valor, 'Corte de habitaciones por hora del bono de aseo', $usuarioId);
            return $this->corteDelMes($anio, $mes);
        }
        $valor = round($valor, 1);
        if ($valor < self::CORTE_MIN || $valor > self::CORTE_MAX) {
            throw new \InvalidArgumentException('El corte debe estar entre 1 y 100 habitaciones por día.');
        }
        $this->escribirCorte(self::CLAVE_PREFIJO . $mesClave, $valor, 'Corte de habitaciones diarias (jornada completa) del bono de aseo', $usuarioId);
        return $this->corte($anio, $mes);
    }

    private function escribirCorte(string $clave, float $valor, string $descripcion, int $usuarioId): void
    {
        $existe = Database::fetchOne('SELECT clave FROM #__alertas_config WHERE clave = ?', [$clave]);
        if ($existe === null) {
            Database::execute(
                'INSERT INTO #__alertas_config (clave, valor, descripcion, updated_by) VALUES (?, ?, ?, ?)',
                [$clave, (string) $valor, $descripcion, $usuarioId]
            );
        } else {
            Database::execute(
                "UPDATE #__alertas_config SET valor = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), updated_by = ? WHERE clave = ?",
                [(string) $valor, $usuarioId, $clave]
            );
        }
        Logger::audit($usuarioId, 'reportes.corte_actualizar', 'alertas_config', null, ['clave' => $clave, 'valor' => $valor]);
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
        $o = $n === null ? null : self::factorPeso($n);
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

    /**
     * Las mismas fórmulas por HORA, desde noviembre de 2026 (v7.18; decisiones de Nicolás del 07/11/2026):
     *   base        = corte por hora, la misma para jornada completa y parcial
     *   act_hora F  = hab. hechas ÷ horas en Aseo
     *   eficacia L  = min(F ÷ base, 1)
     *   observ.  M, logro N, factor O y resultado J: igual que calcular()
     *   extras   I  = max(hab. hechas − base × horas, 0)
     *
     * $minutosAseo null o sin horas (HorasAseoService: sin jornada o sin horas): F, L, N, O, J e I quedan
     * null, no se inventan. El observadas M se calcula igual.
     *
     * @return array{base: float, act_hora: ?float, observadas_pct: ?float, eficacia_pct: ?float, logro_pct: ?float, factor_peso: ?float, resultado_pct: ?float, extras: ?float}
     */
    public static function calcularPorHora(int $habHechas, ?int $minutosAseo, int $observaciones, float $corte): array
    {
        $horas = ($minutosAseo !== null && $minutosAseo > 0) ? $minutosAseo / 60 : null;
        $f = $horas !== null ? $habHechas / $horas : null;
        $m = $habHechas > 0 ? min($observaciones / $habHechas, 1.0) : null;
        $l = $f !== null ? min($f / $corte, 1.0) : null;
        $n = ($l !== null && $m !== null) ? (1 - $m) * $l : null;
        $o = $n === null ? null : self::factorPeso($n);
        $j = ($n !== null && $o !== null) ? $n * $o : null;
        $i = $horas !== null ? max($habHechas - $corte * $horas, 0.0) : null;

        $pct = static fn (?float $v): ?float => $v === null ? null : round($v * 100, 1);
        return [
            'base'           => $corte,
            'act_hora'       => $f === null ? null : round($f, 2),
            'observadas_pct' => $pct($m),
            'eficacia_pct'   => $pct($l),
            'logro_pct'      => $pct($n),
            'factor_peso'    => $o === null ? null : round($o, 3),
            'resultado_pct'  => $pct($j),
            'extras'         => $i === null ? null : round($i, 1),
        ];
    }

    private static function factorPeso(float $logro): float
    {
        return $logro <= 0.7 ? 0.427 * $logro : 2.333 * $logro - 1.333;
    }
}
