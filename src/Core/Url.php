<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Core;

/**
 * URLs de la app relativas al prefijo BASE_PATH.
 *
 * La app puede vivir en la raíz del dominio (dev: BASE_PATH vacío) o bajo un
 * subdirectorio (prod cPanel: BASE_PATH=/limpieza, mismo patrón que Maisterchef).
 * Regla: toda URL que viaja al navegador (redirects, links, assets, payloads de
 * push) pasa por Url::a() / u(); el Router en cambio trabaja SIEMPRE con paths
 * sin prefijo (Request::desdeGlobales() lo quita con Url::quitarBase()).
 */
final class Url
{
    /** Prefijo normalizado bajo el que vive la app: '' (raíz) o '/limpieza'. */
    public static function base(): string
    {
        try {
            $base = (string) Config::get('BASE_PATH', '');
        } catch (\RuntimeException) {
            // Config sin cargar (tests unitarios aislados): comportarse como raíz.
            return '';
        }

        $base = trim($base, '/');
        return $base === '' ? '' : '/' . $base;
    }

    /** Prefija una ruta de la app: a('/home') → '/limpieza/home' en prod, '/home' en dev. */
    public static function a(string $path): string
    {
        return self::base() . $path;
    }

    /**
     * Quita el prefijo BASE_PATH de un path entrante ('/limpieza/home' → '/home').
     * Un path fuera del prefijo se devuelve tal cual (no matcheará ninguna ruta).
     */
    public static function quitarBase(string $path): string
    {
        $base = self::base();
        if ($base === '') {
            return $path;
        }
        if ($path === $base) {
            return '/';
        }
        if (str_starts_with($path, $base . '/')) {
            return substr($path, strlen($base));
        }
        return $path;
    }

    /** Path de la request actual SIN prefijo (para marcar la nav activa en vistas). */
    public static function rutaActual(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return self::quitarBase($path);
    }
}
