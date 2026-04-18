<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Core\View;
use Atankalama\Limpieza\Services\AuthService;

final class PaginasController
{
    public function __construct(
        private readonly AuthService $auth = new AuthService(),
    ) {
    }

    public function raiz(Request $request): Response
    {
        if ($request->usuario !== null) {
            $target = $this->auth->calcularHomeTarget($request->usuario);
            return self::redirect($target);
        }
        return self::redirect('/login');
    }

    public function login(Request $request): Response
    {
        if ($request->usuario !== null) {
            $target = $this->auth->calcularHomeTarget($request->usuario);
            return self::redirect($target);
        }
        return View::renderizar('login');
    }

    public function cambiarContrasena(Request $request): Response
    {
        if ($request->usuario === null) {
            return self::redirect('/login');
        }
        return View::conLayout('cambiar-contrasena', [
            'usuario' => $request->usuario,
            'titulo' => 'Cambiar contraseña',
        ]);
    }

    public function home(Request $request): Response
    {
        if ($request->usuario === null) {
            return self::redirect('/login');
        }
        if ($request->usuario->requiereCambioPwd) {
            return self::redirect('/cambiar-contrasena');
        }
        return View::conLayout('home', [
            'usuario' => $request->usuario,
            'titulo' => 'Inicio',
        ]);
    }

    public function habitaciones(Request $request): Response
    {
        if ($request->usuario === null) {
            return self::redirect('/login');
        }
        if ($request->usuario->requiereCambioPwd) {
            return self::redirect('/cambiar-contrasena');
        }
        return View::conLayout('habitaciones', [
            'usuario' => $request->usuario,
            'titulo' => 'Habitaciones',
        ]);
    }

    public function habitacionDetalle(Request $request): Response
    {
        if ($request->usuario === null) {
            return self::redirect('/login');
        }
        if ($request->usuario->requiereCambioPwd) {
            return self::redirect('/cambiar-contrasena');
        }
        $id = $request->rutaInt('id');
        if ($id === null) {
            return self::redirect('/habitaciones');
        }
        return View::conLayout('habitacion-detalle', [
            'usuario' => $request->usuario,
            'habitacionId' => $id,
            'titulo' => 'Habitación',
        ]);
    }

    private static function redirect(string $url): Response
    {
        $response = new Response(302, '', 'text/html; charset=utf-8');
        return $response->conHeader('Location', $url);
    }
}
