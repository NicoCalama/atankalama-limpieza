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
| Checklists | `checklists.ver` | delega a [checklist.md](checklist.md) §2 |
| Alertas | `alertas.configurar_umbrales` | §4 |
| Cloudbeds | `cloudbeds.configurar_credenciales` | §5 |
| Logs | `logs.ver` | delega a [logs.md](logs.md) |

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

## 8. Endpoints

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

## 9. Referencias cruzadas

- [roles-permisos.md](roles-permisos.md) — catálogo
- [auth.md](auth.md) — cambio de pwd
- [home-admin.md](home-admin.md) §3 — tab "Ajustes" navega a este módulo
- Todas las Homes — link de acceso si `ajustes.acceder`
