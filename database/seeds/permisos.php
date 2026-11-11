<?php

declare(strict_types=1);

return [
    ['habitaciones.ver_todas', 'Ver estado de todas las habitaciones', 'Habitaciones', 'global'],
    ['habitaciones.ver_asignadas_propias', 'Ver solo las habitaciones asignadas al propio usuario', 'Habitaciones', 'propio'],
    ['habitaciones.marcar_completada', 'Marcar una habitación propia como terminada', 'Habitaciones', 'propio'],
    ['habitaciones.saltar', 'Saltar la habitación propia en curso cuando no se puede terminar', 'Habitaciones', 'propio'],
    ['habitaciones.ver_historial', 'Ver historial completo de una habitación', 'Habitaciones', 'global'],
    ['habitaciones.marcar_limpia_manual', 'Marcar una habitación como limpia sin checklist (queda pendiente de auditoría)', 'Habitaciones', 'global'],
    ['habitaciones.marcar_nochero', 'Marcar/desmarcar una habitación como nochero (aseo doble por turno minero)', 'Habitaciones', 'global'],
    ['habitaciones.agregar_nota', 'Dejar/quitar la nota de Recepción para la mucama en una habitación', 'Habitaciones', 'global'],
    // v7: antes alcanzaba con habitaciones.ver_todas, así que Recepción también podía crear y borrar edificios.
    ['habitaciones.gestionar_edificios', 'Crear, editar y borrar edificios y asignar las habitaciones a edificio y piso (Ajustes → Edificios y Mapeo)', 'Habitaciones', 'global'],
    // v7.5: la habitación «PRUEBA» de cada hotel (smoke tests sin Cloudbeds ni avisos). Solo Admin por defecto.
    ['habitaciones.ver_prueba', 'Ver y usar la habitación de prueba «PRUEBA» de cada hotel (sin Cloudbeds, fuera de KPIs y avisos); solo se le asigna a quien tiene este permiso', 'Habitaciones', 'global'],

    ['checklists.ver', 'Ver los templates de checklist', 'Checklists', 'global'],
    ['checklists.editar', 'Modificar items de un template existente', 'Checklists', 'global'],
    ['checklists.crear_nuevos', 'Crear templates nuevos', 'Checklists', 'global'],
    // v7.3 (gerencia, 10/10/2026): las acciones se eligen de listas, sin cuadro de texto. Solo Admin por defecto.
    ['listas.editar', 'Editar las listas de selección: motivos de rechazo, de «No se pudo inspeccionar», de saltar y de reasignar una habitación, del NO de Recepción, mensajes a la mucama, tipos y respuestas de tickets y áreas de «Cambio Tarea» (Ajustes → Checklists y listas)', 'Checklists', 'global'],

    ['asignaciones.asignar_manual', 'Asignar/reasignar habitaciones manualmente', 'Asignaciones', 'global'],
    ['asignaciones.auto_asignar', 'Ejecutar round-robin automático', 'Asignaciones', 'global'],
    ['asignaciones.reordenar_cola_trabajador', 'Reordenar la cola de un trabajador', 'Asignaciones', 'global'],
    ['asignaciones.mover_en_progreso', 'Reasignar o quitar habitaciones que están en progreso (el trabajador pierde lo avanzado)', 'Asignaciones', 'global'],
    // Permiso de marca (rol Apoyo, 01/10/2026): lo lleva todo rol que no es de aseo (decisión de Nicolás, 04/10/2026). Ver RbacService.
    ['asignaciones.excluir_auto', 'No recibe piezas del reparto automático; solo asignación manual (personal de apoyo)', 'Asignaciones', 'propio'],

    ['espacios.ver', 'Ver el listado de áreas comunes', 'Espacios', 'global'],
    ['espacios.crear_editar', 'Crear/editar áreas comunes y su checklist', 'Espacios', 'global'],
    ['espacios.pedir_limpieza', 'Solicitar la limpieza de un área común a un trabajador', 'Espacios', 'global'],
    // v7.27 (áreas beta): decisiones de Nicolás del 11/11/2026. El valor por m² mueve créditos: cambiarlo es de pocos.
    ['espacios.agregar_extra', 'Agregar extras a la limpieza de un área común beta', 'Espacios', 'global'],
    ['espacios.editar_m2', 'Cambiar los m² o quitar las tareas ya guardadas de un área común beta', 'Espacios', 'global'],
    ['tareas.crear', 'Crear tareas nuevas en el catálogo de áreas comunes', 'Espacios', 'global'],
    ['tareas.ver', 'Ver el catálogo de tareas en Ajustes, sin poder cambiarlo', 'Espacios', 'global'],
    ['tareas.editar', 'Editar una tarea ya creada del catálogo: nombre y valor por m²', 'Espacios', 'global'],

    ['auditoria.ver_bandeja', 'Ver la bandeja de habitaciones pendientes de auditar', 'Auditoría', 'global'],
    ['auditoria.aprobar', 'Dar veredicto aprobada', 'Auditoría', 'global'],
    ['auditoria.aprobar_con_observacion', 'Dar veredicto aprobada con observación', 'Auditoría', 'global'],
    ['auditoria.rechazar', 'Dar veredicto rechazada', 'Auditoría', 'global'],
    // v7.11: «No se pudo inspeccionar» (ya hay un huésped en la pieza). Lo tiene quien hoy aprueba.
    ['auditoria.sin_inspeccion', 'Marcar «No se pudo inspeccionar» (por ejemplo, ya hay un huésped): la pieza sale de la bandeja y queda como las que aprueba el sistema', 'Auditoría', 'global'],
    ['auditoria.editar_checklist_durante_auditoria', 'Desmarcar items durante la auditoría', 'Auditoría', 'global'],
    ['auditoria.reordenar_bandeja', 'Reordenar manualmente la bandeja de auditoría', 'Auditoría', 'global'],

    // Inspección pre-entrega (pedido de gerencia, 04/10/2026; en código «revision_entrega»): Recepción
    // registra si una pieza está en condiciones para entregarla al huésped, desde la tarjeta de la pieza
    // en Habitaciones. Nunca escribe auditorias. Se guarda para el KPI de calidad de las supervisoras.
    // Ver docs/revision-entrega.md.
    ['revision_entrega.registrar', 'Registrar la inspección pre-entrega de una habitación (SÍ / NO con motivo, observaciones y foto) desde su tarjeta en Habitaciones', 'Inspección pre-entrega', 'global'],
    ['revision_entrega.configurar', 'Configurar la inspección pre-entrega: si un NO devuelve la pieza a sucia (Ajustes → Inspección pre-entrega). Los motivos de un NO se editan con listas.editar', 'Inspección pre-entrega', 'global'],

    ['tickets.crear', 'Crear un ticket de mantenimiento', 'Tickets', 'global'],
    ['tickets.ver_propios', 'Ver solo los tickets propios', 'Tickets', 'propio'],
    ['tickets.ver_todos', 'Ver todos los tickets', 'Tickets', 'global'],
    ['tickets.asignar_a_cualquier_perfil', 'Asignar tickets a usuarios de cualquier perfil (sin este permiso, solo a Trabajadores)', 'Tickets', 'global'],
    ['tickets.editar_prioridad', 'Fijar o cambiar la prioridad de un ticket', 'Tickets', 'global'],
    // v7.22 (reunión del 08/11/2026): quien atiende los tickets. Ve arriba de cada pantalla sus trabajos
    // abiertos y los sin responsable, recibe el aviso de un ticket nuevo sin responsable y el recordatorio
    // de las 08:00. Es lo que elige el atajo «+ Todo Mantenimiento» al asignar.
    ['tickets.aviso_mantencion', 'Atender tickets: ver arriba de cada pantalla los trabajos abiertos, recibir el aviso de cada ticket nuevo sin responsable y el recordatorio diario', 'Tickets', 'global'],

    // v7.23 (reunión del 08/11/2026; solo Admin, decisión de Nicolás del 10/11/2026).
    ['habitaciones.configurar_hora_tarde', 'Cambiar la hora de la tarde (Ajustes): desde ahí las piezas Día/Noche vuelven a sucias para su segundo aseo', 'Habitaciones', 'global'],

    ['usuarios.ver', 'Ver la lista de usuarios', 'Usuarios', 'global'],
    ['usuarios.crear', 'Crear usuarios nuevos', 'Usuarios', 'global'],
    ['usuarios.editar', 'Editar datos de un usuario', 'Usuarios', 'global'],
    ['usuarios.resetear_password', 'Resetear contraseña de otro usuario', 'Usuarios', 'global'],
    ['usuarios.activar_desactivar', 'Dar de baja o reactivar usuarios', 'Usuarios', 'global'],
    ['usuarios.eliminar', 'Eliminar usuarios definitivamente (derecho de cancelación)', 'Usuarios', 'global'],
    ['usuarios.asignar_rol', 'Asignar/remover roles a un usuario', 'Usuarios', 'global'],
    ['usuarios.modo_espia', 'Ver la aplicación como otro usuario (modo espía, solo lectura)', 'Usuarios', 'global'],
    // v7.22 (Nicolás, 10/11/2026): hoy solo Admin y Supervisora son jefaturas; si aparece otra, se le da
    // este permiso en Ajustes sin tocar código. Para abrir el ticket del aviso, el rol necesita además ver los tickets.
    ['usuarios.ser_supervisor', 'Poder ser elegido como supervisor de otras personas (recibe el aviso cuando les asignan un ticket)', 'Usuarios', 'global'],
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
    // v7.16 (gerencia, 06/11/2026): la supervisora anota, el mismo día, las horas que alguien de Aseo
    // estuvo en otra área, para que no le bajen el bono (se descuentan en la v7.18).
    ['cambio_tarea.registrar', 'Anotar en «Cambio Tarea» las horas que alguien de Aseo estuvo hoy en otra área (solo el mismo día)', 'Turnos', 'global'],

    ['copilot.usar_nivel_1_consultas', 'Usar el copilot para consultas', 'Copilot', 'propio'],
    ['copilot.usar_nivel_2_acciones', 'Usar el copilot para ejecutar acciones', 'Copilot', 'propio'],
    ['copilot.ver_historial_propio', 'Ver el historial de conversaciones propias', 'Copilot', 'propio'],
    ['copilot.ver_historial_todos', 'Ver historial de conversaciones de todos', 'Copilot', 'global'],

    ['cloudbeds.ver_estado_sincronizacion', 'Ver estado de sincronización Cloudbeds', 'Cloudbeds', 'global'],
    ['cloudbeds.forzar_sincronizacion', 'Disparar sincronización manual', 'Cloudbeds', 'global'],
    ['cloudbeds.configurar_credenciales', 'Editar configuración Cloudbeds', 'Cloudbeds', 'global'],
    ['habitaciones.importar_inventario', 'Aplicar altas/bajas de habitaciones detectadas en Cloudbeds', 'Cloudbeds', 'global'],

    ['kpis.ver_propios', 'Ver KPIs personales', 'KPIs', 'propio'],
    ['kpis.ver_operativas', 'Ver KPIs operativos del equipo', 'KPIs', 'global'],
    ['kpis.ver_globales', 'Ver KPIs agregados de alto nivel', 'KPIs', 'global'],
    // Permiso que RESTA (rol Apoyo, 01/10/2026): gente de otras áreas que limpia de vez en cuando y cuyo
    // sueldo no depende del aseo. Desde el 04/10/2026 lo lleva todo rol que no es de aseo. Ver RbacService::PERMISO_EXCLUIDO_KPIS.
    ['kpis.excluido', 'No suma créditos ni entra en los KPIs, Reportes ni el bono de aseo (personal de apoyo de otras áreas)', 'KPIs', 'propio'],

    ['alertas.recibir_predictivas', 'Recibir alertas P0-P1 predictivas', 'Alertas', 'global'],
    ['alertas.configurar_umbrales', 'Configurar umbrales de alertas', 'Alertas', 'global'],

    ['sistema.ver_salud', 'Ver estado técnico del sistema', 'Sistema', 'global'],

    ['ajustes.acceder', 'Acceder al módulo de Ajustes', 'Ajustes', 'global'],

    ['apariencia.editar', 'Editar los colores de las tarjetas de la aplicación', 'Apariencia', 'global'],

    ['logs.ver', 'Ver el visor de logs', 'Logs', 'global'],

    ['reportes.ver', 'Ver el módulo de reportes y KPIs exportables', 'Reportes', 'global'],
    // Privacidad jerárquica de tiempos (jefatura, 16/09/2026): nadie ve sus propios tiempos, solo el
    // nivel de arriba. La sección «Supervisora · Inspección» (tiempo por auditación de las
    // supervisoras) queda tras este permiso, pensado para Admin/jefatura; una supervisora con
    // `reportes.ver` ve a sus trabajadoras y nada de sí misma.
    ['reportes.ver_supervisoras', 'Ver los KPIs y tiempos de las supervisoras en Reportes (sección Supervisora · Inspección)', 'Reportes', 'global'],
    // Único dato manual del bono de aseo de RRHH (01/10/2026): el corte de habitaciones diarias, que
    // depende de la ocupación. Pensado solo para administración.
    ['reportes.editar_corte', 'Editar el corte de habitaciones diarias del bono de aseo (Reportes → Resumen mensual)', 'Reportes', 'global'],
    // v7.17 (gerencia, 06/11/2026; Nicolás 08/11/2026): las horas extras del mes de cada persona de Aseo
    // las carga administración, en una cuadrícula del año, hasta el 6 del mes siguiente.
    ['horas_extras.registrar', 'Cargar las horas extras del mes del personal de Aseo (Reportes → Mensual → Horas extras)', 'Reportes', 'global'],

    ['disponibilidad.notificar_supervisora', 'Marcarse disponible para más habitaciones', 'Disponibilidad', 'propio'],

    ['notificaciones.ver', 'Ver el centro de notificaciones', 'Notificaciones', 'propio'],

    ['usuarios.exportar_datos_propios', 'Exportar mis propios datos personales (Ley 19.628)', 'Usuarios', 'global'],
];
