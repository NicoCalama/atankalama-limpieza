# Alertas predictivas

**Versión:** 1.0 — 2026-04-14

Documenta el sistema de alertas operativas y técnicas P0-P3: algoritmo predictivo, umbrales, tipos, ciclo de vida y visibilidad.

---

## 1. Filosofía

El sistema **predice** problemas antes de que ocurran (trabajador no alcanza a terminar, fin de turno con pendientes) y **reacciona** a eventos (rechazo, sync fallido, ticket nuevo).

**Regla crítica:** el **trabajador NUNCA** ve una alerta sobre sí mismo. Las alertas predictivas son para que la **supervisora** intervenga (redistribuir carga, pedir ayuda) sin presionar al trabajador directamente.

---

## 2. Prioridades (P0-P3)

| Prioridad | Significado | Comportamiento UI |
|---|---|---|
| **P0** | Crítica — requiere atención inmediata | Badge rojo, top de la lista, notificación push (post-MVP) |
| **P1** | Alta — requiere atención en minutos | Badge naranjo, alta visibilidad |
| **P2** | Media — informativa, acción deseable | Badge amarillo, sección inferior |
| **P3** | Baja — reservado futuro | — |

---

## 3. Tipos de alertas (6)

### 3.1 P0 — `cloudbeds_sync_failed`

**Disparador:** tras 3 reintentos fallidos al escribir Clean en Cloudbeds, o al fallar un sync entrante.
**Contexto:** `{ habitacion_id, error_mensaje }` (si es escritura) o `{ sync_id, error }` (si es entrante).
**Título:** "Falla de sincronización Cloudbeds"
**Descripción:** "No pudimos actualizar {habitacion} en Cloudbeds tras 3 intentos."
**Botones:**
1. "Reintentar" → dispara sync manual de esa habitación.
2. "Ver detalle" → abre log.
**Resolución automática:** cuando el sync manual o el próximo cron tenga éxito.

### 3.2 P1 — `trabajador_en_riesgo`

**Disparador:** el algoritmo predictivo (§4) estima que un trabajador no alcanzará a terminar su turno.
**Contexto:** `{ usuario_id, habitaciones_pendientes, tiempo_estimado_faltante, tiempo_disponible, margen_deficit }`.
**Título:** "{Nombre} podría no alcanzar a terminar"
**Descripción:** "Le quedan {N} habitaciones ({tiempo_estimado} min) y su turno termina en {tiempo_disponible} min."
**Botones:**
1. "Ver carga" → abre vista de trabajador.
2. "Reasignar" → abre modal para mover habitaciones a otro.
**Resolución automática:** cuando el recálculo determina que ya no está en riesgo (terminó habitaciones, se reasignó, etc.).

### 3.3 P1 — `habitacion_rechazada`

**Disparador:** auditor da veredicto `rechazado`.
**Contexto:** `{ habitacion_id, trabajador_id, auditor_id, comentario }`.
**Título:** "Habitación {numero} rechazada"
**Descripción:** "{Auditor} rechazó la habitación limpiada por {trabajador}. Requiere re-limpieza."
**Botones:**
1. "Reasignar" → modal de reasignación.
2. "Ver detalle" → abre habitación con comentario.
**Resolución automática:** cuando se reasigna.

### 3.4 P1 — `fin_turno_pendientes`

**Disparador:** a 30 min del fin de turno de un trabajador, si aún tiene habitaciones sin terminar.
**Contexto:** `{ usuario_id, turno_id, habitaciones_pendientes }`.
**Título:** "Fin de turno con pendientes"
**Descripción:** "{Nombre} termina turno en 30 min y aún tiene {N} habitaciones."
**Botones:**
1. "Reasignar" → modal.
2. "Contactar" → abre WhatsApp con mensaje prellenado (opcional, post-MVP).
**Resolución automática:** cuando no quedan pendientes o el turno termina.

### 3.5 P2 — `trabajador_disponible`

**Disparador:** trabajador marca "Estoy disponible para más" desde su Home (permiso `disponibilidad.notificar_supervisora`).
**Contexto:** `{ usuario_id }`.
**Título:** "{Nombre} está disponible"
**Descripción:** "Terminó su cola y puede recibir más habitaciones."
**Botones:**
1. "Asignar habitaciones" → abre bandeja.
**Resolución automática:** cuando se le asigna al menos 1 habitación más.

### 3.6 P2 — `ticket_nuevo`

**Disparador:** cualquier usuario crea un ticket.
**Contexto:** `{ ticket_id, prioridad_ticket, habitacion_id }`.
**Título:** "Ticket {prioridad}: {titulo}"
**Descripción:** "{Usuario} levantó un ticket en {habitacion}."
**Botones:**
1. "Ver ticket" → abre detalle.
2. "Asignar" → si tiene permiso.
**Resolución automática:** cuando el ticket pasa a `resuelto` o `cerrado`.

---

## 4. Algoritmo predictivo — `trabajador_en_riesgo`

### 4.1 Variables

- `tiempo_promedio_personal` — media de `timestamp_fin - timestamp_inicio` de las últimas 20 ejecuciones del trabajador (filtrando outliers > 2σ). Si tiene <5 ejecuciones históricas → usa `tiempo_promedio_global` del tipo de habitación.
- `habitaciones_restantes` — count de asignaciones activas del día, estado ∈ `{sucia, en_progreso, rechazada}`.
- `tiempo_restante_turno` — minutos hasta `usuarios_turnos.hora_fin`.
- `margen_seguridad_minutos` — default **15 min**. Configurable en `alertas_config.margen_seguridad_minutos`.

### 4.2 Fórmula

```
tiempo_estimado_faltante = habitaciones_restantes × tiempo_promedio_personal

EN RIESGO si:
  tiempo_estimado_faltante > (tiempo_restante_turno - margen_seguridad_minutos)
```

### 4.3 Momentos de recálculo

- Al marcar habitación como terminada (trigger).
- Cada **15 min** (cron).
- Al cambiar `alertas_config` (trigger manual).
- Al reasignar habitaciones (trigger).

### 4.4 Primer turno de un trabajador nuevo

Si `tiempo_promedio_personal` no se puede calcular (< 5 ejecuciones):
- Usar promedio global del tipo de habitación.
- Si tampoco hay datos → usar **30 min por habitación** como fallback.
- Agregar flag `es_estimacion_conservadora: true` en el contexto de la alerta.

---

## 5. Visibilidad y permisos

- **`alertas.recibir_predictivas`** — requerido para ver P0, P1, P2 operativas + técnicas.
- Sin este permiso: no se muestra la sección de alertas en la Home.
- El **trabajador nunca** tiene este permiso por defecto.

Admin y Supervisora ven las mismas alertas (mismo permiso). Diferencia: Admin también ve alertas técnicas del sistema (implícito porque tiene `sistema.ver_salud`).

---

## 6. Ciclo de vida en BD

### 6.1 Al levantar

- INSERT en `alertas_activas`.
- INSERT en `bitacora_alertas` con `levantada_at=now`, `resuelta_at=NULL`.

### 6.2 Al resolver

- DELETE de `alertas_activas`.
- UPDATE `bitacora_alertas`: `resuelta_at=now`, `resolucion`, `resuelta_por`, `accion_tomada`.

### 6.3 Tipos de resolución

- `auto` — la condición desapareció (recálculo lo determina).
- `accion_usuario` — botón presionado (reasignar, asignar, ver ticket).
- `descartada` — (NO se usa en MVP — las alertas no tienen botón descartar; se resuelven solas o con acción).

---

## 7. Configuración (Ajustes → Alertas)

Permiso `alertas.configurar_umbrales` permite editar `alertas_config`:

| Clave | Default | Descripción |
|---|---|---|
| `margen_seguridad_minutos` | `15` | Margen del algoritmo predictivo |
| `fin_turno_anticipo_minutos` | `30` | Minutos antes del fin de turno para alerta |
| `recalculo_intervalo_minutos` | `15` | Frecuencia del cron de recálculo |
| `tiempo_fallback_nueva_habitacion` | `30` | Fallback cuando no hay histórico |

---

## 8. Endpoints

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/alertas/activas` | `alertas.recibir_predictivas` | Top 5 + total |
| GET | `/api/alertas` | `alertas.recibir_predictivas` | Listado paginado |
| POST | `/api/alertas/{id}/accion` | según acción | Ejecuta botón 1 o 2 |
| GET | `/api/alertas/bitacora` | `alertas.recibir_predictivas` | Histórico |
| PUT | `/api/alertas/config` | `alertas.configurar_umbrales` | Editar umbrales |

---

## 9. Referencias cruzadas

- [home-supervisora.md](home-supervisora.md) §4 — UI de alertas
- [home-admin.md](home-admin.md) §5 — tab Alertas técnicas
- [cloudbeds.md](cloudbeds.md) §5.2 — trigger de P0
- [auditoria.md](auditoria.md) §4.3 — trigger de `habitacion_rechazada`
- [tickets.md](tickets.md) — trigger de `ticket_nuevo`
- [database-schema.sql](database-schema.sql) — `alertas_activas`, `bitacora_alertas`, `alertas_config`
- [roles-permisos.md](roles-permisos.md) §2.12
