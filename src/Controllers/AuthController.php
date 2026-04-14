<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\AuthException;
use Atankalama\Limpieza\Services\AuthService;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth = new AuthService(),
    ) {
    }

    public function login(Request $request): Response
    {
        $rut = $request->inputString('rut');
        $password = $request->inputString('password');
        if ($rut === '' || $password === '') {
            return Response::error('CAMPOS_REQUERIDOS', 'RUT y contraseña son obligatorios.', 400);
        }

        try {
            $resultado = $this->auth->login($rut, $password, $request->ip, $request->userAgent());
        } catch (AuthException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        $usuario = $resultado['usuario'];
        $cookieOpts = [
            'expires' => time() + Config::getInt('SESSION_LIFETIME_MINUTES', 480) * 60,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
        ];
        if (Config::get('APP_ENV', 'local') !== 'local') {
            $cookieOpts['secure'] = true;
        }

        return Response::ok([
            'usuario' => $usuario->toArrayPublico(),
            'permisos' => $usuario->permisos,
            'requiere_cambio_pwd' => $usuario->requiereCambioPwd,
            'home_target' => $resultado['home_target'],
        ])->conCookie('session', $resultado['token'], $cookieOpts);
    }

    public function logout(Request $request): Response
    {
        $token = $request->sessionToken;
        if ($token !== null) {
            $this->auth->logout($token, $request->usuario?->id, $request->ip);
        }
        return Response::ok(['mensaje' => 'Sesión cerrada.'])
            ->conCookie('session', '', ['expires' => time() - 3600, 'path' => '/']);
    }

    public function yo(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null) {
            return Response::error('NO_AUTENTICADO', 'Debes iniciar sesión.', 401);
        }
        return Response::ok([
            'usuario' => $usuario->toArrayPublico(),
            'permisos' => $usuario->permisos,
            'home_target' => $this->auth->calcularHomeTarget($usuario),
        ]);
    }

    public function cambiarContrasena(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null) {
            return Response::error('NO_AUTENTICADO', 'Debes iniciar sesión.', 401);
        }

        $actual = $request->inputString('password_actual');
        $nueva = $request->inputString('password_nueva');
        $confirm = $request->inputString('password_nueva_confirmacion');

        if ($actual === '' || $nueva === '' || $confirm === '') {
            return Response::error('CAMPOS_REQUERIDOS', 'Todos los campos son obligatorios.', 400);
        }

        try {
            $this->auth->cambiarContrasena($usuario->id, $actual, $nueva, $confirm);
        } catch (AuthException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        return Response::ok(['mensaje' => 'Contraseña actualizada.']);
    }

    public function resetearTemporal(Request $request): Response
    {
        $admin = $request->usuario;
        if ($admin === null) {
            return Response::error('NO_AUTENTICADO', 'Debes iniciar sesión.', 401);
        }

        $usuarioId = $request->inputInt('usuario_id');
        if ($usuarioId === null) {
            return Response::error('CAMPOS_REQUERIDOS', 'usuario_id es obligatorio.', 400);
        }

        try {
            $temporal = $this->auth->resetearContrasenaTemporal($usuarioId, $admin->id);
        } catch (AuthException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        return Response::ok(['password_temporal' => $temporal]);
    }
}
