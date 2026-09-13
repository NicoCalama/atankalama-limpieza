<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Middleware;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Core\Url;
use Atankalama\Limpieza\Services\AuthService;
use Atankalama\Limpieza\Services\ModoEspiaService;
use Atankalama\Limpieza\Support\EspiaContext;

final class AuthCheck implements Middleware
{
    /**
     * Única ruta mutante permitida mientras el modo espía está activo: hay que poder
     * salir de él estando adentro. Cualquier otra escritura queda bloqueada abajo.
     */
    private const RUTA_SALIR_MODO_ESPIA = '/api/modo-espia/salir';

    private const METODOS_MUTANTES = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly AuthService $auth = new AuthService(),
        private readonly ModoEspiaService $espia = new ModoEspiaService(),
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $token = $request->cookies[AuthService::SESSION_COOKIE] ?? null;
        if ($token === null || $token === '') {
            return Response::error('NO_AUTENTICADO', 'Debes iniciar sesión.', 401);
        }

        $usuario = $this->auth->validarSesion($token);
        if ($usuario === null) {
            return Response::error('SESION_EXPIRADA', 'Tu sesión expiró. Inicia sesión nuevamente.', 401)
                ->conCookie(AuthService::SESSION_COOKIE, '', ['expires' => time() - 3600, 'path' => Url::base() ?: '/']);
        }

        if (!$usuario->activo) {
            return Response::error('USUARIO_INACTIVO', 'Tu usuario está inactivo.', 403);
        }

        $request->sessionToken = $token;

        // Modo espía: si esta sesión tiene un objetivo activo, la request corre con el
        // usuario objetivo (mismos permisos/roles/datos que él vería) y queda bloqueada
        // toda mutación salvo la de salir del modo espía. El admin real viaja aparte en
        // $request->espiaAdminId para auditoría y para el banner de la UI.
        $estadoEspia = $this->espia->resolverParaSesion($token, $usuario);
        if ($estadoEspia !== null) {
            $usuario = $estadoEspia['objetivo'];
            $request->espiaAdminId = $estadoEspia['admin']->id;
            $request->espiaAdminNombre = $estadoEspia['admin']->nombre;
            EspiaContext::activar($estadoEspia['admin']->nombre);

            $esMutacion = in_array($request->metodo, self::METODOS_MUTANTES, true);
            if ($esMutacion && $request->path !== self::RUTA_SALIR_MODO_ESPIA) {
                return Response::error(
                    'MODO_ESPIA_SOLO_LECTURA',
                    'Estás en modo espía (solo lectura). Sal del modo espía para hacer cambios.',
                    403
                );
            }
        }

        $request->usuario = $usuario;
        $request->permisos = $usuario->permisos;

        return $next($request);
    }
}
