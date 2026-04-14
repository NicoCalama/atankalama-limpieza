<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Middleware;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\AuthService;

final class AuthCheck implements Middleware
{
    public function __construct(
        private readonly AuthService $auth = new AuthService(),
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $token = $request->cookies['session'] ?? null;
        if ($token === null || $token === '') {
            return Response::error('NO_AUTENTICADO', 'Debes iniciar sesión.', 401);
        }

        $usuario = $this->auth->validarSesion($token);
        if ($usuario === null) {
            return Response::error('SESION_EXPIRADA', 'Tu sesión expiró. Inicia sesión nuevamente.', 401)
                ->conCookie('session', '', ['expires' => time() - 3600, 'path' => '/']);
        }

        if (!$usuario->activo) {
            return Response::error('USUARIO_INACTIVO', 'Tu usuario está inactivo.', 403);
        }

        $request->usuario = $usuario;
        $request->permisos = $usuario->permisos;
        $request->sessionToken = $token;

        return $next($request);
    }
}
