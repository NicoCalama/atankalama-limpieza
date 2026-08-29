<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;

/**
 * Sirve las fotos de tickets (ImagenAdjuntoService) vía PHP en vez de como
 * archivo estático de Apache.
 *
 * Por qué existe: los adjuntos se guardan físicamente en app_core/public/uploads/
 * (Config::basePath() . '/public/uploads/'), pero app_core/ está denegado por web
 * (Require all denied) — el navegador nunca podía llegar a esos archivos. Ver
 * conversación de diagnóstico: toda foto subida devolvía 404 en producción.
 *
 * Ruta pública (sin AuthCheck) a propósito: el link se comparte con Recepción vía
 * NovedadesSyncService, que no tiene cuenta en Limpieza. Mismo nivel de exposición
 * que tenía el archivo estático antes (nombre impredecible: hex random de 16).
 */
final class UploadsController
{
    /** Único patrón que ImagenAdjuntoService::rutaDestino() produce — allowlist estricta. */
    private const PATRON_RUTA = '#^tickets/\d{4}/\d{2}/[a-f0-9]{16}\.webp$#';

    public function servir(Request $request): Response
    {
        $ruta = $request->ruta['ruta'] ?? '';
        // El Router siempre pobla $request->ruta con strings (capturas de preg_match), así que
        // is_string() es hoy redundante para el tipo — pero este endpoint es público y sin auth
        // (ver comentario de clase), así que se mantiene como cinturón y tiradores.
        // @phpstan-ignore-next-line function.alreadyNarrowedType
        if (!is_string($ruta) || preg_match(self::PATRON_RUTA, $ruta) !== 1) {
            return Response::error('RUTA_INVALIDA', 'Adjunto no encontrado.', 404);
        }

        $base = realpath(Config::basePath() . '/public/uploads');
        $absoluta = realpath(Config::basePath() . '/public/uploads/' . $ruta);
        // Cinturón y tiradores: aunque el patrón ya descarta '..', confirmamos que la
        // ruta resuelta sigue physically dentro de uploads/ antes de leer el archivo.
        if ($base === false || $absoluta === false || !str_starts_with($absoluta, $base . DIRECTORY_SEPARATOR)) {
            return Response::error('RUTA_INVALIDA', 'Adjunto no encontrado.', 404);
        }

        $contenido = file_get_contents($absoluta);
        if ($contenido === false) {
            return Response::error('ERROR_LECTURA', 'No se pudo leer el adjunto.', 500);
        }

        // Nombre de archivo aleatorio e inmutable (nunca se reescribe) — cache larga segura.
        return (new Response(200, $contenido, 'image/webp'))
            ->conHeader('Cache-Control', 'public, max-age=31536000, immutable');
    }
}
