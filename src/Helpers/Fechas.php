<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Helpers;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Puente entre las fechas LOCALES del negocio y los timestamps UTC de la BD.
 *
 * Todas las columnas de tiempo (timestamp_inicio, created_at, …) se guardan en
 * **UTC** con formato ISO 'YYYY-MM-DDTHH:MM:SS.sssZ'. Pero el negocio piensa en
 * días de Santiago: la supervisora que filtra "hoy" quiere el día chileno, y el
 * turno de tarde termina a las 22:00 local — que en UTC ya es el día siguiente.
 *
 * Comparar `DATE(col)` (que es la fecha UTC) contra una fecha local desfasaba el
 * trabajo hecho entre las 20:00 y la medianoche al día siguiente. La regla acá es:
 * **la conversión se hace en PHP sobre el rango, nunca en SQL sobre la columna.**
 * Así el cálculo respeta el horario de verano (Chile alterna UTC-4 / UTC-3, y un
 * offset fijo en SQL se rompería dos veces al año), el SQL queda igual en SQLite
 * y en MariaDB, y la columna no queda envuelta en una función.
 *
 * Como los timestamps son ISO del mismo formato y ancho, compararlos como string
 * ordena igual que cronológicamente.
 */
final class Fechas
{
    /** Formato de los timestamps en BD: ISO 8601 UTC con milisegundos. */
    private const ISO_UTC = 'Y-m-d\TH:i:s.v\Z';

    /**
     * Rango UTC que cubre un rango de días locales, ambos inclusive.
     *
     * El límite superior es **exclusivo**: se compara con `col >= $desde AND col < $hasta`.
     * Un `BETWEEN` sobre el instante final dejaría afuera los últimos milisegundos del día.
     *
     * @param string $desde Fecha local YYYY-MM-DD (inclusive)
     * @param string $hasta Fecha local YYYY-MM-DD (inclusive)
     * @return array{0:string, 1:string} [inicio inclusive, fin exclusivo]
     */
    public static function rangoUtc(string $desde, string $hasta): array
    {
        $tz = new DateTimeZone(date_default_timezone_get());
        $inicio = new DateTimeImmutable($desde . ' 00:00:00', $tz);
        // +1 día en el calendario local (no +24 horas): en el cambio de hora, un día
        // dura 23 o 25 horas y sumar horas correría el límite.
        $fin = (new DateTimeImmutable($hasta . ' 00:00:00', $tz))->modify('+1 day');

        return [self::aIsoUtc($inicio), self::aIsoUtc($fin)];
    }

    /**
     * Rango UTC de un solo día local. Azúcar para los `DATE(col) = ?`.
     *
     * @return array{0:string, 1:string} [inicio inclusive, fin exclusivo]
     */
    public static function rangoUtcDelDia(string $fecha): array
    {
        return self::rangoUtc($fecha, $fecha);
    }

    /**
     * La fecha local (YYYY-MM-DD) a la que pertenece un timestamp UTC de la BD.
     *
     * Para agrupar por día en PHP cuando el SQL no puede (SQLite y MariaDB no
     * comparten una función de conversión de zona horaria portable).
     */
    public static function fechaLocalDeUtc(string $timestampUtc): string
    {
        $ts = new DateTimeImmutable($timestampUtc, new DateTimeZone('UTC'));

        return $ts->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
    }

    private static function aIsoUtc(DateTimeImmutable $momento): string
    {
        return $momento->setTimezone(new DateTimeZone('UTC'))->format(self::ISO_UTC);
    }
}
