# Checklist — templates, ejecución y persistencia tap-a-tap

**Versión:** 1.0 — 2026-04-14

Documenta los templates de checklist por tipo de habitación, la ejecución por el trabajador con persistencia inmediata de cada marca, y el manejo de offline.

---

## 1. Modelo conceptual

- **Template**: conjunto ordenado de items por tipo de habitación. Editable por Admin.
- **Ejecución**: instancia concreta de un template para una habitación asignada, en un momento dado. Una ejecución = una limpieza.
- **Items de la ejecución**: estado marcado/desmarcado de cada item del template al momento de la ejecución.

Una habitación puede tener múltiples ejecuciones a lo largo del tiempo (una por ciclo de limpieza).

---

## 2. Templates

### 2.1 Estructura

- Un template = N items ordenados.
- Cada item: `orden`, `descripcion`, `obligatorio` (default true).
- Items `obligatorio=0` son opcionales (no bloquean el botón "habitación terminada", pero siguen siendo marcables).

### 2.2 Ejemplos (MVP seed)

**Template "Habitación doble estándar":**
1. Retirar ropa de cama usada
2. Limpiar y desinfectar baño completo
3. Reponer toallas
4. Hacer cama con sábanas limpias
5. Aspirar alfombra y pisos
6. Limpiar superficies (mesa de noche, escritorio)
7. Reponer amenities (shampoo, jabón, etc.)
8. Vaciar basureros
9. Revisar iluminación y aire
10. Inspección final

### 2.3 Edición (requiere `checklists.editar`)

Admin edita desde Ajustes → Checklists templates:
- Reordenar items (drag & drop).
- Editar descripción.
- Toggle `obligatorio`.
- Desactivar un item (`activo=0`) — no elimina, preserva histórico de ejecuciones.
- Agregar items nuevos.

**Importante:** editar un template **no** afecta ejecuciones ya en progreso. Las ejecuciones usan snapshot del template al momento de crearse (a través de `template_id` + `items_checklist`, filtrando `activo=1` al **momento de la consulta de la ejecución**). Para robustez futura, podría copiarse la estructura al iniciar la ejecución, pero MVP mantiene referencia.

---

## 3. Ejecución — flujo del trabajador

### 3.1 Inicio

Cuando el trabajador abre una habitación desde su Home:

1. Si `habitacion.estado == 'sucia'` + no hay ejecución previa → POST `/api/habitaciones/{id}/iniciar` crea `ejecuciones_checklist` con `estado='en_progreso'`, `timestamp_inicio=now`. Habitación pasa a `en_progreso`.
2. Si ya existe ejecución `en_progreso` para esa habitación/asignación → la reanuda (muestra checks ya marcados).

### 3.2 Persistencia tap-a-tap

Cada tap en un item dispara inmediatamente:

`PUT /api/ejecuciones/{ejecucion_id}/items/{item_id}`
```json
{ "marcado": true }
```

Respuesta:
```json
{ "ok": true, "data": { "progreso": { "marcados": 7, "total": 10, "porcentaje": 70 } } }
```

- Se hace `INSERT OR REPLACE` en `ejecuciones_items` (UNIQUE por `(ejecucion_id, item_id)`).
- NO se espera respuesta para animar el check en UI (optimistic update). Si falla, se revierte.
- Logging: cada tap va a `audit_log` con `accion='checklist.marcar_item'` (o `desmarcar`). Útil para detectar patrones anómalos.

### 3.3 Offline

Si `navigator.onLine === false` o el PUT falla por red:

1. El check se guarda en **cola local** (`localStorage` key `checklist_queue_{ejecucion_id}`).
2. Cuando vuelve conexión (evento `online` del browser), la cola se procesa en orden.
3. Mientras haya items en cola: badge visual "Sincronizando X items..." en el top de la pantalla.
4. Si un item falla definitivamente (ej. 500 repetido) tras 3 reintentos → mostrar banner rojo "Algunos cambios no se guardaron. Intenta más tarde o contacta soporte.".

Estructura de item en cola:
```json
{ "ejecucion_id": 42, "item_id": 3, "marcado": true, "timestamp_local": "2026-04-14T09:23:15.123Z" }
```

Cuando el PUT se procesa con éxito, se elimina de la cola.

### 3.4 Reanudar tras cerrar app

Si el trabajador cierra la app y vuelve a entrar:
- Su Home muestra la habitación como "Continuar" (no "Iniciar").
- Al abrirla, GET `/api/ejecuciones/{id}` trae el estado actual de cada item.
- Los items en la cola local tienen prioridad (optimismo local) hasta que el sync los confirme.

### 3.5 Botón "Habitación terminada"

- **Deshabilitado** mientras haya items obligatorios sin marcar.
- **Habilitado** cuando el porcentaje de items obligatorios marcados == 100%.
- Items opcionales no cuentan para este gate (pero sí se muestran en UI).

Al tocarlo:
- Confirmación modal: "¿Confirmar que terminaste esta habitación? Pasará a auditoría."
- POST `/api/habitaciones/{id}/completar`:
  - Valida 100% obligatorios marcados (backend también, no solo frontend).
  - Setea `ejecuciones_checklist.estado='completada'`, `timestamp_fin=now`.
  - Setea `habitaciones.estado='completada_pendiente_auditoria'`.
  - Respuesta incluye redirect al Home con toast "Habitación lista para auditoría".

---

## 4. Tracking de tiempo (oculto)

`timestamp_inicio` y `timestamp_fin` se guardan pero **NUNCA** se muestran al trabajador.

Usos:
- **Tiempo promedio personal**: se calcula como `AVG(timestamp_fin - timestamp_inicio)` para las últimas N ejecuciones del trabajador. Input de alertas predictivas (ver [alertas-predictivas.md](alertas-predictivas.md)).
- **KPIs internos** en Home Admin (tiempo promedio general, outliers).
- **Reportes Fase 2** (post-MVP).

El trabajador solo ve un reloj de "habitaciones hoy" y su `kpis.ver_propios` — nunca el timestamp exacto.

---

## 5. Items desmarcados por auditor

Cuando un auditor hace "aprobado con observación", puede desmarcar items específicos del checklist (con permiso `auditoria.editar_checklist_durante_auditoria`).

Flujo:
1. Auditor en la UI desmarca items que considera mal ejecutados.
2. Al confirmar veredicto, esos items se actualizan: `ejecuciones_items.marcado=0`, `desmarcado_por_auditor=1`.
3. El array de `item_ids` desmarcados se guarda en `auditorias.items_desmarcados_json`.

Efectos:
- NO se cuenta como "rechazo" de la habitación (sigue siendo aprobada).
- Afecta KPIs a nivel de ítem (qué items son desmarcados con frecuencia → candidato a capacitación).
- NO se muestra al trabajador en su historial como rechazo.

Detalle en [auditoria.md](auditoria.md) §4.

---

## 6. Endpoints REST — resumen

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/checklists/templates` | `checklists.ver` | Lista templates |
| POST | `/api/checklists/templates` | `checklists.crear_nuevos` | Crear template |
| PUT | `/api/checklists/templates/{id}` | `checklists.editar` | Editar template |
| GET | `/api/ejecuciones/{id}` | asignada a mí OR `habitaciones.ver_todas` | Estado actual de ejecución |
| PUT | `/api/ejecuciones/{id}/items/{item_id}` | asignada a mí | Marcar/desmarcar item |
| POST | `/api/habitaciones/{id}/iniciar` | asignada a mí | Crear ejecución |
| POST | `/api/habitaciones/{id}/completar` | `habitaciones.marcar_completada` | Terminar ejecución |

Detalle en [api-endpoints.md](api-endpoints.md).

---

## 7. Referencias cruzadas

- [habitaciones.md](habitaciones.md) — estados y transiciones
- [auditoria.md](auditoria.md) — flujo de auditoría
- [alertas-predictivas.md](alertas-predictivas.md) — uso del tiempo promedio
- [database-schema.sql](database-schema.sql) — `checklists_template`, `items_checklist`, `ejecuciones_checklist`, `ejecuciones_items`
- [home-trabajador.md](home-trabajador.md) — UI del checklist
