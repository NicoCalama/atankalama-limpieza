# Habitaciones — estados, transiciones y asignación

**Versión:** 1.0 — 2026-04-14

Documenta el ciclo de vida de una habitación, las transiciones de estado permitidas, la sincronización con Cloudbeds y el flujo de asignación (manual + round-robin).

---

## 1. Modelo conceptual

Una **habitación** existe en el sistema como catálogo estático (número, hotel, tipo). Su **estado** cambia con el uso: sucia → en progreso → completada (pendiente auditoría) → aprobada / aprobada con observación / rechazada → (sucia de nuevo en el próximo check-out).

Una habitación pertenece a UN solo hotel (1 Sur o Inn). El filtrado "Ambos" es una vista combinada para usuarios con acceso multi-hotel.

---

## 2. Estados (6)

| Estado | Descripción | Visible al Trabajador | Cloudbeds |
|---|---|---|---|
| `sucia` | Cliente hizo check-out, requiere limpieza | Sí (si está asignada a él) | Dirty |
| `en_progreso` | Trabajador inició checklist | Sí | Dirty |
| `completada_pendiente_auditoria` | Trabajador marcó terminada, espera auditoría | Sí (solo ver) | Dirty |
| `aprobada` | Auditor aprobó | Solo histórico | **Clean** |
| `aprobada_con_observacion` | Auditor aprobó con observación menor | Solo histórico | **Clean** |
| `rechazada` | Auditor rechazó, requiere re-limpieza | Sí (reasignación) | Dirty |

---

## 3. Transiciones permitidas

```
sucia
  └─ (trabajador inicia checklist) → en_progreso
       └─ (trabajador marca terminada, 100% checklist) → completada_pendiente_auditoria
            ├─ (auditor: aprobado)                   → aprobada       [Cloudbeds: Clean]
            ├─ (auditor: aprobado_con_observacion)   → aprobada_con_observacion  [Cloudbeds: Clean]
            └─ (auditor: rechazado)                  → rechazada      [Cloudbeds: Dirty]

aprobada / aprobada_con_observacion / rechazada
  └─ (nuevo ciclo, check-out en Cloudbeds) → sucia
```

### 3.1 Transiciones prohibidas

- **Volver a `sucia` desde cualquier estado terminal** salvo por sync con Cloudbeds (nuevo check-out).
- **Re-auditar** una habitación ya auditada: ver inmutabilidad en [auditoria.md](auditoria.md).
- **Saltar el estado `completada_pendiente_auditoria`**: no se puede aprobar directamente desde `en_progreso`.
- **Reasignar una habitación `en_progreso`** sin pasar por supervisora con permiso `asignaciones.asignar_manual`.

Validación centralizada en `src/Services/EstadoHabitacionService.php` — método `puedeTransicionar(string $actual, string $destino): bool`.

---

## 4. Sincronización con Cloudbeds

Detalle completo en [cloudbeds.md](cloudbeds.md). Resumen:

- **Entrada** (Cloudbeds → app): sync 2x/día + manual. Cambia habitaciones a `sucia` cuando hay check-out.
- **Salida** (app → Cloudbeds): al cambiar estado a `aprobada` o `aprobada_con_observacion`, se dispara un PUT a Cloudbeds marcando "Clean". Si falla, se encola con reintentos (1s/2s/4s). Tras 3 fallos → alerta P0 `cloudbeds_sync_failed`.
- **Rechazadas** vuelven a Dirty en Cloudbeds (idempotente — ya estaba Dirty).

---

## 5. Filtrado por hotel

La UI filtra habitaciones según el contexto del usuario:

- **Trabajador**: ve solo sus asignaciones (filtrado implícito por `asignaciones.usuario_id`).
- **Supervisora / Recepción / Admin**: tab bar o selector con "1 Sur", "Inn", "Ambos". Default: último usado (persistido en localStorage). Si es primera vez, `usuarios.hotel_default`.

Backend: endpoints aceptan query param `?hotel=1_sur|inn|ambos`. Default: `ambos`.

---

## 6. Asignación de habitaciones a trabajadores

### 6.1 Manual (requiere `asignaciones.asignar_manual`)

Supervisora abre selector → elige habitación(es) → elige trabajador → confirma.

Endpoint: `POST /api/asignaciones`
```json
{ "habitacion_ids": [12, 15, 18], "usuario_id": 7, "fecha": "2026-04-14" }
```

- Crea filas en `asignaciones` con `asignado_por = supervisora.id`, `orden_cola = (max + 1)`.
- Si la habitación ya tenía una asignación activa, la marca `activa=0` (reasignación).
- Registra en `audit_log`.

### 6.2 Round-robin automático (requiere `asignaciones.auto_asignar`)

Endpoint: `POST /api/asignaciones/auto`
```json
{ "hotel": "1_sur", "fecha": "2026-04-14" }
```

Algoritmo:
1. Lista habitaciones en estado `sucia` del hotel/fecha sin asignación activa.
2. Lista trabajadores con turno asignado para esa fecha en ese hotel.
3. Reparte round-robin: habitación[0] → trabajador[0], habitación[1] → trabajador[1], etc.
4. Al completar la vuelta, retoma desde el trabajador[0] con siguiente `orden_cola`.
5. Crea filas con `asignado_por = NULL` (sistema).

### 6.3 Reasignación (requiere `asignaciones.asignar_manual`)

Cuando una habitación es **rechazada**, la supervisora decide:
- Reasignar al mismo trabajador (para que corrija).
- Reasignar a otro trabajador.

Endpoint: `POST /api/asignaciones/reasignar`
```json
{ "habitacion_id": 12, "usuario_id": 9, "motivo": "rechazada_reasignacion" }
```

Marca asignación anterior `activa=0`, crea nueva. Registra en `audit_log` y `bitacora_alertas` si venía de una alerta P1 `habitacion_rechazada`.

### 6.4 Reordenar cola del trabajador (requiere `asignaciones.reordenar_cola_trabajador`)

Supervisora puede cambiar el orden en que un trabajador verá sus habitaciones pendientes.

Endpoint: `PUT /api/asignaciones/orden`
```json
{ "usuario_id": 7, "fecha": "2026-04-14", "orden": [15, 12, 18] }
```

Actualiza `orden_cola` según el array (índice 0 → orden_cola 1, etc.).

---

## 7. Endpoints REST — resumen

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/habitaciones` | `habitaciones.ver_todas` | Lista todas (query: hotel, estado, fecha) |
| GET | `/api/habitaciones/asignadas` | `habitaciones.ver_asignadas_propias` | Mis asignaciones del día |
| GET | `/api/habitaciones/{id}` | `habitaciones.ver_todas` OR asignada a mí | Detalle |
| GET | `/api/habitaciones/{id}/historial` | `habitaciones.ver_historial` | Historial de ejecuciones y auditorías |
| POST | `/api/habitaciones/{id}/iniciar` | asignada a mí | Cambia estado a `en_progreso` |
| POST | `/api/habitaciones/{id}/completar` | `habitaciones.marcar_completada` + asignada | Cambia a `completada_pendiente_auditoria` (requiere 100% checklist) |
| POST | `/api/asignaciones` | `asignaciones.asignar_manual` | Asignación manual |
| POST | `/api/asignaciones/auto` | `asignaciones.auto_asignar` | Round-robin |
| POST | `/api/asignaciones/reasignar` | `asignaciones.asignar_manual` | Reasignar habitación rechazada |
| PUT | `/api/asignaciones/orden` | `asignaciones.reordenar_cola_trabajador` | Reordenar cola |

Detalle completo en [api-endpoints.md](api-endpoints.md).

---

## 8. Referencias cruzadas

- [checklist.md](checklist.md) — ejecución del checklist dentro de `en_progreso`
- [auditoria.md](auditoria.md) — flujo post-`completada_pendiente_auditoria`
- [cloudbeds.md](cloudbeds.md) — sincronización bidireccional
- [database-schema.sql](database-schema.sql) — tablas `habitaciones`, `asignaciones`, `hoteles`, `tipos_habitacion`
- [roles-permisos.md](roles-permisos.md) §2.1, §2.3 — permisos relacionados
- [home-trabajador.md](home-trabajador.md), [home-supervisora.md](home-supervisora.md) — UI
