# Ajustes — módulo de configuración

**Versión:** 1.0 — 2026-04-14

Documenta el módulo `/ajustes`: secciones, permisos y UI.

---

## 1. Alcance

`/ajustes` es un módulo separado de las Homes. Todas las Homes tienen un link de acceso (tab, sidebar o menú) cuando el usuario tiene `ajustes.acceder`.

Las **secciones internas** se filtran por permisos específicos. Un usuario con `ajustes.acceder` pero sin permisos de gestión solo verá "Mi cuenta".

---

## 2. Estructura

### 2.1 Layout

- **Desktop:** sidebar izquierda con lista de secciones + área de contenido a la derecha.
- **Móvil:** lista vertical de secciones (drill-down al tocar una).

Header común: breadcrumb "Ajustes > {sección}", botón volver (móvil).

### 2.2 Secciones

| Sección | Permiso requerido | Detalle |
|---|---|---|
| Mi cuenta | (todos) | §6 |
| Roles y Permisos | `permisos.asignar_a_rol` | §3 |
| Usuarios | `usuarios.ver` | delega a [usuarios.md](usuarios.md) |
| Turnos | `turnos.ver` | delega a [turnos.md](turnos.md) |
| Checklists y listas | `checklists.editar` o `listas.editar` | Dos pestañas (v7.3): «Checklists» delega a [checklist.md](checklist.md) §2; «Listas» = §2.3 |
| Inspección pre-entrega | `revision_entrega.configurar` | Qué hace un NO de Recepción (solo avisar / además devolver la pieza aprobada a sucia). Desde la v7.3 los motivos se editan en «Checklists y listas». Delega a [revision-entrega.md](revision-entrega.md) (v7) |
| Edificios y Mapeo | `habitaciones.gestionar_edificios` | Edificios y edificio/piso de cada pieza. Antes de la v7 se veía con `habitaciones.ver_todas` (Recepción incluida) |
| Colores | `apariencia.editar` | Colores de tarjetas por estado y hotel: la supervisora elige un color base por concepto (key-value en `ui_config`); el backend deriva variantes claro/oscuro (`Helpers\Colores`) y el layout las inyecta como CSS vars para las clases `.chip-estado-*` / `.hotel-accent-*` de custom.css. Endpoints en [api-endpoints.md](api-endpoints.md) |
| Alertas | `alertas.configurar_umbrales` | §4 |
| Cloudbeds | `cloudbeds.configurar_credenciales` | §5 |
| Logs | `logs.ver` | delega a [logs.md](logs.md) |
| Versiones | (todos) | §8 |

### 2.3 Listas de selección (v7.3)

Pedido de gerencia (10/10/2026): **las acciones no llevan cuadro de texto, se elige de una lista** que se edita
acá. Pestaña «Listas» de **Ajustes → Checklists y listas** (`views/ajustes/listas-opciones.php`, componente en
`views/recursos/ajustes/listas-opciones.js`). Permiso **`listas.editar`**, solo Admin por defecto (se puede dar a
otro rol desde Roles y permisos).

| Lista | Lo ve | Cuándo | Se guarda |
|---|---|---|---|
| `rechazo` | Supervisora | Al rechazar en la inspección, además de los ítems fallidos | `auditorias.motivo_id` + el nombre en `comentario` |
| `sin_inspeccion` (v7.11) | Supervisora | «No se pudo inspeccionar» (ya hay un huésped…); la pieza sale de la bandeja ([auditoria.md](auditoria.md) §2.4) | `auditorias.motivo_id` + el nombre en `comentario` |
| `reasignar` | Supervisora | Al reasignar una pieza (ventana o arrastre en el tablero) | `audit_log` (`asignacion.reasignar`: `motivo` + `opcion_id`) |
| `saltar` | Trabajadora | «No puedo limpiarla / terminarla ahora» | `audit_log` y la alerta P2 (`motivo` + `opcion_id`) |
| `no_recepcion` | Recepción | NO de la inspección pre-entrega | `revisiones_entrega.motivo_id` (tabla propia `motivos_revision_entrega`) |
| `nota_recepcion` (v7.4) | Recepción | Mensaje a la mucama (detalle de la pieza y menú de Habitaciones); **se pueden elegir varias** | `habitaciones.nota_recepcion` (los nombres unidos con « · ») |
| `ticket_tipo` (v7.4) | Quien reporta | Tipo de problema al crear un ticket (reemplaza la descripción escrita) | `tickets.tipo_id` + el nombre como título y descripción |
| `ticket_respuesta` (v7.4) | Quien atiende | Respuesta al comentar un ticket | `tickets_comentarios.comentario` (el nombre) |

**Aprobar con observación no tiene lista:** la observación son las casillas que la supervisora desmarca (decisión
de Nicolás, 10/10/2026).

**Pantalla:** una tarjeta por lista, agrupadas por quién la usa (Supervisora / Trabajadora / Recepción / Tickets), con «Lo ve:
… al …» y cuántas opciones activas tiene. Al tocarla se abre el editor (hoja desde abajo en el teléfono): ↑↓ para
ordenar, lápiz para renombrar, interruptor para activar/desactivar, «+ Agregar» y **«Así lo ve …»**, los botones tal
como los verá quien elige. Todo se guarda al instante.

**Reglas** (`Services\ListasOpcionesService`):
- Nombre de 2 a 60 caracteres; sin duplicados en la misma lista (sin distinguir mayúsculas ni tildes, en PHP).
- **No se borran**: se desactivan y el historial las conserva. Cada lista conserva **al menos una activa**
  (409 `ULTIMA_OPCION_ACTIVA`).
- Ordenar exige mandar todas las opciones de la lista (409 `ORDEN_DESACTUALIZADO` si cambió entre medio).
- Las acciones validan que la opción siga activa (400 `OPCION_INVALIDA`) y sin opción responden 400
  `OPCION_REQUERIDA`. En el rechazo, saltar y reasignar se guarda el **nombre** tal como se vio: renombrar después
  no cambia el historial (en el NO de Recepción sí, porque guarda el id, como desde la v7).
- Ninguna opción tiene comportamiento especial («Huésped no ha salido» hace lo mismo que las demás): todas se
  pueden renombrar o desactivar.

Endpoints en [api-endpoints.md](api-endpoints.md) §18b.

---

## 3. Roles y Permisos — matriz editable

Permiso: `permisos.asignar_a_rol`.

### 3.1 UI

Tabla:
- Filas = permisos (agrupados por categoría con headers: Habitaciones, Auditoría, etc.).
- Columnas = roles (4 por default + los creados).
- Celdas = checkbox.

Al tocar un checkbox:
- Optimistic update.
- PUT `/api/roles/{rol_id}/permisos/{permiso_codigo}` `{ "asignado": true | false }`.
- Si el backend rechaza (safeguard último admin), revierte.

### 3.2 Acciones

- **Crear rol nuevo** (botón "+" top): modal con nombre + descripción.
- **Editar rol**: lápiz junto al nombre.
- **Eliminar rol**: icono trash — solo si `es_sistema=0` y no tiene usuarios asignados.

### 3.3 Safeguards

Toda edición de permisos pasa por el backend que valida:
- Siempre debe haber ≥1 usuario activo con `permisos.asignar_a_rol`.
- Si la operación deja al sistema sin ello → 409 `ULTIMO_ADMIN`.

---

## 4. Alertas (config de umbrales)

Permiso: `alertas.configurar_umbrales`.

UI: formulario con los campos de `alertas_config`:
- `margen_seguridad_minutos` (input número, default 15).
- `fin_turno_anticipo_minutos` (default 30).
- `recalculo_intervalo_minutos` (default 15).
- `tiempo_fallback_nueva_habitacion` (default 30).

Botón "Guardar" → PUT `/api/alertas/config`.

Al guardar, se dispara recálculo inmediato de alertas predictivas.

---

## 5. Cloudbeds (credenciales + sync)

Permiso: `cloudbeds.configurar_credenciales`.

**Estado (16/10/2026):** la pantalla `/ajustes/cloudbeds` existe desde la v7.8 y **solo tiene los interruptores de §5.0**.
Lo de §5.1–5.4 es la especificación original y sigue sin pantalla (el sync se ve en el Inicio del Admin).

### 5.0 Habitaciones en mantención o cerradas (v7.8)

Dos interruptores: «Limpieza al salir de mantención» y «Limpieza al dejar de estar cerrada». Prendido = al
terminar ese bloqueo en Cloudbeds, la pieza pasa a sucia, queda sin asignar con aviso P2 a la supervisora y su
limpieza es completa, con cambio de sábanas. Apagado = solo se quita la etiqueta. Prendidos si nadie los tocó.
API: `GET/PUT /api/cloudbeds/bloqueos-config` `{mantencion?: bool, cerrada?: bool}`. Ver [cloudbeds.md](cloudbeds.md) §4.5.

### 5.1 Credenciales

**Importante:** las credenciales viven en `.env`, no en BD. La UI aquí solo muestra:
- Estado actual: "Configurado" / "Falta configurar" (chequea si `CLOUDBEDS_API_KEY` está seteado).
- Instrucciones para configurar (editar `.env` + reiniciar).
- Botón "Probar conexión" → intenta un GET a Cloudbeds y muestra resultado.

### 5.2 Sync schedule

Formulario para editar `cloudbeds_config`:
- Hora sync matutino (default 07:00).
- Hora sync tarde (default 15:00).
- Reintentos max (default 3).
- Timeout segundos (default 10).

### 5.3 Historial

Link a GET `/api/cloudbeds/historial` — tabla paginada de syncs con estado, duración, errores.

### 5.4 Acción manual

Botón "Sincronizar ahora" → POST `/api/cloudbeds/sync`.

---

## 6. Mi cuenta (todos los usuarios)

### 6.1 Datos personales

- Nombre (editable).
- Email (editable).
- RUT (solo lectura).
- Hotel default (selector: 1 Sur / Inn / Ambos).
- Tema (radio: Auto / Claro / Oscuro).

### 6.2 Seguridad

- Botón "Cambiar contraseña" → abre modal con form (ver [auth.md](auth.md) §3).

### 6.3 Historial del copilot

Si tiene `copilot.ver_historial_propio`:
- Lista de conversaciones con fecha + título.
- Tap → detalle con mensajes.
- Botón "Borrar" por conversación.

### 6.4 Sesión

- Botón "Cerrar sesión" (logout).
- Badge con última fecha de login.

---

## 7. Logs viewer (si permiso)

Permiso: `logs.ver`. Ver [logs.md](logs.md) §4 para UI del viewer.

---

## 8. Versiones (todos los usuarios)

Historial de versiones de la app: qué cambió en cada una. Sin permiso — no expone
nada sensible y la gracia es que el equipo y la empresa vean qué trajo cada deploy.

**Fuente única:** `CHANGELOG.md` en la raíz del repo. No hay tabla ni endpoint: la
página se renderiza en servidor y `Helpers\Changelog` parsea el archivo. De la
misma fuente sale el badge de versión del home del Admin (`version_app.actual` en
[home-admin.md](home-admin.md) §14.2), que **linkea acá**, para que la app no pueda
contradecir al historial.

**Formato** (una fila por versión, la más nueva arriba; el propio `CHANGELOG.md` lo
documenta):

```
| **v1.1** · 07/07/2026 | Cambio uno · Cambio dos |
```

- Izquierda: versión y fecha en DD/MM/YYYY, o `sin publicar` si ya está en `main`
  pero no salió a producción. Derecha: los cambios enumerados, separados por ` · `.
- **La versión "en uso" es la última publicada** — una `sin publicar` se muestra
  marcada pero no cuenta como la que corre.
- Numeración: deploy chico sube el segundo número (v1 → v1.1), cambio grande sube
  el primero (v1.x → v2). Un cambio que no sube código no es una versión. El paso
  de release está en [deploy-cpanel.md](deploy-cpanel.md) §10 paso 0.
- Si el archivo falta o el formato se rompe, la página muestra su estado vacío y el
  badge se esconde (no se inventa un número). `ChangelogTest` parsea el archivo
  real del repo para que eso no pase en silencio.

---

## 9. Endpoints

Los endpoints de Ajustes delegan a los módulos de dominio. Este doc no define endpoints propios — solo orquesta.

Referencia rápida:
- Roles/permisos → [api-endpoints.md](api-endpoints.md) §roles
- Usuarios → [api-endpoints.md](api-endpoints.md) §usuarios
- Turnos → [api-endpoints.md](api-endpoints.md) §turnos
- Checklists → [api-endpoints.md](api-endpoints.md) §checklists
- Alertas config → [api-endpoints.md](api-endpoints.md) §alertas
- Cloudbeds → [api-endpoints.md](api-endpoints.md) §cloudbeds
- Logs → [api-endpoints.md](api-endpoints.md) §logs
- Mi cuenta → PUT `/api/usuarios/me`

---

## 10. Referencias cruzadas

- [roles-permisos.md](roles-permisos.md) — catálogo
- [auth.md](auth.md) — cambio de pwd
- [home-admin.md](home-admin.md) §3 — tab "Ajustes" navega a este módulo
- Todas las Homes — link de acceso si `ajustes.acceder`
