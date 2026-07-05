<?php

declare(strict_types=1);

use Atankalama\Limpieza\Core\Url;

if (!function_exists('u')) {
    /**
     * Prefija una ruta de la app con BASE_PATH: u('/home') → '/limpieza/home' en prod.
     * Usar en vistas y JS inline para TODA URL propia que viaja al navegador.
     * OJO: apiFetch/apiPost/apiPut (app.js) ya prefijan solos — no combinar con u().
     */
    function u(string $path): string
    {
        return Url::a($path);
    }
}
