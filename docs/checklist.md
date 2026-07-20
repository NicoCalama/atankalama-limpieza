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
- Cada item: `orden`, `descripcion`, `obligatorio` (default true), `creditos` (peso, default 1), `es_cambio_sabanas` (etiqueta).
- Items `obligatorio=0` son opcionales (no bloquean el botón "habitación terminada", pero siguen siendo marcables) y **no otorgan créditos** (el peso `creditos` solo cuenta si el item es obligatorio; ver [creditos-rework.md](creditos-rework.md)).

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

### 2.3 Edición (requiere `checklists.editar`) — IMPLEMENTADO

Admin edita desde **Ajustes → Checklists** (`/ajustes/checklists`, `ChecklistService::editarTemplate`):
- Reordenar items (botones subir/bajar).
- Editar descripción.
- Toggle `obligatorio`.
- Fijar el **peso de créditos** por item (input visible solo en items obligatorios; ver [creditos-rework.md](creditos-rework.md)).
- Toggle `es_cambio_sabanas` (etiqueta de sábanas).
- Agregar items nuevos.
- Quitar un item → simplemente no viaja en la versión nueva; el item viejo queda intacto en su versión.

Solo se editan acá los templates de **tipo** (piezas de huésped); los de **espacio** (áreas comunes, `habitacion_id != NULL`) se editan desde `/espacios`.

### 2.4 Versionado — copy-on-write (plan.md §8.6) — IMPLEMENTADO

**Guardar nunca modifica lo que ya existe.** Cada edición inserta una fila nueva en `checklists_template` —la `v(N+1)` de la misma raíz— con **todos** los items como filas nuevas, y deja la versión anterior en `activo=0`. Una raíz = un checklist a lo largo del tiempo; la agrupa `raiz_id` (en la v1 es igual al propio `id`) y `version` la numera.

Por qué, y no in-place: `ReportesService` suma `items_checklist.creditos` con un JOIN **en vivo** contra la tabla. Mientras los items se editaban in-place, cambiarle el peso o el `obligatorio` a un item **reescribía los KPIs de meses ya cerrados** — el puntaje que un trabajador sacó en junio se movía solo. Con copy-on-write cada ejecución sigue apuntando a los items tal como eran cuando se limpió.

Consecuencias:
- **Una limpieza en curso termina con la versión con la que empezó.** La ejecución queda clavada a su `template_id`: no le desaparecen items a mitad de camino ni le aparecen nuevos sin marcar. Las limpiezas que empiecen después usan la versión nueva.
- **El `template_id` cambia en cada guardado**, y los `id` de los items también. El `PUT` devuelve `{template_id, version}` y la UI recarga la lista; ningún cliente debe reusar los ids viejos.
- **Historial navegable:** el botón *Historial* de cada tarjeta lista las versiones (vigente + anteriores) con fecha, autor, cantidad de items y créditos, y permite ver los items de una versión vieja **en solo lectura** (`GET /api/checklists/templates/{id}/historial`, permiso `checklists.ver`). Restaurar una versión anterior no está implementado: si hace falta, se re-edita a mano y sale una versión nueva.
- Nada se borra nunca (FK RESTRICT desde `ejecuciones_items`).

Las **áreas comunes** quedan fuera de este esquema por ahora: `EspacioService::editar` da de baja sus items e inserta filas nuevas, así que sus históricos tampoco se pisan, pero sus versiones no son navegables. Unificar es pendiente.

---

## 3. Ejecución — flujo del trabajador

### 3.1 Inicio

Cuando el trabajador abre una habitación desde su Home:

1. Si `habitacion.estado == 'sucia'` + no hay ejecución previa → POST `/api/habitaciones/{id}/iniciar` crea `ejecuciones_checklist` con `estado='en_progreso'`, `timestamp_inicio=now`. Habitación pasa a `en_progreso`.
2. Si ya existe ejecución `en_progreso` para esa habitación/asignación → la reanuda (muestra checks ya marcados).

**Candado "una habitación a la vez":** el trabajador no puede iniciar una habitación
nueva mientras tenga **otra** en progreso. En ese caso `iniciar` responde `409`
con código `YA_TIENE_HABITACION_EN_PROGRESO`. Reanudar la misma habitación siempre
se permite (es el caso 2). Esto obliga a cerrar cada habitación antes de pasar a la
siguiente y evita que el trabajador acepte todo en lote. La única forma de soltar la
habitación actual sin terminarla es la válvula de escape (§3.6).

### 3.6 Válvula de escape — "No puedo terminar ahora"

Si el trabajador no puede terminar la habitación actual (huésped no salió, falta un
insumo, requiere mantención), toca **"No puedo terminar esta ahora"** en el detalle,
elige un motivo y confirma → `POST /api/habitaciones/{id}/saltar` con `{ motivo }`.

El backend:
- Descarta la ejecución en progreso (el progreso parcial se pierde; al retomar se
  empieza de cero) y libera el candado de §3.1.
- Devuelve la habitación a estado `sucia`.
- La manda **al final de la cola** del trabajador (reaparece más tarde en el turno).
- Levanta una alerta **P2 `habitacion_saltada`** a la supervisora, con el motivo. El
  trabajador nunca ve esta alerta.
- Registra el salto en `audit_log` (`accion='checklist.saltar'`, con el motivo).

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
| GET | `/api/checklists/templates` | `checklists.ver` | Lista templates de tipo vigentes (con `version`, `items_count`, `creditos_total`) |
| GET | `/api/checklists/templates/{id}/items` | `checklists.ver` | Ítems de un template (también de una versión vieja) |
| GET | `/api/checklists/templates/{id}/historial` | `checklists.ver` | Versiones del checklist, de la más nueva a la más vieja |
| POST | `/api/checklists/templates` | `checklists.crear_nuevos` | Crear template *(no implementado en MVP)* |
| PUT | `/api/checklists/templates/{id}` | `checklists.editar` | Editar ítems (descripción, orden, obligatorio, peso de créditos, sábanas). Copy-on-write: devuelve el `template_id` **nuevo** |
| GET | `/api/ejecuciones/{id}` | asignada a mí OR `habitaciones.ver_todas` | Estado actual de ejecución |
| PUT | `/api/ejecuciones/{id}/items/{item_id}` | asignada a mí | Marcar/desmarcar item |
| POST | `/api/habitaciones/{id}/iniciar` | asignada a mí | Crear ejecución (409 `YA_TIENE_HABITACION_EN_PROGRESO` si ya hay otra en curso) |
| POST | `/api/habitaciones/{id}/completar` | `habitaciones.marcar_completada` | Terminar ejecución |
| POST | `/api/habitaciones/{id}/saltar` | asignada a mí | "No puedo terminar ahora": descarta la ejecución, devuelve la habitación a `sucia`, la manda al final de la cola y alerta a la supervisora |

Detalle en [api-endpoints.md](api-endpoints.md).

---

## 7. Referencias cruzadas

- [habitaciones.md](habitaciones.md) — estados y transiciones
- [auditoria.md](auditoria.md) — flujo de auditoría
- [alertas-predictivas.md](alertas-predictivas.md) — uso del tiempo promedio
- [database-schema.sql](database-schema.sql) — `checklists_template`, `items_checklist`, `ejecuciones_checklist`, `ejecuciones_items`
- [home-trabajador.md](home-trabajador.md) — UI del checklist
