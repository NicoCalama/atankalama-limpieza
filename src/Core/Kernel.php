<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Core;

use Atankalama\Limpieza\Controllers\AlertasController;
use Atankalama\Limpieza\Controllers\NotificacionesController;
use Atankalama\Limpieza\Controllers\ReportesController;
use Atankalama\Limpieza\Controllers\AsignacionesController;
use Atankalama\Limpieza\Controllers\AuditoriaController;
use Atankalama\Limpieza\Controllers\AuthController;
use Atankalama\Limpieza\Controllers\ChecklistsController;
use Atankalama\Limpieza\Controllers\CloudbedsController;
use Atankalama\Limpieza\Controllers\CopilotController;
use Atankalama\Limpieza\Controllers\EdificiosController;
use Atankalama\Limpieza\Controllers\EspaciosController;
use Atankalama\Limpieza\Controllers\FestivosController;
use Atankalama\Limpieza\Controllers\HabitacionesController;
use Atankalama\Limpieza\Controllers\HomeController;
use Atankalama\Limpieza\Controllers\InventarioController;
use Atankalama\Limpieza\Controllers\ModoEspiaController;
use Atankalama\Limpieza\Controllers\PaginasController;
use Atankalama\Limpieza\Controllers\RolesController;
use Atankalama\Limpieza\Controllers\PushController;
use Atankalama\Limpieza\Controllers\SistemaController;
use Atankalama\Limpieza\Controllers\TicketsController;
use Atankalama\Limpieza\Controllers\TurnosController;
use Atankalama\Limpieza\Controllers\TurnosImportController;
use Atankalama\Limpieza\Controllers\UiConfigController;
use Atankalama\Limpieza\Controllers\UploadsController;
use Atankalama\Limpieza\Controllers\UsuariosController;
use Atankalama\Limpieza\Controllers\UsuariosImportController;
use Atankalama\Limpieza\Middleware\AuthCheck;
use Atankalama\Limpieza\Middleware\OptionalAuth;
use Atankalama\Limpieza\Middleware\PermissionCheck;

final class Kernel
{
    public static function construirRouter(): Router
    {
        $router = new Router();
        $auth = new AuthController();
        $roles = new RolesController();
        $authCheck = new AuthCheck();
        $optionalAuth = new OptionalAuth();

        // Páginas HTML
        $paginas = new PaginasController();
        // Manifest PWA dinámico (público, lo pide el navegador sin sesión).
        // Es una ruta y no un archivo estático para que start_url/scope/iconos
        // salgan con BASE_PATH (dev raíz vs prod subpath). Sin extensión a
        // propósito: el server embebido de PHP no rutea URIs con extensión.
        $router->get('/manifest', [$paginas, 'manifest']);
        // Recursos modulares de vistas (CSS y JS extraídos a views/recursos/). Públicos: los
        // pide el navegador como <script>/<link>; servirRecurso solo entrega .css/.js de esa
        // carpeta. En desarrollo requiere el router del server embebido (ver public/index.php).
        $router->get('/views/recursos/{ruta*}', [$paginas, 'servirRecurso']);
        $router->get('/', [$paginas, 'raiz'], [$optionalAuth]);
        $router->get('/login', [$paginas, 'login'], [$optionalAuth]);
        $router->get('/home', [$paginas, 'home'], [$optionalAuth]);
        $router->get('/cambiar-contrasena', [$paginas, 'cambiarContrasena'], [$optionalAuth]);
        $router->get('/edificios', [$paginas, 'edificios'], [$optionalAuth]);
        $router->get('/habitaciones', [$paginas, 'habitaciones'], [$optionalAuth]);
        $router->get('/habitaciones/{id}', [$paginas, 'habitacionDetalle'], [$optionalAuth]);
        $router->get('/auditoria', [$paginas, 'auditoriaBandeja'], [$optionalAuth]);
        $router->get('/auditoria/{id}', [$paginas, 'auditoriaDetalle'], [$optionalAuth]);
        $router->get('/asignaciones', [$paginas, 'asignaciones'], [$optionalAuth]);
        $router->get('/alertas', [$paginas, 'alertas'], [$optionalAuth]);
        $router->get('/espacios', [$paginas, 'espacios'], [$optionalAuth]);
        $router->get('/tickets', [$paginas, 'tickets'], [$optionalAuth]);
        $router->get('/usuarios', [$paginas, 'usuarios'], [$optionalAuth]);
        $router->get('/ajustes', [$paginas, 'ajustes'], [$optionalAuth]);
        $router->get('/ajustes/mi-cuenta', [$paginas, 'ajustesMiCuenta'], [$optionalAuth]);
        $router->get('/ajustes/turnos', [$paginas, 'ajustesTurnos'], [$optionalAuth]);
        $router->get('/ajustes/alertas', [$paginas, 'ajustesAlertas'], [$optionalAuth]);
        $router->get('/ajustes/rbac', [$paginas, 'ajustesRbac'], [$optionalAuth]);
        $router->get('/ajustes/checklists', [$paginas, 'ajustesChecklists'], [$optionalAuth]);
        $router->get('/ajustes/colores', [$paginas, 'ajustesColores'], [$optionalAuth]);
        $router->get('/ajustes/versiones', [$paginas, 'ajustesVersiones'], [$optionalAuth]);
        $router->get('/ajustes/importar-turnos', [$paginas, 'ajustesImportarTurnos'], [$optionalAuth]);
        $router->get('/reportes', [$paginas, 'reportes'], [$optionalAuth]);

        // Auth público
        $router->post('/api/auth/login', [$auth, 'login']);
        $router->post('/api/auth/recuperar', [$auth, 'recuperar']);

        // Auth autenticado
        $router->post('/api/auth/logout', [$auth, 'logout'], [$authCheck]);
        $router->get('/api/auth/yo', [$auth, 'yo'], [$authCheck]);
        $router->post('/api/auth/cambiar-contrasena', [$auth, 'cambiarContrasena'], [$authCheck]);
        $router->post('/api/auth/reset-temporal', [$auth, 'resetearTemporal'], [
            $authCheck,
            new PermissionCheck('usuarios.resetear_password'),
        ]);

        // Home API
        $home = new HomeController();
        $router->get('/api/home/trabajador', [$home, 'trabajador'], [$authCheck]);
        $router->post('/api/disponibilidad/avisar', [$home, 'avisarDisponibilidad'], [$authCheck]);
        $router->get('/api/home/recepcion', [$home, 'recepcion'], [
            $authCheck,
            new PermissionCheck('auditoria.ver_bandeja'),
        ]);
        $router->get('/api/home/supervisora', [$home, 'supervisora'], [
            $authCheck,
            new PermissionCheck('habitaciones.ver_todas'),
        ]);
        $router->get('/api/home/admin', [$home, 'admin'], [
            $authCheck,
            new PermissionCheck('ajustes.acceder'),
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
        $router->get('/api/habitaciones/{id}', [$habitaciones, 'obtener'], [$authCheck]);
        $router->put('/api/habitaciones/{id}/estructura', [$habitaciones, 'actualizarEstructura'], [
            $authCheck,
            new PermissionCheck('habitaciones.ver_todas'), // Supervisora/Admin pueden editar
        ]);
        $router->get('/api/habitaciones/{id}/historial', [$habitaciones, 'historial'], [
            $authCheck,
            new PermissionCheck('habitaciones.ver_historial'),
        ]);
        $router->get('/api/habitaciones/{id}/historial/exportar', [$habitaciones, 'historialExportar'], [
            $authCheck,
            new PermissionCheck('habitaciones.ver_historial'),
        ]);
        $router->get('/api/habitaciones/{id}/auditoria', [$habitaciones, 'auditoriaActual'], [
            $authCheck,
            new PermissionCheck('auditoria.ver_bandeja'),
        ]);
        $router->post('/api/habitaciones/{id}/marcar-limpia', [$habitaciones, 'marcarLimpiaManual'], [
            $authCheck,
            new PermissionCheck('habitaciones.marcar_limpia_manual'),
        ]);
        $router->post('/api/habitaciones/{id}/marcar-sucia', [$habitaciones, 'marcarSuciaManual'], [
            $authCheck,
            new PermissionCheck('habitaciones.marcar_limpia_manual'),
        ]);
        $router->post('/api/habitaciones/{id}/sin-aseo-cliente', [$habitaciones, 'marcarSinAseoCliente'], [
            $authCheck,
            new PermissionCheck('habitaciones.marcar_limpia_manual'),
        ]);
        $router->get('/api/habitaciones/{id}/movimientos', [$habitaciones, 'movimientos'], [
            $authCheck,
            new PermissionCheck('habitaciones.ver_historial'),
        ]);
        $router->get('/api/habitaciones/{id}/movimientos/exportar', [$habitaciones, 'movimientosExportar'], [
            $authCheck,
            new PermissionCheck('habitaciones.ver_historial'),
        ]);
        $router->put('/api/habitaciones/{id}/nochero', [$habitaciones, 'marcarNochero'], [
            $authCheck,
            new PermissionCheck('habitaciones.marcar_nochero'),
        ]);
        $router->delete('/api/habitaciones/{id}/nochero', [$habitaciones, 'desmarcarNochero'], [
            $authCheck,
            new PermissionCheck('habitaciones.marcar_nochero'),
        ]);
        $router->post('/api/habitaciones/{id}/nota', [$habitaciones, 'agregarNota'], [
            $authCheck,
            new PermissionCheck('habitaciones.agregar_nota'),
        ]);
        $router->delete('/api/habitaciones/{id}/nota', [$habitaciones, 'quitarNota'], [
            $authCheck,
            new PermissionCheck('habitaciones.agregar_nota'),
        ]);

        // Edificios
        $edificiosCtrl = new EdificiosController();
        $router->get('/api/edificios', [$edificiosCtrl, 'listar'], [$authCheck, new PermissionCheck('habitaciones.ver_todas')]);
        $router->post('/api/edificios', [$edificiosCtrl, 'crear'], [$authCheck, new PermissionCheck('habitaciones.ver_todas')]);
        $router->put('/api/edificios/{id}', [$edificiosCtrl, 'actualizar'], [$authCheck, new PermissionCheck('habitaciones.ver_todas')]);
        $router->delete('/api/edificios/{id}', [$edificiosCtrl, 'eliminar'], [$authCheck, new PermissionCheck('habitaciones.ver_todas')]);

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

        // Inventario — aplicar/rechazar las altas/bajas de piezas detectadas en Cloudbeds
        // (la detección + alerta vive en InventarioCheckService, disparada por el cron).
        $inventario = new InventarioController();
        $router->post('/api/inventario/aplicar', [$inventario, 'aplicar'], [
            $authCheck,
            new PermissionCheck('habitaciones.importar_inventario'),
        ]);
        $router->post('/api/inventario/rechazar', [$inventario, 'rechazar'], [
            $authCheck,
            new PermissionCheck('habitaciones.importar_inventario'),
        ]);

        // Checklists y ejecuciones
        $checklists = new ChecklistsController();
        $router->get('/api/checklists/templates', [$checklists, 'listarTemplates'], [
            $authCheck,
            new PermissionCheck('checklists.ver'),
        ]);
        $router->get('/api/checklists/config', [$checklists, 'config'], [
            $authCheck,
            new PermissionCheck('checklists.ver'),
        ]);
        $router->put('/api/checklists/config', [$checklists, 'guardarConfig'], [
            $authCheck,
            new PermissionCheck('checklists.editar'),
        ]);
        $router->get('/api/checklists/templates/{id}/items', [$checklists, 'itemsDelTemplate'], [
            $authCheck,
            new PermissionCheck('checklists.ver'),
        ]);
        $router->get('/api/checklists/templates/{id}/historial', [$checklists, 'historialDeTemplate'], [
            $authCheck,
            new PermissionCheck('checklists.ver'),
        ]);
        $router->put('/api/checklists/templates/{id}', [$checklists, 'editarTemplate'], [
            $authCheck,
            new PermissionCheck('checklists.editar'),
        ]);
        $router->post('/api/habitaciones/{id}/iniciar', [$checklists, 'iniciar'], [$authCheck]);
        $router->post('/api/habitaciones/{id}/completar', [$checklists, 'completar'], [
            $authCheck,
            new PermissionCheck('habitaciones.marcar_completada'),
        ]);
        $router->post('/api/habitaciones/{id}/saltar', [$checklists, 'saltar'], [
            $authCheck,
            new PermissionCheck('habitaciones.saltar'),
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
        $router->post('/api/asignaciones/desasignar', [$asignaciones, 'desasignar'], [
            $authCheck,
            new PermissionCheck('asignaciones.asignar_manual'),
        ]);
        $router->put('/api/asignaciones/orden', [$asignaciones, 'reordenar'], [
            $authCheck,
            new PermissionCheck('asignaciones.reordenar_cola_trabajador'),
        ]);
        $router->get('/api/usuarios/{id}/cola', [$asignaciones, 'colaTrabajador'], [$authCheck]);
        $router->get('/api/asignaciones/vista', [$asignaciones, 'vista'], [
            $authCheck,
            new PermissionCheck('asignaciones.asignar_manual'),
        ]);

        // Apariencia (Ajustes → Colores) — colores de tarjetas por estado y hotel
        $uiConfig = new UiConfigController();
        $router->get('/api/ui-config/colores', [$uiConfig, 'obtenerColores'], [
            $authCheck,
            new PermissionCheck('apariencia.editar'),
        ]);
        $router->put('/api/ui-config/colores', [$uiConfig, 'guardarColores'], [
            $authCheck,
            new PermissionCheck('apariencia.editar'),
        ]);

        // Espacios (áreas comunes) — ver docs/areas-comunes.md
        $espacios = new EspaciosController();
        $router->get('/api/espacios', [$espacios, 'listar'], [
            $authCheck,
            new PermissionCheck('espacios.ver'),
        ]);
        // Antes de /api/espacios/{id}: {id} captura cualquier segmento y el router resuelve
        // por orden de registro, así que 'exportar' calzaría con {id} si esta ruta fuera después.
        $router->get('/api/espacios/exportar', [$espacios, 'exportar'], [
            $authCheck,
            new PermissionCheck('espacios.ver'),
        ]);
        $router->get('/api/espacios/{id}', [$espacios, 'obtener'], [
            $authCheck,
            new PermissionCheck('espacios.ver'),
        ]);
        $router->post('/api/espacios', [$espacios, 'crear'], [
            $authCheck,
            new PermissionCheck('espacios.crear_editar'),
        ]);
        $router->put('/api/espacios/{id}', [$espacios, 'editar'], [
            $authCheck,
            new PermissionCheck('espacios.crear_editar'),
        ]);
        $router->delete('/api/espacios/{id}', [$espacios, 'archivar'], [
            $authCheck,
            new PermissionCheck('espacios.crear_editar'),
        ]);
        $router->post('/api/espacios/{id}/pedir-limpieza', [$espacios, 'pedirLimpieza'], [
            $authCheck,
            new PermissionCheck('espacios.pedir_limpieza'),
        ]);

        // Auditoría
        $auditoria = new AuditoriaController();
        $router->get('/api/auditoria/bandeja', [$auditoria, 'bandeja'], [
            $authCheck,
            new PermissionCheck('auditoria.ver_bandeja'),
        ]);
        // Ruta literal ANTES de /api/auditoria/{id}, mismo motivo que
        // /api/tickets/usuarios-asignables: el router matchea en orden de registro.
        $router->put('/api/auditoria/orden', [$auditoria, 'reordenarBandeja'], [
            $authCheck,
            new PermissionCheck('auditoria.reordenar_bandeja'),
        ]);
        $router->post('/api/auditoria/{id}', [$auditoria, 'emitirVeredicto'], [$authCheck]);
        $router->get('/api/auditoria/{id}/historial', [$auditoria, 'historial'], [
            $authCheck,
            new PermissionCheck('auditoria.ver_bandeja'),
        ]);
        // Apertura de la pieza en inspección (KPI "tiempo por auditación"). Tiene un segmento
        // más que /api/auditoria/{id}, así no colisiona con el POST del veredicto.
        $router->post('/api/auditoria/{id}/iniciar', [$auditoria, 'iniciar'], [
            $authCheck,
            new PermissionCheck('auditoria.ver_bandeja'),
        ]);

        // Tickets
        $tickets = new TicketsController();
        $router->get('/api/tickets', [$tickets, 'listar'], [$authCheck]);
        // Ruta literal ANTES de /api/tickets/{id}: el router matchea en orden de registro y
        // {id} es un wildcard — si esta va después, nunca se alcanza (la captura {id} le gana).
        $router->get('/api/tickets/usuarios-asignables', [$tickets, 'usuariosAsignables'], [
            $authCheck,
            new PermissionCheck('tickets.ver_todos'),
        ]);
        $router->get('/api/tickets/{id}', [$tickets, 'obtener'], [$authCheck]);
        $router->post('/api/tickets', [$tickets, 'crear'], [
            $authCheck,
            new PermissionCheck('tickets.crear'),
        ]);
        // Sin PermissionCheck acá a propósito: quien solo tiene tickets.ver_propios puede
        // "tomar" (autoasignarse) un ticket sin dueño — asignar a un tercero sigue exigiendo
        // tickets.ver_todos, la regla fina vive en el controller. Ver TicketsController::asignar().
        $router->put('/api/tickets/{id}/asignar', [$tickets, 'asignar'], [$authCheck]);
        // Sin PermissionCheck acá a propósito: quien tiene el ticket asignado también puede
        // marcarlo resuelto (no cerrarlo ni reabrirlo) — la regla fina vive en el controller,
        // que sí conoce el ticket concreto. Ver TicketsController::cambiarEstado().
        $router->put('/api/tickets/{id}/estado', [$tickets, 'cambiarEstado'], [$authCheck]);
        // A diferencia de /asignar y /estado (regla fina en el controller), acá un solo
        // permiso decide todo — mismo patrón que /usuarios-asignables.
        $router->put('/api/tickets/{id}/prioridad', [$tickets, 'cambiarPrioridad'], [
            $authCheck,
            new PermissionCheck('tickets.editar_prioridad'),
        ]);
        // Sin PermissionCheck acá a propósito: mismo criterio que ver el ticket (dueño,
        // asignado, o tickets.ver_todos) — la regla vive en TicketsController::puedeVerTicket().
        $router->get('/api/tickets/{id}/comentarios', [$tickets, 'comentarios'], [$authCheck]);
        $router->post('/api/tickets/{id}/comentarios', [$tickets, 'comentar'], [$authCheck]);
        $router->post('/api/tickets/{id}/cerrar', [$tickets, 'cerrar'], [
            $authCheck,
            new PermissionCheck('tickets.ver_todos'),
        ]);

        // Usuarios CRUD
        $usuarios = new UsuariosController();
        $router->get('/api/usuarios', [$usuarios, 'listar'], [
            $authCheck,
            new PermissionCheck('usuarios.ver'),
        ]);
        $router->get('/api/usuarios/{id}', [$usuarios, 'obtener'], [
            $authCheck,
            new PermissionCheck('usuarios.ver'),
        ]);
        $router->post('/api/usuarios', [$usuarios, 'crear'], [
            $authCheck,
            new PermissionCheck('usuarios.crear'),
        ]);
        $router->put('/api/usuarios/{id}', [$usuarios, 'actualizar'], [
            $authCheck,
            new PermissionCheck('usuarios.editar'),
        ]);
        $router->post('/api/usuarios/{id}/activar', [$usuarios, 'activar'], [
            $authCheck,
            new PermissionCheck('usuarios.activar_desactivar'),
        ]);
        $router->post('/api/usuarios/{id}/desactivar', [$usuarios, 'desactivar'], [
            $authCheck,
            new PermissionCheck('usuarios.activar_desactivar'),
        ]);
        $router->delete('/api/usuarios/{id}', [$usuarios, 'eliminar'], [
            $authCheck,
            new PermissionCheck('usuarios.eliminar'),
        ]);
        // Derecho de acceso a datos personales (Ley 19.628 art. 12).
        // No usa PermissionCheck: el control vive en el controller porque permite tanto
        // al propio usuario como a un admin con usuarios.editar consultar los datos.
        $router->get('/api/usuarios/{id}/datos-personales', [$usuarios, 'exportarDatos'], [$authCheck]);

        // Modo espía: ver la app como otro usuario, solo lectura (docs/contexto).
        $modoEspia = new ModoEspiaController();
        $router->post('/api/usuarios/{id}/modo-espia/activar', [$modoEspia, 'activar'], [
            $authCheck,
            new PermissionCheck('usuarios.modo_espia'),
        ]);
        // Sin PermissionCheck a propósito: es la única ruta mutante que AuthCheck deja
        // pasar mientras el modo espía está activo (ver AuthCheck::RUTA_SALIR_MODO_ESPIA).
        $router->post('/api/modo-espia/salir', [$modoEspia, 'salir'], [$authCheck]);

        // Carga masiva de usuarios desde el calendario semanal .xlsx (mismo permiso que
        // crear uno solo: importar N es la misma acción de alta, repetida).
        $usuariosImport = new UsuariosImportController();
        $router->post('/api/usuarios/importar/preview', [$usuariosImport, 'preview'], [
            $authCheck,
            new PermissionCheck('usuarios.crear'),
        ]);
        $router->post('/api/usuarios/importar/confirmar', [$usuariosImport, 'confirmar'], [
            $authCheck,
            new PermissionCheck('usuarios.crear'),
        ]);

        // Turnos
        $turnos = new TurnosController();
        $router->get('/api/turnos', [$turnos, 'listar'], [
            $authCheck,
            new PermissionCheck('turnos.ver'),
        ]);
        $router->post('/api/turnos', [$turnos, 'crear'], [
            $authCheck,
            new PermissionCheck('turnos.crear_editar'),
        ]);
        $router->put('/api/turnos/{id}', [$turnos, 'actualizar'], [
            $authCheck,
            new PermissionCheck('turnos.crear_editar'),
        ]);
        $router->post('/api/usuarios/{id}/turno', [$turnos, 'asignarAUsuario'], [
            $authCheck,
            new PermissionCheck('turnos.asignar_a_usuario'),
        ]);
        $router->delete('/api/usuarios/{id}/turno', [$turnos, 'quitarDeUsuario'], [
            $authCheck,
            new PermissionCheck('turnos.asignar_a_usuario'),
        ]);
        $router->post('/api/usuarios/{id}/turno/rango', [$turnos, 'asignarRangoAUsuario'], [
            $authCheck,
            new PermissionCheck('turnos.asignar_a_usuario'),
        ]);
        $router->get('/api/turnos/dia', [$turnos, 'turnosDelDia'], [
            $authCheck,
            new PermissionCheck('turnos.ver'),
        ]);

        // Festivos (informativo, se muestran en el calendario de turnos)
        $festivos = new FestivosController();
        $router->get('/api/festivos', [$festivos, 'listar'], [
            $authCheck,
            new PermissionCheck('turnos.ver'),
        ]);
        $router->post('/api/festivos', [$festivos, 'crear'], [
            $authCheck,
            new PermissionCheck('turnos.asignar_a_usuario'),
        ]);
        $router->delete('/api/festivos/{id}', [$festivos, 'eliminar'], [
            $authCheck,
            new PermissionCheck('turnos.asignar_a_usuario'),
        ]);

        // Alertas
        $alertas = new AlertasController();
        $router->get('/api/alertas/activas', [$alertas, 'activas'], [
            $authCheck,
            new PermissionCheck('alertas.recibir_predictivas'),
        ]);
        $router->get('/api/alertas', [$alertas, 'listar'], [
            $authCheck,
            new PermissionCheck('alertas.recibir_predictivas'),
        ]);
        $router->post('/api/alertas/{id}/accion', [$alertas, 'ejecutarAccion'], [
            $authCheck,
            new PermissionCheck('alertas.recibir_predictivas'),
        ]);
        $router->get('/api/alertas/bitacora', [$alertas, 'bitacora'], [
            $authCheck,
            new PermissionCheck('alertas.recibir_predictivas'),
        ]);
        $router->get('/api/alertas/config', [$alertas, 'listarConfig'], [
            $authCheck,
            new PermissionCheck('alertas.configurar_umbrales'),
        ]);
        $router->put('/api/alertas/config', [$alertas, 'actualizarConfig'], [
            $authCheck,
            new PermissionCheck('alertas.configurar_umbrales'),
        ]);
        $router->post('/api/alertas/recalcular', [$alertas, 'recalcular'], [
            $authCheck,
            new PermissionCheck('alertas.configurar_umbrales'),
        ]);

        // Copilot IA
        $copilot = new CopilotController();
        $router->post('/api/copilot/mensaje', [$copilot, 'mensaje'], [
            $authCheck,
            new PermissionCheck('copilot.usar_nivel_1_consultas'),
        ]);
        $router->get('/api/copilot/conversaciones', [$copilot, 'listarConversaciones'], [
            $authCheck,
            new PermissionCheck('copilot.ver_historial_propio'),
        ]);
        $router->get('/api/copilot/conversaciones/todas', [$copilot, 'listarTodasConversaciones'], [
            $authCheck,
            new PermissionCheck('copilot.ver_historial_todos'),
        ]);
        $router->get('/api/copilot/conversaciones/{id}', [$copilot, 'obtenerConversacion'], [
            $authCheck,
            new PermissionCheck('copilot.ver_historial_propio'),
        ]);
        $router->delete('/api/copilot/conversaciones/{id}', [$copilot, 'borrarConversacion'], [$authCheck]);

        // Push Notifications
        $push = new PushController();
        $router->get('/api/push/vapid-public-key', [$push, 'vapidPublicKey'], [$authCheck]);
        $router->post('/api/push/suscribir', [$push, 'suscribir'], [$authCheck]);
        $router->delete('/api/push/suscribir', [$push, 'desuscribir'], [$authCheck]);

        // Notificaciones (inbox por usuario)
        $notif = new NotificacionesController();
        $router->get('/api/notificaciones', [$notif, 'listar'], [$authCheck]);
        $router->get('/api/notificaciones/sin-leer', [$notif, 'sinLeer'], [$authCheck]);
        $router->delete('/api/notificaciones/{id}', [$notif, 'eliminar'], [$authCheck]);
        $router->delete('/api/notificaciones', [$notif, 'eliminarTodas'], [$authCheck]);

        // Reportes y KPIs
        $reportes = new ReportesController();
        $router->get('/api/reportes/kpis', [$reportes, 'kpis'], [
            $authCheck,
            new PermissionCheck('reportes.ver'),
        ]);
        $router->get('/api/reportes/ficha', [$reportes, 'ficha'], [
            $authCheck,
            new PermissionCheck('reportes.ver'),
        ]);
        $router->get('/api/reportes/resumen-mensual', [$reportes, 'resumenMensual'], [
            $authCheck,
            new PermissionCheck('reportes.ver'),
        ]);
        $router->get('/api/reportes/exportar-mensual', [$reportes, 'exportarMensual'], [
            $authCheck,
            new PermissionCheck('reportes.ver'),
        ]);
        $router->get('/api/reportes/resumen-mensual-auditores', [$reportes, 'resumenMensualAuditores'], [
            $authCheck,
            new PermissionCheck('reportes.ver'),
        ]);
        $router->get('/api/reportes/exportar-mensual-auditores', [$reportes, 'exportarMensualAuditores'], [
            $authCheck,
            new PermissionCheck('reportes.ver'),
        ]);
        $router->get('/api/reportes/exportar', [$reportes, 'exportar'], [
            $authCheck,
            new PermissionCheck('reportes.ver'),
        ]);
        $router->get('/api/reportes/auditorias-pendientes', [$reportes, 'auditoriasPendientes'], [
            $authCheck,
            new PermissionCheck('reportes.ver'),
        ]);
        $router->get('/api/reportes/exportar-auditorias-pendientes', [$reportes, 'exportarAuditoriasPendientes'], [
            $authCheck,
            new PermissionCheck('reportes.ver'),
        ]);

        // Importación de turnos desde Breik
        $turnosImport = new TurnosImportController();
        $router->post('/api/turnos/importar/preview', [$turnosImport, 'preview'], [
            $authCheck,
            new PermissionCheck('turnos.importar'),
        ]);
        $router->post('/api/turnos/importar/confirmar', [$turnosImport, 'confirmar'], [
            $authCheck,
            new PermissionCheck('turnos.importar'),
        ]);

        // Adjuntos de tickets — público (sin auth): app_core/ está denegado por web, así
        // que las fotos se sirven vía PHP. El link se comparte con Recepción (Novedades),
        // que no tiene cuenta acá. Ver UploadsController para el detalle.
        $uploads = new UploadsController();
        $router->get('/uploads/{ruta*}', [$uploads, 'servir']);

        // Sistema — health check público (sin auth, para uptime monitors)
        $sistema = new SistemaController();
        $router->get('/api/health', [$sistema, 'salud']);

        return $router;
    }
}
