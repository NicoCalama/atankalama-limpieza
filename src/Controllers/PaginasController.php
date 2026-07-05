<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Core\Url;
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

    public function asignaciones(Request $request): Response
    {
        if ($request->usuario === null) {
            return self::redirect('/login');
        }
        if ($request->usuario->requiereCambioPwd) {
            return self::redirect('/cambiar-contrasena');
        }
        return View::conLayout('asignaciones', [
            'usuario' => $request->usuario,
            'titulo' => 'Asignaciones',
        ]);
    }

    public function espacios(Request $request): Response
    {
        if ($request->usuario === null) {
            return self::redirect('/login');
        }
        if ($request->usuario->requiereCambioPwd) {
            return self::redirect('/cambiar-contrasena');
        }
        if (!$request->usuario->tienePermiso('espacios.ver')) {
            return self::redirect('/home');
        }
        return View::conLayout('espacios', [
            'usuario' => $request->usuario,
            'titulo' => 'Áreas comunes',
        ]);
    }

    public function ajustesRbac(Request $request): Response
    {
        if ($request->usuario === null) {
            return self::redirect('/login');
        }
        if ($request->usuario->requiereCambioPwd) {
            return self::redirect('/cambiar-contrasena');
        }
        if (!$request->usuario->tienePermiso('permisos.asignar_a_rol')) {
            return self::redirect('/home');
        }
        return View::conLayout('ajustes-rbac', [
            'usuario' => $request->usuario,
            'titulo' => 'Roles y permisos',
        ]);
    }

    public function ajustes(Request $request): Response
    {
        if ($request->usuario === null) {
            return self::redirect('/login');
        }
        if ($request->usuario->requiereCambioPwd) {
            return self::redirect('/cambiar-contrasena');
        }
        return View::conLayout('ajustes', [
            'usuario' => $request->usuario,
            'titulo' => 'Ajustes',
        ]);
    }

    public function ajustesMiCuenta(Request $request): Response
    {
        if ($request->usuario === null) {
            return self::redirect('/login');
        }
        if ($request->usuario->requiereCambioPwd) {
            return self::redirect('/cambiar-contrasena');
        }
        return View::conLayout('ajustes-mi-cuenta', [
            'usuario' => $request->usuario,
            'titulo' => 'Mi cuenta',
        ]);
    }

    public function ajustesTurnos(Request $request): Response
    {
        if ($request->usuario === null) {
            return self::redirect('/login');
        }
        if ($request->usuario->requiereCambioPwd) {
            return self::redirect('/cambiar-contrasena');
        }
        if (!$request->usuario->tienePermiso('turnos.ver')) {
            return self::redirect('/ajustes');
        }
        return View::conLayout('ajustes-turnos', [
            'usuario' => $request->usuario,
            'titulo' => 'Turnos',
        ]);
    }

    public function ajustesImportarTurnos(Request $request): Response
    {
        if ($request->usuario === null) {
            return self::redirect('/login');
        }
        if ($request->usuario->requiereCambioPwd) {
            return self::redirect('/cambiar-contrasena');
        }
        if (!$request->usuario->tienePermiso('turnos.importar')) {
            return self::redirect('/ajustes/turnos');
        }
        return View::conLayout('ajustes/importar-turnos', [
            'usuario' => $request->usuario,
            'titulo' => 'Importar turnos',
        ]);
    }

    public function ajustesAlertas(Request $request): Response
    {
        if ($request->usuario === null) {
            return self::redirect('/login');
        }
        if ($request->usuario->requiereCambioPwd) {
            return self::redirect('/cambiar-contrasena');
        }
        if (!$request->usuario->tienePermiso('alertas.configurar_umbrales')) {
            return self::redirect('/ajustes');
        }
        return View::conLayout('ajustes-alertas', [
            'usuario' => $request->usuario,
            'titulo' => 'Alertas',
        ]);
    }

    public function usuarios(Request $request): Response
    {
        if ($request->usuario === null) {
            return self::redirect('/login');
        }
        if ($request->usuario->requiereCambioPwd) {
            return self::redirect('/cambiar-contrasena');
        }
        return View::conLayout('usuarios', [
            'usuario' => $request->usuario,
            'titulo' => 'Usuarios',
        ]);
    }

    public function tickets(Request $request): Response
    {
        if ($request->usuario === null) {
            return self::redirect('/login');
        }
        if ($request->usuario->requiereCambioPwd) {
            return self::redirect('/cambiar-contrasena');
        }
        return View::conLayout('tickets', [
            'usuario' => $request->usuario,
            'titulo' => 'Tickets',
        ]);
    }

    public function auditoriaBandeja(Request $request): Response
    {
        if ($request->usuario === null) {
            return self::redirect('/login');
        }
        if ($request->usuario->requiereCambioPwd) {
            return self::redirect('/cambiar-contrasena');
        }
        if (!$request->usuario->tienePermiso('auditoria.ver_bandeja')) {
            return self::redirect('/home');
        }
        return View::conLayout('auditoria-bandeja', [
            'usuario' => $request->usuario,
            'titulo' => 'Auditoría',
        ]);
    }

    public function auditoriaDetalle(Request $request): Response
    {
        if ($request->usuario === null) {
            return self::redirect('/login');
        }
        if ($request->usuario->requiereCambioPwd) {
            return self::redirect('/cambiar-contrasena');
        }
        $id = $request->rutaInt('id');
        if ($id === null) {
            return self::redirect('/home');
        }
        return View::conLayout('auditoria-detalle', [
            'usuario' => $request->usuario,
            'habitacionId' => $id,
            'titulo' => 'Auditoría',
        ]);
    }

    public function reportes(Request $request): Response
    {
        if ($request->usuario === null) {
            return self::redirect('/login');
        }
        if ($request->usuario->requiereCambioPwd) {
            return self::redirect('/cambiar-contrasena');
        }
        if (!$request->usuario->tienePermiso('reportes.ver')) {
            return self::redirect('/home');
        }
        return View::conLayout('reportes', [
            'usuario' => $request->usuario,
            'titulo'  => 'Reportes',
        ]);
    }

    /**
     * Manifest PWA generado en runtime: start_url, scope, iconos y shortcuts
     * salen con el prefijo BASE_PATH, así una sola fuente sirve para dev (raíz)
     * y prod (subpath /limpieza). No existe public/manifest.json estático.
     */
    public function manifest(Request $request): Response
    {
        $base = Url::base();
        $manifest = [
            'name' => 'Atankalama Limpieza',
            'short_name' => 'Limpieza',
            'description' => 'Gestión de limpieza hotelera Atankalama Corp',
            'start_url' => $base . '/home',
            'scope' => $base . '/',
            'display' => 'standalone',
            'orientation' => 'portrait-primary',
            'background_color' => '#111827',
            'theme_color' => '#2563eb',
            'lang' => 'es',
            'icons' => [
                [
                    'src' => $base . '/assets/img/icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
                [
                    'src' => $base . '/assets/img/icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
            ],
            'shortcuts' => [
                [
                    'name' => 'Mis habitaciones',
                    'url' => $base . '/habitaciones',
                    'description' => 'Ver habitaciones asignadas',
                ],
                [
                    'name' => 'Auditoría',
                    'url' => $base . '/auditoria',
                    'description' => 'Bandeja de auditoría',
                ],
            ],
            'categories' => ['productivity', 'utilities'],
        ];

        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}';
        return new Response(200, $json, 'application/manifest+json; charset=utf-8');
    }

    private static function redirect(string $url): Response
    {
        // $url llega sin prefijo ('/login'); acá se antepone BASE_PATH una sola vez.
        $response = new Response(302, '', 'text/html; charset=utf-8');
        return $response->conHeader('Location', Url::a($url));
    }
}
