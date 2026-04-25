<?php

declare(strict_types=1);

return [
    ['habitaciones.ver_todas', 'Ver estado de todas las habitaciones', 'Habitaciones', 'global'],
    ['habitaciones.ver_asignadas_propias', 'Ver solo las habitaciones asignadas al propio usuario', 'Habitaciones', 'propio'],
    ['habitaciones.marcar_completada', 'Marcar una habitación propia como terminada', 'Habitaciones', 'propio'],
    ['habitaciones.ver_historial', 'Ver historial completo de una habitación', 'Habitaciones', 'global'],

    ['checklists.ver', 'Ver los templates de checklist', 'Checklists', 'global'],
    ['checklists.editar', 'Modificar items de un template existente', 'Checklists', 'global'],
    ['checklists.crear_nuevos', 'Crear templates nuevos', 'Checklists', 'global'],

    ['asignaciones.asignar_manual', 'Asignar/reasignar habitaciones manualmente', 'Asignaciones', 'global'],
    ['asignaciones.auto_asignar', 'Ejecutar round-robin automático', 'Asignaciones', 'global'],
    ['asignaciones.reordenar_cola_trabajador', 'Reordenar la cola de un trabajador', 'Asignaciones', 'global'],

    ['auditoria.ver_bandeja', 'Ver la bandeja de habitaciones pendientes de auditar', 'Auditoría', 'global'],
    ['auditoria.aprobar', 'Dar veredicto aprobada', 'Auditoría', 'global'],
    ['auditoria.aprobar_con_observacion', 'Dar veredicto aprobada con observación', 'Auditoría', 'global'],
    ['auditoria.rechazar', 'Dar veredicto rechazada', 'Auditoría', 'global'],
    ['auditoria.editar_checklist_durante_auditoria', 'Desmarcar items durante la auditoría', 'Auditoría', 'global'],

    ['tickets.crear', 'Crear un ticket de mantenimiento', 'Tickets', 'global'],
    ['tickets.ver_propios', 'Ver solo los tickets propios', 'Tickets', 'propio'],
    ['tickets.ver_todos', 'Ver todos los tickets', 'Tickets', 'global'],

    ['usuarios.ver', 'Ver la lista de usuarios', 'Usuarios', 'global'],
    ['usuarios.crear', 'Crear usuarios nuevos', 'Usuarios', 'global'],
    ['usuarios.editar', 'Editar datos de un usuario', 'Usuarios', 'global'],
    ['usuarios.resetear_password', 'Resetear contraseña de otro usuario', 'Usuarios', 'global'],
    ['usuarios.activar_desactivar', 'Dar de baja o reactivar usuarios', 'Usuarios', 'global'],
    ['usuarios.asignar_rol', 'Asignar/remover roles a un usuario', 'Usuarios', 'global'],
    ['usuarios.cambiar_propia_contrasena', 'Cambiar la propia contraseña', 'Usuarios', 'propio'],

    ['roles.ver', 'Ver los roles del sistema', 'Roles', 'global'],
    ['roles.crear', 'Crear un rol nuevo', 'Roles', 'global'],
    ['roles.editar', 'Editar nombre/descripción de un rol', 'Roles', 'global'],
    ['roles.eliminar', 'Eliminar un rol', 'Roles', 'global'],
    ['permisos.asignar_a_rol', 'Editar la matriz rol × permiso', 'Roles', 'global'],

    ['turnos.ver', 'Ver los turnos configurados', 'Turnos', 'global'],
    ['turnos.crear_editar', 'Crear o editar definiciones de turnos', 'Turnos', 'global'],
    ['turnos.asignar_a_usuario', 'Asignar un turno a un usuario', 'Turnos', 'global'],
    ['turnos.importar', 'Importar turnos masivamente desde CSV de Breik', 'Turnos', 'global'],

    ['copilot.usar_nivel_1_consultas', 'Usar el copilot para consultas', 'Copilot', 'propio'],
    ['copilot.usar_nivel_2_acciones', 'Usar el copilot para ejecutar acciones', 'Copilot', 'propio'],
    ['copilot.ver_historial_propio', 'Ver el historial de conversaciones propias', 'Copilot', 'propio'],
    ['copilot.ver_historial_todos', 'Ver historial de conversaciones de todos', 'Copilot', 'global'],

    ['cloudbeds.ver_estado_sincronizacion', 'Ver estado de sincronización Cloudbeds', 'Cloudbeds', 'global'],
    ['cloudbeds.forzar_sincronizacion', 'Disparar sincronización manual', 'Cloudbeds', 'global'],
    ['cloudbeds.configurar_credenciales', 'Editar configuración Cloudbeds', 'Cloudbeds', 'global'],

    ['kpis.ver_propios', 'Ver KPIs personales', 'KPIs', 'propio'],
    ['kpis.ver_operativas', 'Ver KPIs operativos del equipo', 'KPIs', 'global'],
    ['kpis.ver_globales', 'Ver KPIs agregados de alto nivel', 'KPIs', 'global'],

    ['alertas.recibir_predictivas', 'Recibir alertas P0-P1 predictivas', 'Alertas', 'global'],
    ['alertas.configurar_umbrales', 'Configurar umbrales de alertas', 'Alertas', 'global'],

    ['sistema.ver_salud', 'Ver estado técnico del sistema', 'Sistema', 'global'],

    ['ajustes.acceder', 'Acceder al módulo de Ajustes', 'Ajustes', 'global'],

    ['logs.ver', 'Ver el visor de logs', 'Logs', 'global'],

    ['reportes.ver', 'Ver el módulo de reportes y KPIs exportables', 'Reportes', 'global'],

    ['disponibilidad.notificar_supervisora', 'Marcarse disponible para más habitaciones', 'Disponibilidad', 'propio'],

    ['notificaciones.ver', 'Ver el centro de notificaciones', 'Notificaciones', 'propio'],
];
