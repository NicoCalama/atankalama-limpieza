<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Middleware;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\AuthService;

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
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $token = $request->cookies['session'] ?? null;
        if ($token !== null && $token !== '') {
            $usuario = $this->auth->validarSesion($token);
            if ($usuario !== null && $usuario->activo) {
                $request->usuario = $usuario;
                $request->permisos = $usuario->permisos;
                $request->sessionToken = $token;
            }
        }

        return $next($request);
    }
}
