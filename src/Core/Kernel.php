<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Core;

use Atankalama\Limpieza\Controllers\AuthController;
use Atankalama\Limpieza\Controllers\RolesController;
use Atankalama\Limpieza\Middleware\AuthCheck;
use Atankalama\Limpieza\Middleware\PermissionCheck;

final class Kernel
{
    public static function construirRouter(): Router
    {
        $router = new Router();
        $auth = new AuthController();
        $roles = new RolesController();
        $authCheck = new AuthCheck();

        // Auth público
        $router->post('/api/auth/login', [$auth, 'login']);

        // Auth autenticado
        $router->post('/api/auth/logout', [$auth, 'logout'], [$authCheck]);
        $router->get('/api/auth/yo', [$auth, 'yo'], [$authCheck]);
        $router->post('/api/auth/cambiar-contrasena', [$auth, 'cambiarContrasena'], [$authCheck]);
        $router->post('/api/auth/reset-temporal', [$auth, 'resetearTemporal'], [
            $authCheck,
            new PermissionCheck('usuarios.resetear_password'),
        ]);

        // RBAC
        $router->get('/api/roles', [$roles, 'listar'], [$authCheck, new PermissionCheck('ajustes.acceder')]);
        $router->get('/api/roles/{id}', [$roles, 'obtener'], [$authCheck, new PermissionCheck('ajustes.acceder')]);
        $router->post('/api/roles', [$roles, 'crear'], [$authCheck, new PermissionCheck('permisos.asignar_a_rol')]);
        $router->put('/api/roles/{id}', [$roles, 'actualizar'], [$authCheck, new PermissionCheck('permisos.asignar_a_rol')]);
        $router->delete('/api/roles/{id}', [$roles, 'eliminar'], [$authCheck, new PermissionCheck('permisos.asignar_a_rol')]);
        $router->get('/api/permisos', [$roles, 'listarPermisos'], [$authCheck, new PermissionCheck('ajustes.acceder')]);

        $router->post('/api/usuarios/{id}/roles', [$roles, 'asignarRolAUsuario'], [
            $authCheck,
            new PermissionCheck('usuarios.editar'),
        ]);
        $router->delete('/api/usuarios/{id}/roles/{rolId}', [$roles, 'quitarRolAUsuario'], [
            $authCheck,
            new PermissionCheck('usuarios.editar'),
        ]);

        return $router;
    }
}
