<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Helpers\HorasTurno;

/**
 * Horas en Aseo de cada persona en un período (v7.18 «KPIs por hora»; decisiones de Nicolás del 07 y
 * 08/11/2026). Son el denominador del bono y de la Productividad desde noviembre de 2026.
 *
 *   horas en Aseo = horas de turno de los días trabajados + extras del mes − horas en otras áreas
 *
 *  - Los días trabajados los decide ReportesService::fichaTrabajadores (también por hotel); aquí solo llegan.
 *  - Las horas de un día son las de su turno (HorasTurno: jornada completa sin los 30 min de colación). Un
 *    día trabajado sin turno cargado vale siempre la jornada estándar de la persona (MINUTOS_ESTANDAR;
 *    decisión de Nicolás, 08/11/2026): si limpió, vino, y el personal se divide entre completa y parcial.
 *  - Las extras son un total por persona y mes: solo suman los meses que el período cubre completos. Son
 *    de la persona y no de un hotel, así que van enteras también con un hotel filtrado.
 *  - Las horas en otras áreas se descuentan solo de los días que entran en la fila. El descuento es sobre
 *    el total y no por día: las que pasan del turno de ese día se compensan con las extras. Anotar
 *    exactamente el largo del turno (8 h en un 08–16) significa «todo el día»: descuenta sus horas de
 *    trabajo (7,5 h), porque la colación no se trabaja en ningún lado (decisión de Nicolás, 08/11/2026).
 *
 * Sin jornada no se sabe la colación ni la jornada estándar, y un total de 0 o menos no permite dividir. En
 * esos casos el total queda null con su estado, y el bono muestra «—».
 */
final class HorasAseoService
{
    public const ESTADO_OK = 'ok';
    public const ESTADO_SIN_JORNADA = 'sin_jornada';
    public const ESTADO_SIN_HORAS = 'sin_horas';

    /** Minutos de un día sin turno cargado: 7,5 h en jornada completa y la mitad en parcial, como el corte por día. */
    public const MINUTOS_ESTANDAR = ['completa' => 450, 'parcial' => 225];

    /**
     * @param array<int, list<string>> $fechasPorUsuario uid → días trabajados ('YYYY-MM-DD')
     * @param array<int, ?string>      $jornadas         uid → completa | parcial | null
     * @return array<int, array{turno: float, extras: float, otras_areas: float, aseo: ?float, minutos_aseo: ?int, dias_estimados: int, estado: string}>
     */
    public function calcular(array $fechasPorUsuario, array $jornadas, string $desde, string $hasta): array
    {
        $ids = array_keys($fechasPorUsuario);
        if ($ids === []) {
            return [];
        }

        $porDia = $this->turnos($ids, $jornadas, $desde, $hasta);
        $otras  = $this->otrasAreas($ids, $desde, $hasta);
        $extras = $this->extras($ids, self::mesesCubiertos($desde, $hasta));

        $horas = static fn (int $minutos): float => round($minutos / 60, 2);
        $salida = [];
        foreach ($fechasPorUsuario as $uid => $fechas) {
            $jornada = $jornadas[$uid] ?? null;
            $estandar = $jornada === null ? null : (self::MINUTOS_ESTANDAR[$jornada] ?? null);
            $minTurno = 0;
            $minOtras = 0;
            $estimados = 0;
            foreach ($fechas as $fecha) {
                $turno = $porDia[$uid][$fecha] ?? null;
                if ($turno !== null && $turno['netos'] > 0) {
                    ['netos' => $netos, 'bruto' => $bruto] = $turno;
                } else {
                    $netos = $estandar ?? 0;
                    $bruto = $jornada === 'completa' ? $netos + HorasTurno::COLACION_COMPLETA_MIN : $netos;
                    $estimados++;
                }
                $minTurno += $netos;
                $anotado = $otras[$uid][$fecha] ?? 0;
                $minOtras += $anotado === $bruto ? $netos : $anotado;
            }
            $minExtras = $extras[$uid] ?? 0;
            $total = $minTurno + $minExtras - $minOtras;

            $estado = match (true) {
                $estandar === null => self::ESTADO_SIN_JORNADA,
                $total <= 0        => self::ESTADO_SIN_HORAS,
                default            => self::ESTADO_OK,
            };
            $ok = $estado === self::ESTADO_OK;
            $salida[$uid] = [
                'turno'          => $horas($minTurno),
                'extras'         => $horas($minExtras),
                'otras_areas'    => $horas($minOtras),
                'aseo'           => $ok ? $horas($total) : null,
                'minutos_aseo'   => $ok ? $total : null,
                'dias_estimados' => $estimados,
                'estado'         => $estado,
            ];
        }
        return $salida;
    }

    /**
     * Meses calendario que el período cubre enteros (desde ≤ día 1 y hasta ≥ último día), sin mirar la
     * fecha de hoy: a mitad de mes, «del 1 a hoy» todavía no lo cubre.
     *
     * @return list<string> 'YYYY-MM'
     */
    public static function mesesCubiertos(string $desde, string $hasta): array
    {
        $meses = [];
        $mes = new \DateTimeImmutable(substr($desde, 0, 7) . '-01');
        $ultimo = substr($hasta, 0, 7);
        while ($mes->format('Y-m') <= $ultimo) {
            if ($desde <= $mes->format('Y-m-01') && $hasta >= $mes->format('Y-m-t')) {
                $meses[] = $mes->format('Y-m');
            }
            $mes = $mes->modify('first day of next month');
        }
        return $meses;
    }

    /** Texto para el contexto de los KPIs cuando el período deja extras afuera; null si las incluye todas. */
    public static function avisoExtras(string $desde, string $hasta): ?string
    {
        $cubiertos = count(self::mesesCubiertos($desde, $hasta));
        $tocados = (((int) substr($hasta, 0, 4) - (int) substr($desde, 0, 4)) * 12)
            + (int) substr($hasta, 5, 2) - (int) substr($desde, 5, 2) + 1;
        return match (true) {
            $cubiertos === $tocados => null,
            $cubiertos === 0        => 'sin extras del mes',
            default                 => 'sin extras de los meses incompletos',
        };
    }

    /**
     * Minutos del turno de cada persona y día del período: los de trabajo (netos) y el largo total (bruto).
     *
     * @param list<int>           $ids
     * @param array<int, ?string> $jornadas
     * @return array<int, array<string, array{netos: int, bruto: int}>> uid → fecha → minutos
     */
    private function turnos(array $ids, array $jornadas, string $desde, string $hasta): array
    {
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $porDia = [];
        foreach (Database::fetchAll(
            "SELECT ut.usuario_id, ut.fecha, t.hora_inicio, t.hora_fin
               FROM #__usuarios_turnos ut
               JOIN #__turnos t ON t.id = ut.turno_id
              WHERE ut.fecha BETWEEN ? AND ? AND ut.usuario_id IN ({$in})",
            array_merge([$desde, $hasta], $ids)
        ) as $f) {
            $uid = (int) $f['usuario_id'];
            $porDia[$uid][(string) $f['fecha']] = [
                'netos' => HorasTurno::minutosNetos((string) $f['hora_inicio'], (string) $f['hora_fin'], $jornadas[$uid] ?? null),
                'bruto' => HorasTurno::minutosEntre((string) $f['hora_inicio'], (string) $f['hora_fin']),
            ];
        }
        return $porDia;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, int>> uid → fecha → minutos en otras áreas
     */
    private function otrasAreas(array $ids, string $desde, string $hasta): array
    {
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $salida = [];
        foreach (Database::fetchAll(
            "SELECT usuario_id, fecha, SUM(minutos) AS minutos
               FROM #__horas_otras_areas
              WHERE fecha BETWEEN ? AND ? AND usuario_id IN ({$in})
              GROUP BY usuario_id, fecha",
            array_merge([$desde, $hasta], $ids)
        ) as $f) {
            $salida[(int) $f['usuario_id']][(string) $f['fecha']] = (int) $f['minutos'];
        }
        return $salida;
    }

    /**
     * @param list<int>    $ids
     * @param list<string> $meses
     * @return array<int, int> uid → minutos extras de esos meses
     */
    private function extras(array $ids, array $meses): array
    {
        if ($meses === []) {
            return [];
        }
        $inIds = implode(', ', array_fill(0, count($ids), '?'));
        $inMeses = implode(', ', array_fill(0, count($meses), '?'));
        $salida = [];
        foreach (Database::fetchAll(
            "SELECT usuario_id, SUM(minutos) AS minutos
               FROM #__horas_extras
              WHERE usuario_id IN ({$inIds}) AND mes IN ({$inMeses})
              GROUP BY usuario_id",
            array_merge($ids, $meses)
        ) as $f) {
            $salida[(int) $f['usuario_id']] = (int) $f['minutos'];
        }
        return $salida;
    }
}
