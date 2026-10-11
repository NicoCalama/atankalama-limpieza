<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;

/**
 * Habitaciones de prueba (v7.5, pedido de Nicolás del 10/10/2026): una pieza «PRUEBA» por hotel
 * (habitaciones.es_prueba = 1, sin cloudbeds_room_id) para hacer los smoke tests en producción sin
 * tocar Cloudbeds ni el proceso real. Decisiones de Nicolás:
 *   - la ve solo quien tiene habitaciones.ver_prueba (Admin por defecto) y solo se le asigna a esa gente;
 *   - queda fuera de todo lo que mide o reparte (auto-asignar, KPIs, Reportes, Inicio, producción de
 *     Recepción, alertas predictivas);
 *   - no avisa a nadie más: sin alertas, sin push a supervisoras y sus tickets no van a Novedades.
 * Ver docs/habitacion-prueba.md.
 */
final class HabitacionesPrueba
{
    public const PERMISO_VER = 'habitaciones.ver_prueba';

    /**
     * Condición SQL «la pieza de $alias no es de prueba», para ir con AND dentro de una consulta.
     * $alias es un identificador del código (p. ej. `h`), nunca un dato del usuario.
     */
    public static function sqlSinPrueba(string $alias = 'h'): string
    {
        if (preg_match('/^[a-z_]+$/', $alias) !== 1) {
            throw new \InvalidArgumentException("Alias no válido: {$alias}");
        }
        return "{$alias}.es_prueba = 0";
    }

    /**
     * Saca de una lista de filas las de piezas de prueba, salvo que quien mira tenga el permiso.
     *
     * @template T of array<string, mixed>
     * @param list<T> $filas con la columna es_prueba
     * @return list<T>
     */
    public static function filtrar(array $filas, bool $puedeVer): array
    {
        if ($puedeVer) {
            return $filas;
        }
        return array_values(array_filter(
            $filas,
            static fn(array $f): bool => ((int) ($f['es_prueba'] ?? 0)) !== 1
        ));
    }

    public static function esPrueba(int $habitacionId): bool
    {
        return (int) Database::fetchColumn('SELECT es_prueba FROM #__habitaciones WHERE id = ?', [$habitacionId]) === 1;
    }

    /** ¿El usuario tiene habitaciones.ver_prueba por alguno de sus roles? */
    public static function usuarioPuedeVer(int $usuarioId): bool
    {
        return Database::fetchOne(
            'SELECT 1 FROM #__usuarios_roles ur
               JOIN #__rol_permisos rp ON rp.rol_id = ur.rol_id
              WHERE ur.usuario_id = ? AND rp.permiso_codigo = ?',
            [$usuarioId, self::PERMISO_VER]
        ) !== null;
    }
}
