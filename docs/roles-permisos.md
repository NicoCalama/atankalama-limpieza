# Roles y Permisos — Catálogo canónico RBAC

**Versión:** 1.0 — 2026-04-14
**Fuente de verdad** para el sistema RBAC dinámico de Atankalama Limpieza. Cualquier cambio en permisos (agregar, renombrar, eliminar) debe reflejarse aquí **antes** de tocar código o seeders.

---

## 1. Filosofía: RBAC dinámico

Este proyecto usa **Role-Based Access Control dinámico**. Reglas no negociables:

### 1.1 Regla de oro

```php
// ❌ NUNCA
if ($usuario->rol === 'admin') { ... }

// ✅ SIEMPRE
if ($usuario->tienePermiso('habitaciones.asignar_manual')) { ... }
```

### 1.2 Principios

1. **Los roles son conjuntos de permisos**, no identidades. Un "Admin" es un usuario con el conjunto completo de permisos, no un tipo especial de usuario.
2. **Los permisos son atómicos**: un permiso = una capacidad específica.
3. **Los permisos son globales**, no dependen del hotel. El filtrado por hotel (1 Sur / Inn / Ambos) se hace en UI según el contexto del usuario.
4. **La matriz rol × permiso es editable desde Ajustes**, sin tocar código. El Admin puede quitar o agregar permisos a cualquier rol (incluso a sí mismo — con warning).
5. **Un usuario puede tener múltiples roles**. Sus permisos efectivos son la **unión** de permisos de todos sus roles.
6. **El frontend oculta/desactiva UI según permisos**, pero el backend **siempre valida** con el middleware `PermissionCheck`. Nunca confíes solo en el frontend.

### 1.3 No existen permisos `home.ver_*`

El acceso a cada Home se deriva por **lógica OR** sobre los permisos que habilitan sus secciones. Ejemplo:

- Home Supervisora es visible si el usuario tiene `habitaciones.ver_todas` **OR** `alertas.recibir_predictivas` **OR** `auditoria.ver_bandeja`.
- Home Admin es visible si tiene `alertas.recibir_predictivas` **OR** `kpis.ver_operativas` **OR** `sistema.ver_salud` **OR** `ajustes.acceder`.

El router evalúa qué Home mostrar según el conjunto de permisos del usuario (detalle en [api-endpoints.md](api-endpoints.md) cuando exista).

---

## 2. Catálogo completo de permisos (75 en `database/seeds/permisos.php` al 05/10/2026; esta sección no lista todos — el seed manda)

Formato: `codigo | descripcion | categoria | scope`

### 2.1 Habitaciones

| Código | Descripción | Categoría | Scope |
|---|---|---|---|
| `habitaciones.ver_todas` | Ver estado de todas las habitaciones de ambos hoteles | Habitaciones | global |
| `habitaciones.ver_asignadas_propias` | Ver solo las habitaciones asignadas al propio usuario | Habitaciones | propio |
| `habitaciones.marcar_completada` | Marcar una habitación propia como terminada (pasa a auditoría) | Habitaciones | propio |
| `habitaciones.ver_historial` | Ver historial completo de una habitación (quién la limpió, auditorías previas) | Habitaciones | global |
| `habitaciones.gestionar_edificios` | Crear, editar y borrar edificios y asignar las habitaciones a edificio y piso (Ajustes → Edificios y Mapeo). v7: antes alcanzaba con `habitaciones.ver_todas`, así que Recepción veía la pantalla y podía crear y borrar edificios. Supervisora y Admin; en prod lo conserva todo rol que tenía `ver_todas` menos Recepción (decisión de Nicolás, 05/10/2026) | Habitaciones | global |

### 2.2 Checklists (templates)

| Código | Descripción | Categoría | Scope |
|---|---|---|---|
| `checklists.ver` | Ver los templates de checklist por tipo de habitación | Checklists | global |
| `checklists.editar` | Modificar items de un template existente | Checklists | global |
| `checklists.crear_nuevos` | Crear templates nuevos (para tipo de habitación nuevo) | Checklists | global |

### 2.3 Asignaciones

| Código | Descripción | Categoría | Scope |
|---|---|---|---|
| `asignaciones.asignar_manual` | Asignar/reasignar habitaciones manualmente a un trabajador | Asignaciones | global |
| `asignaciones.auto_asignar` | Ejecutar round-robin automático | Asignaciones | global |
| `asignaciones.reordenar_cola_trabajador` | Reordenar la cola de habitaciones pendientes de un trabajador | Asignaciones | global |
| `asignaciones.mover_en_progreso` | Reasignar o quitar habitaciones que están en progreso (el trabajador pierde lo avanzado). Solo Admin por defecto (v6.7, decisión de jefatura 22/09/2026); sin él, la pieza en progreso se ve bloqueada y el backend responde 403 — ver `docs/asignacion.md` §4.4 | Asignaciones | global |
| `asignaciones.excluir_auto` | **Permiso de marca** (ver §5.2): quien lo tiene no recibe piezas del reparto automático (round-robin), aunque tenga turno; solo asignación manual. Rol Apoyo (01/10/2026) y, desde el 04/10/2026, todo rol que no es de aseo (Admin, Supervisora, Recepción). Migración `scripts/migrate-add-rol-apoyo.php` | Asignaciones | propio |

### 2.4 Auditoría

| Código | Descripción | Categoría | Scope |
|---|---|---|---|
| `auditoria.ver_bandeja` | Ver la bandeja de habitaciones pendientes de auditar | Auditoría | global |
| `auditoria.aprobar` | Dar veredicto "aprobada" | Auditoría | global |
| `auditoria.aprobar_con_observacion` | Dar veredicto "aprobada con observación" (resuelto en el momento) | Auditoría | global |
| `auditoria.rechazar` | Dar veredicto "rechazada" (requiere re-limpieza) | Auditoría | global |
| `auditoria.editar_checklist_durante_auditoria` | Desmarcar items del checklist como parte de una auditoría con observación | Auditoría | global |

### 2.4.1 Inspección pre-entrega (v7; en código «revision_entrega»)

Recepción revisa la pieza antes de entregarla al huésped (SÍ / NO), desde el botón de su tarjeta en Habitaciones. No es la inspección de las supervisoras: nunca escribe `auditorias`. Cada revisión guarda una foto del momento para el KPI de calidad de las supervisoras (que se arma después). Ver [revision-entrega.md](revision-entrega.md).

| Código | Descripción | Categoría | Scope |
|---|---|---|---|
| `revision_entrega.registrar` | Registrar la inspección pre-entrega de una habitación (SÍ / NO con motivo, observaciones y foto) desde su tarjeta en Habitaciones | Inspección pre-entrega | global |
| `revision_entrega.configurar` | Configurar la inspección pre-entrega: motivos de un NO y si un NO devuelve la pieza a sucia (Ajustes → Inspección pre-entrega). Por defecto Supervisora y Admin, no Recepción (decisión de Nicolás, 04/10/2026) | Inspección pre-entrega | global |

Migración `scripts/migrate-add-revision-entrega.php` (o el SQL de `deploy-cpanel.md` §11.15): crea las tablas y los tres permisos de la v7 (estos dos + `habitaciones.gestionar_edificios`) y los concede por rol y a todo rol con `permisos.asignar_a_rol`.

**«Inicio» del menú (v7):** se decide por permisos en `Support\PantallaInicio` (única fuente; la usan `views/home.php`, el menú y la vista guiada). Quien tiene `auditoria.ver_bandeja` sin `ajustes.acceder` ni (`alertas.recibir_predictivas` + `asignaciones.asignar_manual`) no tiene Inicio propio: su Inicio es Habitaciones y el menú no muestra «Inicio» (Recepción, pedido de Nicolás 05/10/2026).

### 2.5 Tickets

| Código | Descripción | Categoría | Scope |
|---|---|---|---|
| `tickets.crear` | Crear un ticket de mantenimiento | Tickets | global |
| `tickets.ver_propios` | Ver solo los tickets levantados por el propio usuario | Tickets | propio |
| `tickets.ver_todos` | Ver todos los tickets del sistema | Tickets | global |

### 2.6 Usuarios

| Código | Descripción | Categoría | Scope |
|---|---|---|---|
| `usuarios.ver` | Ver la lista de usuarios | Usuarios | global |
| `usuarios.crear` | Crear usuarios nuevos | Usuarios | global |
| `usuarios.editar` | Editar datos de un usuario (nombre, email, RUT) | Usuarios | global |
| `usuarios.resetear_password` | Resetear contraseña de otro usuario (genera temporal) | Usuarios | global |
| `usuarios.activar_desactivar` | Dar de baja o reactivar a un usuario | Usuarios | global |
| `usuarios.asignar_rol` | Asignar/remover roles a un usuario | Usuarios | global |
| `usuarios.cambiar_propia_contrasena` | Cambiar la propia contraseña desde "Mi cuenta" | Usuarios | propio |

### 2.7 Roles y permisos (matriz RBAC)

| Código | Descripción | Categoría | Scope |
|---|---|---|---|
| `roles.ver` | Ver los roles del sistema y su composición de permisos | Roles | global |
| `roles.crear` | Crear un rol nuevo | Roles | global |
| `roles.editar` | Editar el nombre/descripción de un rol | Roles | global |
| `roles.eliminar` | Eliminar un rol (solo si no tiene usuarios asignados) | Roles | global |
| `permisos.asignar_a_rol` | Editar la matriz rol × permiso (marcar/desmarcar checkboxes) | Roles | global |

### 2.8 Turnos

| Código | Descripción | Categoría | Scope |
|---|---|---|---|
| `turnos.ver` | Ver los turnos configurados | Turnos | global |
| `turnos.crear_editar` | Crear o editar definiciones de turnos (mañana, tarde, etc.) | Turnos | global |
| `turnos.asignar_a_usuario` | Asignar un turno a un usuario | Turnos | global |

### 2.9 Copilot IA

| Código | Descripción | Categoría | Scope |
|---|---|---|---|
| `copilot.usar_nivel_1_consultas` | Usar el copilot para consultas de datos (read-only) | Copilot | propio |
| `copilot.usar_nivel_2_acciones` | Usar el copilot para ejecutar acciones (asignar, auditar, etc.) | Copilot | propio |
| `copilot.ver_historial_propio` | Ver el historial de conversaciones propias con el copilot | Copilot | propio |
| `copilot.ver_historial_todos` | Ver historial de conversaciones de todos los usuarios (auditoría/debug) | Copilot | global |

**Integración con permisos granulares:** cuando el copilot ejecuta una acción (nivel 2), cada tool disponible se filtra según los permisos del usuario. Ejemplo: si el usuario tiene `copilot.usar_nivel_2_acciones` pero NO tiene `auditoria.aprobar`, la tool "aprobar habitación" no está disponible para el copilot en esa conversación.

### 2.10 Cloudbeds

| Código | Descripción | Categoría | Scope |
|---|---|---|---|
| `cloudbeds.ver_estado_sincronizacion` | Ver el estado de la última sincronización | Cloudbeds | global |
| `cloudbeds.forzar_sincronizacion` | Disparar una sincronización manual | Cloudbeds | global |
| `cloudbeds.configurar_credenciales` | Editar API key y endpoints de Cloudbeds | Cloudbeds | global |

### 2.11 KPIs

| Código | Descripción | Categoría | Scope |
|---|---|---|---|
| `kpis.ver_propios` | Ver KPIs personales (habitaciones hoy, tiempo promedio personal) | KPIs | propio |
| `kpis.ver_operativas` | Ver KPIs operativos del equipo (activos, disponibles, auditadas, etc.) | KPIs | global |
| `kpis.ver_globales` | Ver KPIs agregados de alto nivel (ocupación, tendencias, eficiencia general) | KPIs | global |
| `kpis.excluido` | **Permiso de marca** (ver §5.2): quien lo tiene no suma créditos ni aparece en Reportes, la ficha, el resumen mensual ni el bono de RRHH, y sus limpiezas no mueven los KPIs del equipo (rechazo, aprobación a la 1ª, productividad, ítems desmarcados, cobertura de inspección, tiempo promedio y tasa de rechazo del Inicio del Admin). Lo operativo no cambia: estados, bandeja de inspección, pendientes y alertas. Rol Apoyo (01/10/2026). Se decide con el rol que la persona tiene HOY (no hay historial de roles): si pasa de Apoyo a Trabajador, su trabajo anterior aparece en los reportes de meses pasados. Desde el 04/10/2026 lo lleva también todo rol que no es de aseo (Admin, Supervisora, Recepción): solo saca lo que la persona **limpió**; sus inspecciones siguen contando igual. Migración `scripts/migrate-add-rol-apoyo.php` | KPIs | propio |
| `reportes.ver` | Ver el módulo de reportes y KPIs exportables (página Reportes, ficha de KPIs del trabajador) | Reportes | global |
| `reportes.ver_supervisoras` | Ver los KPIs y tiempos de las supervisoras en Reportes (sección «Supervisora · Inspección»). **Privacidad jerárquica de tiempos** (jefatura, 16/09/2026): nadie ve sus propios tiempos, solo el nivel de arriba → solo roles administradores; una supervisora con `reportes.ver` ve a sus trabajadoras y nada de sí misma. Migración `scripts/migrate-add-permiso-reportes-supervisoras.php` | Reportes | global |
| `reportes.editar_corte` | Editar el corte de habitaciones diarias del bono de aseo (Reportes → Resumen mensual por trabajador). Es el único dato manual del bono de RRHH; depende de la ocupación y se guarda por mes. Solo administración (decisión de Nicolás, 01/10/2026). Migración `scripts/migrate-add-permiso-reportes-editar-corte.php` | Reportes | global |

### 2.12 Alertas

| Código | Descripción | Categoría | Scope |
|---|---|---|---|
| `alertas.recibir_predictivas` | Recibir alertas P0-P1 (trabajador en riesgo, rechazada, fin turno, sync failed) | Alertas | global |
| `alertas.configurar_umbrales` | Configurar umbrales de las alertas predictivas (margen seguridad, etc.) | Alertas | global |

### 2.13 Sistema

| Código | Descripción | Categoría | Scope |
|---|---|---|---|
| `sistema.ver_salud` | Ver estado técnico del sistema (Cloudbeds, BD, usuarios activos, versión, errores) | Sistema | global |

### 2.14 Ajustes

| Código | Descripción | Categoría | Scope |
|---|---|---|---|
| `ajustes.acceder` | Acceder al módulo `/ajustes` (las secciones internas se filtran por sus propios permisos) | Ajustes | global |

### 2.15 Apariencia

| Código | Descripción | Categoría | Scope |
|---|---|---|---|
| `apariencia.editar` | Editar los colores de las tarjetas (Ajustes → Colores). Por defecto: Supervisora y Admin | Apariencia | global |

### 2.16 Logs

| Código | Descripción | Categoría | Scope |
|---|---|---|---|
| `logs.ver` | Ver el visor de logs (logs_eventos + audit_log) | Logs | global |

### 2.16 Disponibilidad

| Código | Descripción | Categoría | Scope |
|---|---|---|---|
| `disponibilidad.notificar_supervisora` | Marcarse como "disponible para más habitaciones" (notifica a Supervisora) | Disponibilidad | propio |

### 2.17 Notificaciones

| Código | Descripción | Categoría | Scope |
|---|---|---|---|
| `notificaciones.ver` | Ver el centro de notificaciones personales | Notificaciones | propio |

---

## 3. Roles por defecto (5)

Los 5 roles vienen precargados por seeder. Son **editables** desde Ajustes — solo son defaults razonables.

### 3.1 Trabajador

**Propósito:** personal de limpieza en terreno. Solo ve sus habitaciones asignadas y las marca como terminadas.

Permisos por defecto:
- `habitaciones.ver_asignadas_propias`
- `habitaciones.marcar_completada`
- `tickets.crear`
- `tickets.ver_propios`
- `copilot.usar_nivel_1_consultas`
- `copilot.usar_nivel_2_acciones`
- `copilot.ver_historial_propio`
- `kpis.ver_propios`
- `usuarios.cambiar_propia_contrasena`
- `disponibilidad.notificar_supervisora`
- `notificaciones.ver`

### 3.2 Supervisora

**Propósito:** monitorea equipo, asigna/reasigna habitaciones, audita, gestiona tickets.

Permisos por defecto (incluye todo lo del Trabajador excepto los `_propios` reemplazados por globales):
- `habitaciones.ver_todas`
- `habitaciones.ver_historial`
- `asignaciones.asignar_manual`
- `asignaciones.auto_asignar`
- `asignaciones.reordenar_cola_trabajador`
- `auditoria.ver_bandeja`
- `auditoria.aprobar`
- `auditoria.aprobar_con_observacion`
- `auditoria.rechazar`
- `auditoria.editar_checklist_durante_auditoria`
- `tickets.crear`
- `tickets.ver_todos`
- `turnos.ver`
- `copilot.usar_nivel_1_consultas`
- `copilot.usar_nivel_2_acciones`
- `copilot.ver_historial_propio`
- `kpis.ver_operativas`
- `alertas.recibir_predictivas` (también recibe el aviso de un NO de la inspección pre-entrega)
- `revision_entrega.configurar` (v7: motivos y qué hace un NO de la inspección pre-entrega)
- `habitaciones.gestionar_edificios` (v7: Ajustes → Edificios y Mapeo)
- `usuarios.cambiar_propia_contrasena`
- `notificaciones.ver`

**NO tiene por defecto:** `alertas.configurar_umbrales` (solo Admin), ni gestión de usuarios/roles, ni acceso a Ajustes.

### 3.3 Recepción

**Propósito:** audita habitaciones terminadas y, desde la v7, hace la inspección pre-entrega (SÍ / NO antes de entregar la pieza al huésped) desde Habitaciones, que es su pantalla principal: no tiene «Inicio» ni Edificios y Mapeo. Marca nocheros y deja notas a la mucama desde ahí mismo. Cloudbeds es su herramienta principal para el estado de habitaciones.

Permisos por defecto:
- `habitaciones.ver_todas` (solo lectura, contexto durante auditoría)
- `auditoria.ver_bandeja`
- `auditoria.aprobar`
- `auditoria.aprobar_con_observacion`
- `auditoria.rechazar`
- `auditoria.editar_checklist_durante_auditoria`
- `revision_entrega.registrar` (v7)
- `copilot.usar_nivel_1_consultas`
- `copilot.usar_nivel_2_acciones`
- `copilot.ver_historial_propio`
- `usuarios.cambiar_propia_contrasena`
- `notificaciones.ver`

### 3.4 Admin

**Propósito:** control total. Todos los permisos por defecto.

Permisos por defecto: **todos los del catálogo**, incluidos los permisos de marca (`kpis.excluido`, `asignaciones.excluir_auto`; ver §5.2): el Admin no es personal de aseo, así que lo que limpie no cuenta en los KPIs y no recibe piezas del reparto automático (decisión de Nicolás, 04/10/2026; hasta entonces el `'__ALL__'` los saltaba).

### 3.5 Apoyo

**Propósito:** personal de otras áreas que limpia de vez en cuando y cuyo sueldo no depende del aseo (pedido de Nicolás, 01/10/2026). Trabaja igual que un Trabajador —ve sus piezas, marca el checklist, se inspecciona igual y una pieza rechazada vuelve a sucia—, pero no suma créditos, no entra en KPIs ni en el bono, y no recibe piezas del reparto automático: la supervisora le asigna a mano (necesita turno para aparecer en el tablero). **No es de sistema** (`es_sistema = 0`): Admin lo puede editar o borrar desde Ajustes.

Permisos por defecto: los de Trabajador **menos** `kpis.ver_propios`, **más** `kpis.excluido` y `asignaciones.excluir_auto`. La migración copia los permisos que Trabajador tiene HOY en la base (pueden haberse editado desde Ajustes).

Rework cruzado: si una trabajadora rehace una pieza que le rechazaron a Apoyo, la pieza y lo que ella marcó le cuentan a ella; lo heredado (a nombre de Apoyo) no le cuenta a nadie. Si Apoyo rehace una rechazada de una trabajadora, el rechazo le sigue contando a ella.

---

## 4. Matriz visual — Permisos × Roles

Leyenda: ✅ por defecto · ⚪ disponible (activable desde Ajustes) · — no aplica

| Permiso | Trabajador | Supervisora | Recepción | Admin |
|---|:-:|:-:|:-:|:-:|
| `habitaciones.ver_todas` | ⚪ | ✅ | ✅ | ✅ |
| `habitaciones.ver_asignadas_propias` | ✅ | ⚪ | ⚪ | ✅ |
| `habitaciones.marcar_completada` | ✅ | ⚪ | ⚪ | ✅ |
| `habitaciones.ver_historial` | ⚪ | ✅ | ⚪ | ✅ |
| `checklists.ver` | ⚪ | ⚪ | ⚪ | ✅ |
| `checklists.editar` | ⚪ | ⚪ | ⚪ | ✅ |
| `checklists.crear_nuevos` | ⚪ | ⚪ | ⚪ | ✅ |
| `apariencia.editar` | ⚪ | ✅ | ⚪ | ✅ |
| `asignaciones.asignar_manual` | ⚪ | ✅ | ⚪ | ✅ |
| `asignaciones.auto_asignar` | ⚪ | ✅ | ⚪ | ✅ |
| `asignaciones.reordenar_cola_trabajador` | ⚪ | ✅ | ⚪ | ✅ |
| `asignaciones.mover_en_progreso` | ⚪ | ⚪ | ⚪ | ✅ |
| `asignaciones.excluir_auto` (marca) | ⚪ | ✅ | ✅ | ✅ |
| `auditoria.ver_bandeja` | ⚪ | ✅ | ✅ | ✅ |
| `auditoria.aprobar` | ⚪ | ✅ | ✅ | ✅ |
| `auditoria.aprobar_con_observacion` | ⚪ | ✅ | ✅ | ✅ |
| `auditoria.rechazar` | ⚪ | ✅ | ✅ | ✅ |
| `auditoria.editar_checklist_durante_auditoria` | ⚪ | ✅ | ✅ | ✅ |
| `revision_entrega.registrar` | ⚪ | ⚪ | ✅ | ✅ |
| `revision_entrega.configurar` | ⚪ | ✅ | ⚪ | ✅ |
| `habitaciones.gestionar_edificios` | ⚪ | ✅ | ⚪ | ✅ |
| `tickets.crear` | ✅ | ✅ | ⚪ | ✅ |
| `tickets.ver_propios` | ✅ | ⚪ | ⚪ | ✅ |
| `tickets.ver_todos` | ⚪ | ✅ | ⚪ | ✅ |
| `usuarios.ver` | ⚪ | ⚪ | ⚪ | ✅ |
| `usuarios.crear` | ⚪ | ⚪ | ⚪ | ✅ |
| `usuarios.editar` | ⚪ | ⚪ | ⚪ | ✅ |
| `usuarios.resetear_password` | ⚪ | ⚪ | ⚪ | ✅ |
| `usuarios.activar_desactivar` | ⚪ | ⚪ | ⚪ | ✅ |
| `usuarios.asignar_rol` | ⚪ | ⚪ | ⚪ | ✅ |
| `usuarios.cambiar_propia_contrasena` | ✅ | ✅ | ✅ | ✅ |
| `roles.ver` | ⚪ | ⚪ | ⚪ | ✅ |
| `roles.crear` | ⚪ | ⚪ | ⚪ | ✅ |
| `roles.editar` | ⚪ | ⚪ | ⚪ | ✅ |
| `roles.eliminar` | ⚪ | ⚪ | ⚪ | ✅ |
| `permisos.asignar_a_rol` | ⚪ | ⚪ | ⚪ | ✅ |
| `turnos.ver` | ⚪ | ✅ | ⚪ | ✅ |
| `turnos.crear_editar` | ⚪ | ⚪ | ⚪ | ✅ |
| `turnos.asignar_a_usuario` | ⚪ | ⚪ | ⚪ | ✅ |
| `copilot.usar_nivel_1_consultas` | ✅ | ✅ | ✅ | ✅ |
| `copilot.usar_nivel_2_acciones` | ✅ | ✅ | ✅ | ✅ |
| `copilot.ver_historial_propio` | ✅ | ✅ | ✅ | ✅ |
| `copilot.ver_historial_todos` | ⚪ | ⚪ | ⚪ | ✅ |
| `cloudbeds.ver_estado_sincronizacion` | ⚪ | ⚪ | ⚪ | ✅ |
| `cloudbeds.forzar_sincronizacion` | ⚪ | ⚪ | ⚪ | ✅ |
| `cloudbeds.configurar_credenciales` | ⚪ | ⚪ | ⚪ | ✅ |
| `kpis.ver_propios` | ✅ | ⚪ | ⚪ | ✅ |
| `kpis.ver_operativas` | ⚪ | ✅ | ⚪ | ✅ |
| `kpis.ver_globales` | ⚪ | ⚪ | ⚪ | ✅ |
| `kpis.excluido` (marca) | ⚪ | ✅ | ✅ | ✅ |
| `alertas.recibir_predictivas` | ⚪ | ✅ | ⚪ | ✅ |
| `alertas.configurar_umbrales` | ⚪ | ⚪ | ⚪ | ✅ |
| `sistema.ver_salud` | ⚪ | ⚪ | ⚪ | ✅ |
| `ajustes.acceder` | ⚪ | ⚪ | ⚪ | ✅ |
| `logs.ver` | ⚪ | ⚪ | ⚪ | ✅ |
| `disponibilidad.notificar_supervisora` | ✅ | ⚪ | ⚪ | ⚪ |
| `notificaciones.ver` | ✅ | ✅ | ✅ | ✅ |

---

## 5. Reglas de herencia y combinación

### 5.1 Usuarios con múltiples roles

Un usuario puede tener 1 o más roles. Sus permisos efectivos son la **unión** (OR) de los permisos de sus roles.

```
usuario.permisos = ⋃ rol.permisos (para cada rol en usuario.roles)
```

Ejemplo: una persona con roles `Supervisora` + `Recepción` acumula los permisos de ambos. No hay resta ni conflicto.

### 5.2 No hay permisos negativos (pero sí permisos de marca)

El sistema no soporta "denegar explícitamente". Si un rol no tiene un permiso, el usuario no lo tiene (salvo que otro rol suyo lo provea).

**Excepción documentada (01/10/2026, rol Apoyo): permisos de marca.** `kpis.excluido` y `asignaciones.excluir_auto` no habilitan ni quitan ninguna capacidad: marcan **cómo se cuenta** a la persona (fuera de KPIs, fuera del reparto automático). Se rigen por la misma unión del §5.1: si cualquiera de sus roles los tiene, la persona queda marcada (alguien con Trabajador + Apoyo queda fuera de los KPIs). **Desde el 04/10/2026 los lleva todo rol que no es de aseo** (Admin, Supervisora, Recepción, además de Apoyo); solo Trabajador queda sin ellos. Ojo con quien tenga **Trabajador + otro rol**: queda fuera de los KPIs y del bono de aseo. Se chequean con `RbacService::sqlSinPermiso()` / `usuariosConPermiso()`, nunca por nombre de rol.

### 5.3 Admin puede editarse a sí mismo

Un Admin puede quitarle permisos a su propio rol desde la matriz. La UI debe mostrar **warning modal** antes de confirmar:

> ⚠️ Estás por quitar el permiso `ajustes.acceder` de tu propio rol. Si confirmas, no podrás volver a entrar a Ajustes. ¿Continuar?

**Salvaguarda backend:** el sistema debe garantizar que **siempre exista al menos un usuario con `permisos.asignar_a_rol`**. Si la operación dejaría al sistema sin ningún admin, el endpoint responde 409 Conflict.

**Estado: IMPLEMENTADO (15/09/2026).** El invariante lo aplica `RbacService::conGuardiaDeAdmin()` de forma atómica (transacción serializada `Database::transactionImmediate()` + bloqueo de fila `FOR UPDATE` en MariaDB / `BEGIN IMMEDIATE` en SQLite), cubriendo los **5 vectores**: desactivar, eliminar, quitar el rol admin a un usuario, vaciar el permiso llave del rol vía la matriz (`actualizarRol`), y borrar un rol admin-equivalente. "Admin" se define por **PERMISO** (`permisos.asignar_a_rol`), nunca por nombre de rol (RBAC dinámico). Errores: **409 `ULTIMO_ADMIN`** y **400 `AUTO_DESACTIVACION_PROHIBIDA`** (al intentar desactivarse uno mismo). La UI (`views/componentes/modal-usuario-detalle.php`) deshabilita los botones de desactivar/quitar-rol con un tooltip cuando el usuario es el último admin, usando el flag `es_ultimo_admin` que devuelve `GET /api/usuarios`.

### 5.4 Nadie da ni toca permisos de gestión que no tiene (anti-escalada)

**Estado: IMPLEMENTADO (07/10/2026).** Quien gestiona usuarios solo puede dar un rol, o tocar a un usuario, si ya tiene todos los permisos de **gestión de cuentas** que ese rol o ese usuario traen. Lo aplican `RbacService::puedeOtorgarRol()` y `RbacService::puedeGestionarUsuario()` en: asignar rol, crear usuario con roles (y la carga masiva), quitar rol, editar datos o email, resetear contraseña, activar/desactivar y eliminar. Error: **403 `PRIVILEGIOS_INSUFICIENTES`**. Sobre uno mismo siempre se puede.

Los permisos de gestión de cuentas son una **lista explícita** (`RbacService::PERMISOS_GESTION`): `usuarios.crear`, `usuarios.editar`, `usuarios.resetear_password`, `usuarios.activar_desactivar`, `usuarios.eliminar`, `usuarios.asignar_rol`, `usuarios.modo_espia`, `roles.crear`, `roles.editar`, `roles.eliminar` y `permisos.asignar_a_rol`. No se compara todo el catálogo: así una supervisora con `usuarios.crear` puede crear trabajadoras (el rol Trabajador trae permisos de terreno que ella no tiene), pero nadie sin `permisos.asignar_a_rol` puede dar el rol Admin, crear una cuenta Admin, ni cambiarle el email o la clave a un Admin. Tampoco entran los permisos sobre la propia cuenta o de solo lectura (`usuarios.cambiar_propia_contrasena`, `usuarios.exportar_datos_propios`, `usuarios.ver`, `roles.ver`): no dan poder sobre nadie, y como en producción los permisos se ajustan a mano, compararlos podía bloquear a una supervisora frente a una trabajadora que los tuviera y ella no. Asignar y quitar roles exige `usuarios.asignar_rol` (antes bastaba `usuarios.editar`).

**Recuperación de emergencia.** Si aun así el sistema quedara sin ningún admin activo (un bug, una migración o edición directa de la BD), correr en el servidor:

```
php scripts/promover-admin.php <RUT>              # reactiva (activo=1) + asigna un rol con el permiso llave
php scripts/promover-admin.php <RUT> --reset-pwd  # además genera una contraseña temporal
```

El script solo **agrega** capacidad admin (nunca la quita), por lo que es seguro correrlo incluso con 0 admins. Complementa a `scripts/reset-admin-password.php` (que solo cambia la clave del admin de rescate RUT `11111111-1`, sin reactivar ni reasignar rol).

---

### 5.5 Permisos del catálogo que se revisan

Desde el 07/10/2026: `notificaciones.ver` (rutas de la
campanita, que tampoco se muestra sin él), `usuarios.exportar_datos_propios` (exportar los datos propios)
y `roles.ver` (leer la matriz; antes bastaba `ajustes.acceder`). Quedan **sin ruta propia, a propósito**:
`usuarios.cambiar_propia_contrasena` (cambiar la clave temporal es obligatorio para entrar: exigir el
permiso podría dejar a alguien sin poder entrar) y `roles.crear` / `roles.editar` / `roles.eliminar`
(crear, editar y borrar roles exige `permisos.asignar_a_rol`, que ya incluye todo eso).

## 6. Cómo agregar un permiso nuevo

Cuando implementes una feature que requiera un permiso nuevo:

1. **Agrégalo a este doc** (sección correspondiente del catálogo + matriz).
2. **Agrégalo al seeder** `database/seeds/permisos.php`.
3. **Asígnalo en el seeder** `database/seeds/rol_permisos.php` a los roles que lo deben tener por defecto.
4. **Aplícalo en el endpoint** via middleware `PermissionCheck('codigo.del.permiso')`.
5. **Aplícalo en el frontend** (ocultar/desactivar UI con `$usuario->tienePermiso(...)`).
6. **Commit** con mensaje `feat: agregar permiso <codigo> para <feature>`.

No hay que modificar la tabla `permisos` a mano — el seeder hace `INSERT OR IGNORE`, corrible en cualquier momento.

---

## 7. Vinculación con el schema

Ver [database-schema.sql](database-schema.sql) (Fase 2) para el DDL. Tablas relevantes:

- **`permisos`** — catálogo (una fila por permiso de este doc): `codigo` (PK), `descripcion`, `categoria`, `scope`.
- **`roles`** — roles del sistema: `id`, `nombre`, `descripcion`.
- **`rol_permisos`** — matriz rol × permiso: `rol_id`, `permiso_codigo`.
- **`usuarios_roles`** — asignación usuario × rol: `usuario_id`, `rol_id`.

Consulta de permisos efectivos de un usuario:

```sql
SELECT DISTINCT p.codigo
FROM permisos p
JOIN rol_permisos rp ON rp.permiso_codigo = p.codigo
JOIN usuarios_roles ur ON ur.rol_id = rp.rol_id
WHERE ur.usuario_id = ?;
```

El método `$usuario->tienePermiso($codigo)` debe cachear esta query por duración de la sesión (cargar una vez al login, guardar en `$_SESSION['permisos']`).

---

## 8. Referencias cruzadas

- [plan.md](../plan.md) §5 — diseño original del sistema RBAC
- [home-trabajador.md](home-trabajador.md) §2 — uso de permisos en Home Trabajador
- [home-supervisora.md](home-supervisora.md) §2 — uso de permisos en Home Supervisora
- [home-recepcion.md](home-recepcion.md) §2 — uso de permisos en Home Recepción
- [home-admin.md](home-admin.md) §2 — uso de permisos en Home Admin
- [CLAUDE.md](../CLAUDE.md) — regla de oro RBAC dinámico
- [database-schema.sql](database-schema.sql) — tablas `permisos`, `roles`, `rol_permisos`, `usuarios_roles`
- [auth.md](auth.md) — cómo se cargan los permisos en sesión al login
- [ajustes.md](ajustes.md) — UI de la matriz RBAC editable
