<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Helpers;

use Atankalama\Limpieza\Core\Config;

/**
 * Lee el historial de versiones desde CHANGELOG.md, la única fuente de verdad.
 *
 * De acá salen tanto la pantalla /ajustes/versiones como el badge de versión del
 * home del Admin, para que no puedan decir cosas distintas. Formato esperado (ver
 * el propio CHANGELOG.md), una fila de tabla por versión y la más nueva arriba:
 *
 *     | **v1.1** · 07/07/2026 | Cambio uno · Cambio dos |
 *
 * Izquierda: versión y fecha de publicación en DD/MM/YYYY, o "sin publicar" si la
 * versión está en main pero todavía no salió a producción. Derecha: los cambios
 * separados por " · ". Las filas que no calzan con el formato se ignoran (así el
 * encabezado y la línea de guiones no ensucian el resultado).
 */
final class Changelog
{
    private const SEPARADOR = ' · ';

    /** Fila de versión: | **vX.Y** · fecha | cambio · cambio | */
    private const PATRON_FILA = '/^\|\s*\*\*v([0-9][0-9.]*)\*\*\s*·\s*([^|]+?)\s*\|\s*(.+?)\s*\|$/u';

    /** @var list<array{version:string, fecha:?string, publicada:bool, cambios:list<string>}>|null */
    private static ?array $cache = null;

    /**
     * Todas las versiones del changelog, de la más nueva a la más vieja.
     *
     * Si el archivo no existe (o no se puede leer), devuelve una lista vacía: la
     * pantalla muestra su estado vacío y el badge se oculta, pero nada se cae.
     *
     * @return list<array{version:string, fecha:?string, publicada:bool, cambios:list<string>}>
     */
    public static function versiones(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $archivo = self::ruta();
        if (!is_file($archivo)) {
            self::$cache = [];
            return self::$cache;
        }
        $contenido = @file_get_contents($archivo);
        self::$cache = $contenido === false ? [] : self::parsear($contenido);
        return self::$cache;
    }

    /**
     * La versión que está corriendo: la última **publicada**.
     *
     * Una versión "sin publicar" no cuenta — todavía no llegó a producción, y el
     * badge tiene que decir lo que el usuario está usando, no lo que viene.
     *
     * @return array{version:string, fecha:?string, publicada:bool, cambios:list<string>}|null
     */
    public static function actual(): ?array
    {
        foreach (self::versiones() as $v) {
            if ($v['publicada']) {
                return $v;
            }
        }
        return null;
    }

    /**
     * @return list<array{version:string, fecha:?string, publicada:bool, cambios:list<string>}>
     */
    public static function parsear(string $markdown): array
    {
        $versiones = [];
        $enBloqueDeCodigo = false;
        foreach (preg_split('/\R/u', $markdown) ?: [] as $linea) {
            $linea = trim($linea);
            // El propio CHANGELOG.md documenta su formato con una fila de ejemplo dentro
            // de un bloque ``` — sin saltearlo, el ejemplo entraría como una versión más.
            if (str_starts_with($linea, '```')) {
                $enBloqueDeCodigo = !$enBloqueDeCodigo;
                continue;
            }
            if ($enBloqueDeCodigo || $linea === '' || $linea[0] !== '|') {
                continue;
            }
            if (preg_match(self::PATRON_FILA, $linea, $m) !== 1) {
                continue;
            }
            $fecha = trim($m[2]);
            $publicada = self::esFecha($fecha);
            $cambios = array_values(array_filter(
                array_map('trim', explode(self::SEPARADOR, $m[3])),
                static fn(string $c): bool => $c !== ''
            ));
            $versiones[] = [
                'version' => $m[1],
                'fecha' => $publicada ? $fecha : null,
                'publicada' => $publicada,
                'cambios' => $cambios,
            ];
        }
        return $versiones;
    }

    /** Vacía el cache en memoria. Solo para los tests. */
    public static function limpiarCache(): void
    {
        self::$cache = null;
    }

    private static function ruta(): string
    {
        return Config::basePath() . '/CHANGELOG.md';
    }

    /** DD/MM/YYYY real (no basta el patrón: 32/13/2026 no es fecha). */
    private static function esFecha(string $valor): bool
    {
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $valor, $m) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[1], (int) $m[3]);
    }
}
