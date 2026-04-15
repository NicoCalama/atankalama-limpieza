<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Core;

use Atankalama\Limpieza\Controllers\AsignacionesController;
use Atankalama\Limpieza\Controllers\AuditoriaController;
use Atankalama\Limpieza\Controllers\AuthController;
use Atankalama\Limpieza\Controllers\ChecklistsController;
use Atankalama\Limpieza\Controllers\CloudbedsController;
use Atankalama\Limpieza\Controllers\HabitacionesController;
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

        // Habitaciones
        $habitaciones = new HabitacionesController();
        $router->get('/api/hoteles', [$habitaciones, 'listarHoteles'], [$authCheck]);
        $router->get('/api/habitaciones', [$habitaciones, 'listar'], [
            $authCheck,
            new PermissionCheck('habitaciones.ver_todas'),
        ]);
        $router->get('/api/habitaciones/{id}', [$habitaciones, 'obtener'], [
            $authCheck,
            new PermissionCheck('habitaciones.ver_todas'),
        ]);

        // Cloudbeds
        $cloudbeds = new CloudbedsController();
        $router->get('/api/cloudbeds/estado', [$cloudbeds, 'estado'], [
            $authCheck,
            new PermissionCheck('cloudbeds.ver_estado_sincronizacion'),
        ]);
        $router->get('/api/cloudbeds/historial', [$cloudbeds, 'historial'], [
            $authCheck,
            new PermissionCheck('cloudbeds.ver_estado_sincronizacion'),
        ]);
        $router->post('/api/cloudbeds/sync', [$cloudbeds, 'sincronizar'], [
            $authCheck,
            new PermissionCheck('cloudbeds.forzar_sincronizacion'),
        ]);
        $router->get('/api/cloudbeds/config', [$cloudbeds, 'listarConfig'], [
            $authCheck,
            new PermissionCheck('cloudbeds.ver_estado_sincronizacion'),
        ]);
        $router->put('/api/cloudbeds/config', [$cloudbeds, 'actualizarConfig'], [
            $authCheck,
            new PermissionCheck('cloudbeds.configurar_credenciales'),
        ]);

        // Checklists y ejecuciones
        $checklists = new ChecklistsController();
        $router->get('/api/checklists/templates', [$checklists, 'listarTemplates'], [
            $authCheck,
            new PermissionCheck('checklists.ver'),
        ]);
        $router->get('/api/checklists/templates/{id}/items', [$checklists, 'itemsDelTemplate'], [
            $authCheck,
            new PermissionCheck('checklists.ver'),
        ]);
        $router->post('/api/habitaciones/{id}/iniciar', [$checklists, 'iniciar'], [$authCheck]);
        $router->post('/api/habitaciones/{id}/completar', [$checklists, 'completar'], [
            $authCheck,
            new PermissionCheck('habitaciones.marcar_completada'),
        ]);
        $router->get('/api/ejecuciones/{id}', [$checklists, 'estadoEjecucion'], [$authCheck]);
        $router->put('/api/ejecuciones/{id}/items/{itemId}', [$checklists, 'marcarItem'], [$authCheck]);

        // Asignaciones
        $asignaciones = new AsignacionesController();
        $router->post('/api/asignaciones', [$asignaciones, 'crear'], [
            $authCheck,
            new PermissionCheck('asignaciones.asignar_manual'),
        ]);
        $router->post('/api/asignaciones/auto', [$asignaciones, 'auto'], [
            $authCheck,
            new PermissionCheck('asignaciones.auto_asignar'),
        ]);
        $router->post('/api/asignaciones/reasignar', [$asignaciones, 'reasignar'], [
            $authCheck,
            new PermissionCheck('asignaciones.asignar_manual'),
        ]);
        $router->put('/api/asignaciones/orden', [$asignaciones, 'reordenar'], [
            $authCheck,
            new PermissionCheck('asignaciones.reordenar_cola_trabajador'),
        ]);
        $router->get('/api/usuarios/{id}/cola', [$asignaciones, 'colaTrabajador'], [$authCheck]);

        // Auditoría
        $auditoria = new AuditoriaController();
        $router->get('/api/auditoria/bandeja', [$auditoria, 'bandeja'], [
            $authCheck,
            new PermissionCheck('auditoria.ver_bandeja'),
        ]);
        $router->post('/api/auditoria/{id}', [$auditoria, 'emitirVeredicto'], [$authCheck]);
        $router->get('/api/auditoria/{id}/historial', [$auditoria, 'historial'], [
            $authCheck,
            new PermissionCheck('auditoria.ver_bandeja'),
        ]);

        return $router;
    }
}
