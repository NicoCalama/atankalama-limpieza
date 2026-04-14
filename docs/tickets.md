# Tickets de mantenimiento

**Versión:** 1.0 — 2026-04-14

Documenta el módulo de tickets para reportar problemas de mantenimiento (lámparas quemadas, cañerías, muebles rotos, etc.).

---

## 1. Alcance MVP

Sistema simple de tickets. **No es un workflow complejo** — solo un registro para que:
- Trabajadores/Supervisoras/Recepción reporten problemas.
- Admin reciba notificación (alerta P2 `ticket_nuevo`).
- Alguien marque cuando está resuelto.

La gestión real del mantenimiento (contratistas, pagos, etc.) queda fuera del MVP.

---

## 2. Modelo

Tabla `tickets` (ver [database-schema.sql](database-schema.sql)):

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | PK | — |
| `habitacion_id` | FK opcional | Si el ticket es sobre una habitación específica |
| `hotel_id` | FK | Obligatorio |
| `titulo` | TEXT | "Lámpara quemada", "Fuga en baño" |
| `descripcion` | TEXT | Detalle |
| `prioridad` | ENUM | `baja`, `normal`, `alta`, `urgente` |
| `estado` | ENUM | `abierto`, `en_progreso`, `resuelto`, `cerrado` |
| `levantado_por` | FK usuario | Quien creó el ticket |
| `asignado_a` | FK usuario NULL | Quien se hará cargo |
| `created_at`, `updated_at`, `resuelto_at` | TEXT | — |

---

## 3. Estados

```
abierto → en_progreso → resuelto → cerrado
```

- **abierto**: recién creado, nadie lo toma.
- **en_progreso**: alguien lo asignó o lo marcó en trabajo.
- **resuelto**: reportado como arreglado (pendiente verificación).
- **cerrado**: confirmado cerrado.

Transición `resuelto → abierto` (reabrir) es posible si alguien detecta que no se arregló.

---

## 4. Permisos

| Permiso | Rol default | Acción |
|---|---|---|
| `tickets.crear` | Trabajador, Supervisora | Crear ticket |
| `tickets.ver_propios` | Trabajador | Ver solo los que uno creó |
| `tickets.ver_todos` | Supervisora, Admin | Ver todos |

**Nota:** Admin implícitamente puede gestionar todo. Recepción no crea tickets por defecto (su trabajo es auditar), pero puede activarse.

---

## 5. Flujo — crear ticket

### 5.1 Desde Home (botón "Reportar problema")

1. FAB o botón en Home → modal "Nuevo ticket".
2. Campos:
   - **Habitación** (opcional, selector) — preseleccionada si estoy viendo una habitación específica.
   - **Título** (obligatorio, max 80 chars).
   - **Descripción** (obligatorio, textarea).
   - **Prioridad** (radio: baja/normal/alta/urgente, default normal).
3. POST `/api/tickets` → crea fila, estado `abierto`.
4. Se crea alerta P2 `ticket_nuevo` dirigida a usuarios con `tickets.ver_todos`.
5. Toast: "Ticket creado. Gracias por reportar."

### 5.2 Desde copilot

Tool `crear_ticket(habitacion_id, titulo, descripcion, prioridad)` — mismo efecto.

---

## 6. Flujo — gestión

### 6.1 Supervisora / Admin ven bandeja de tickets

GET `/api/tickets?estado=abierto` — lista ordenada por prioridad desc + fecha asc.

Acciones:
- **Tomar** → `asignado_a = self`, estado `en_progreso`.
- **Asignar a** → selector de usuario, estado `en_progreso`.
- **Marcar resuelto** → estado `resuelto`, `resuelto_at = now`.
- **Cerrar** → estado `cerrado` (solo si estaba resuelto).
- **Reabrir** → estado `abierto` (revierte resuelto/cerrado).

### 6.2 Notificaciones

- Al crear ticket → alerta P2 a supervisores/admin.
- Al cambiar estado → sin alerta nueva (solo refleja en bandejas).
- Al cerrar ticket → resuelve la alerta P2 original (si aún activa).

---

## 7. Endpoints

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| POST | `/api/tickets` | `tickets.crear` | Crear |
| GET | `/api/tickets` | `tickets.ver_todos` | Todos (query: estado, hotel, prioridad) |
| GET | `/api/tickets/mios` | `tickets.ver_propios` | Solo los míos |
| GET | `/api/tickets/{id}` | propietario o `tickets.ver_todos` | Detalle |
| PUT | `/api/tickets/{id}/asignar` | `tickets.ver_todos` | `{ "usuario_id": N }` |
| PUT | `/api/tickets/{id}/estado` | `tickets.ver_todos` | `{ "estado": "..." }` |

---

## 8. Referencias cruzadas

- [alertas-predictivas.md](alertas-predictivas.md) §3.6 — P2 `ticket_nuevo`
- [database-schema.sql](database-schema.sql) — tabla `tickets`
- [roles-permisos.md](roles-permisos.md) §2.5
- [home-trabajador.md](home-trabajador.md), [home-supervisora.md](home-supervisora.md) — UI
