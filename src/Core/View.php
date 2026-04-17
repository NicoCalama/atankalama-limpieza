<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Core;

final class View
{
    /**
     * Renderiza un template PHP y devuelve un Response HTML.
     *
     * @param string               $template  Nombre del template (sin extensión), relativo a views/
     * @param array<string, mixed> $datos     Variables disponibles en el template
     * @param int                  $status    HTTP status code
     */
    public static function renderizar(string $template, array $datos = [], int $status = 200): Response
    {
        $html = self::capturar($template, $datos);
        return new Response($status, $html, 'text/html; charset=utf-8');
    }

    /**
     * Renderiza un template con layout envolvente.
     *
     * @param string               $template  Nombre del template (sin extensión)
     * @param array<string, mixed> $datos     Variables para el template y el layout
     * @param string               $layout    Nombre del layout (sin extensión)
     */
    public static function conLayout(string $template, array $datos = [], string $layout = 'layout', int $status = 200): Response
    {
        $datos['__contenido'] = self::capturar($template, $datos);
        $html = self::capturar($layout, $datos);
        return new Response($status, $html, 'text/html; charset=utf-8');
    }

    /**
     * Captura el output de un template PHP y lo devuelve como string.
     */
    private static function capturar(string $template, array $datos): string
    {
        $archivo = dirname(__DIR__, 2) . '/views/' . $template . '.php';
        if (!file_exists($archivo)) {
            throw new \RuntimeException("Template no encontrado: {$template}");
        }

        extract($datos, EXTR_SKIP);
        ob_start();
        require $archivo;
        return ob_get_clean() ?: '';
    }
}
