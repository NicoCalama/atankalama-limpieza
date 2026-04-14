<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Middleware;

use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;

final class PermissionCheck implements Middleware
{
    /** @var string[] */
    private array $permisosRequeridos;

    /**
     * @param string|string[] $permisos  Si es array, basta con tener AL MENOS UNO.
     */
    public function __construct(string|array $permisos)
    {
        $this->permisosRequeridos = is_array($permisos) ? $permisos : [$permisos];
    }

    public function handle(Request $request, callable $next): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null) {
            return Response::error('NO_AUTENTICADO', 'AuthCheck debe correr antes de PermissionCheck.', 401);
        }

        if (!$usuario->tieneAlgunPermiso($this->permisosRequeridos)) {
            Logger::warning(
                'auth',
                'permiso insuficiente',
                ['requeridos' => $this->permisosRequeridos, 'tiene' => $usuario->permisos],
                $usuario->id
            );
            return Response::error('PERMISO_INSUFICIENTE', 'No tienes permisos para esta acción.', 403);
        }

        return $next($request);
    }
}
