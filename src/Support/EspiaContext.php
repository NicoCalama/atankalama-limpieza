<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Support;

/**
 * EspiaContext — puente de solo lectura entre AuthCheck (que ya resolvió el
 * modo espía de la request, con su query a #__sesiones) y las vistas
 * (layout.php, que arma el banner sin recibir el Request completo — mismo
 * patrón que TourResolver::forCurrentRequest($usuario)).
 *
 * AuthCheck llama a EspiaContext::activar() una sola vez por request, antes
 * de despachar. Nadie más debería escribir acá.
 */
final class EspiaContext
{
    private static bool $activo = false;
    private static ?string $adminNombre = null;

    public static function activar(string $adminNombre): void
    {
        self::$activo = true;
        self::$adminNombre = $adminNombre;
    }

    public static function activo(): bool
    {
        return self::$activo;
    }

    public static function adminNombre(): ?string
    {
        return self::$adminNombre;
    }
}
