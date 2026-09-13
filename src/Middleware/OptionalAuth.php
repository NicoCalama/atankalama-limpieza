<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Middleware;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\AuthService;
use Atankalama\Limpieza\Services\ModoEspiaService;
use Atankalama\Limpieza\Support\EspiaContext;

/**
 * Intenta cargar el usuario autenticado si hay cookie de sesión válida.
 * A diferencia de AuthCheck, NO bloquea si no hay sesión — simplemente
 * deja $request->usuario en null y continúa.
 * Útil para páginas que funcionan distinto según si el usuario está o no autenticado.
 */
final class OptionalAuth implements Middleware
{
    public function __construct(
        private readonly AuthService $auth = new AuthService(),
        private readonly ModoEspiaService $espia = new ModoEspiaService(),
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $token = $request->cookies[AuthService::SESSION_COOKIE] ?? null;
        if ($token !== null && $token !== '') {
            $usuario = $this->auth->validarSesion($token);
            if ($usuario !== null && $usuario->activo) {
                $request->sessionToken = $token;

                // Modo espía: todas las rutas de página (HTML) pasan por este middleware,
                // no por AuthCheck — sin esto, el layout/menú se renderizaría con el admin
                // real en vez del usuario objetivo. No hay bloqueo de mutación acá porque
                // ninguna ruta con OptionalAuth es POST/PUT/PATCH/DELETE.
                $estadoEspia = $this->espia->resolverParaSesion($token, $usuario);
                if ($estadoEspia !== null) {
                    $usuario = $estadoEspia['objetivo'];
                    $request->espiaAdminId = $estadoEspia['admin']->id;
                    $request->espiaAdminNombre = $estadoEspia['admin']->nombre;
                    EspiaContext::activar($estadoEspia['admin']->nombre);
                }

                $request->usuario = $usuario;
                $request->permisos = $usuario->permisos;
            }
        }

        return $next($request);
    }
}
