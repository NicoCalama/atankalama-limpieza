<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Helpers;

/**
 * Horas de trabajo de un turno (v7.16, pedido de gerencia del 06/11/2026). El bono de Aseo pasa a medirse
 * por hora (v7.17), y las horas de cada día salen del turno de la persona.
 *
 * Jornada completa: el turno incluye 30 min de colación NO pagada (Nicolás, 07/11/2026), así que un turno
 * 08:00–16:00 son 7,5 h de trabajo, que es para lo que está pensado el corte del bono. La jornada parcial
 * no tiene colación: cuenta el turno completo. Sin jornada definida tampoco se descuenta (no se inventa).
 *
 * Los turnos nunca cruzan la medianoche (docs/turnos.md §5.3): un fin anterior o igual al inicio da 0.
 */
final class HorasTurno
{
    public const COLACION_COMPLETA_MIN = 30;

    /** Minutos entre dos horas «HH:MM» del mismo día (0 si no se entiende o el fin no es posterior). */
    public static function minutosEntre(string $inicio, string $fin): int
    {
        $a = self::aMinutos($inicio);
        $b = self::aMinutos($fin);
        if ($a === null || $b === null || $b <= $a) {
            return 0;
        }
        return $b - $a;
    }

    /** Minutos de trabajo del turno: el turno menos la colación si la jornada es completa. Nunca negativo. */
    public static function minutosNetos(string $inicio, string $fin, ?string $jornada): int
    {
        $total = self::minutosEntre($inicio, $fin);
        if ($jornada === 'completa') {
            $total -= self::COLACION_COMPLETA_MIN;
        }
        return max(0, $total);
    }

    private static function aMinutos(string $hora): ?int
    {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::\d{2})?$/', trim($hora), $m)) {
            return null;
        }
        return ((int) $m[1]) * 60 + (int) $m[2];
    }
}
