# Auditoría — 3 estados, inmutabilidad y efectos

**Versión:** 1.0 — 2026-04-14

Documenta el flujo de auditoría con 3 estados (`aprobado`, `aprobado_con_observacion`, `rechazado`), la regla no negociable de inmutabilidad post-auditoría, y los efectos de cada veredicto. Desde la v7.11 hay un 4.º camino, «No se pudo inspeccionar» (`sin_inspeccion`, §2.4), que deja la pieza como el cierre de día automático.

---

## 1. Filosofía

La auditoría **no es binaria**. Tiene 3 estados porque la realidad operativa lo requiere:

- A veces un auditor encuentra algo menor (falta un amenity, una arruga) pero lo resuelve en el momento. Marcarlo como "rechazado" sería injusto con el trabajador y generaría una re-limpieza innecesaria. Tampoco debe marcarse como "aprobado limpio" porque se perdería el dato para KPIs.
- Solución: **`aprobado_con_observacion`**.

---

## 2. Los 3 estados

### 2.1 `aprobado`

- Todo bien. El trabajador hizo su trabajo.
- Habitación → `Clean` en Cloudbeds.
- KPIs del trabajador: contabiliza como aprobación limpia.

### 2.2 `aprobado_con_observacion`

- El auditor encontró detalles menores. Los **resolvió en el momento** (o los desmarca del checklist para dejar traza).
- Habitación → `Clean` en Cloudbeds (disponible para venta).
- KPIs a nivel de **ítem** (no de habitación): se registra qué items específicos fueron desmarcados.
- El trabajador **NO ve esto como rechazo** en su historial. Es información interna para capacitación agregada.
- **La observación son las casillas desmarcadas** (al menos una; v7.3, decisión de Nicolás del 10/10/2026): no hay comentario escrito. El sistema guarda en `auditorias.comentario` el texto «Observado: ítem · ítem» para el historial.

### 2.3 `rechazado`

- Problemas serios. La habitación no puede entregarse.
- Habitación → `Dirty` en Cloudbeds (no disponible).
- Supervisora es notificada vía alerta P1 `habitacion_rechazada`.
- Supervisora decide a quién reasignar (mismo trabajador para corrección, o a otro).
- KPIs: cuenta como rechazo para el trabajador original.
- **Motivo obligatorio de la lista «rechazo»** (v7.3, gerencia 10/10/2026: sin cuadros de texto), editable en Ajustes → Checklists y listas, más al menos un ítem fallido (lo que se re-limpia). Se guarda `auditorias.motivo_id` y el nombre del motivo en `comentario` (foto del momento: renombrar la opción después no cambia el historial).

### 2.4 «No se pudo inspeccionar» (`sin_inspeccion`, v7.11)

Pedido de Nicolás del 25/10/2026: la supervisora llega a inspeccionar y ya hay un huésped en la pieza. Antes la pieza seguía en la bandeja y otra supervisora volvía a tocar la puerta.

- **4.º botón** de la pantalla de inspección: «No se pudo inspeccionar». Lo ve quien tiene el permiso `auditoria.sin_inspeccion` (por defecto, quien aprueba: Supervisora, Recepción y Admin).
- **Motivo obligatorio** de la lista «sin_inspeccion» («Huésped en la pieza», «Cartel No molestar»; editable en Ajustes → Checklists y listas). No se toca el checklist: no admite casillas desmarcadas (400 `ITEMS_DESMARCADOS_NO_APLICABLE`). Se guarda `auditorias.motivo_id` y el nombre del motivo en `comentario`, como el rechazo.
- **La pieza queda igual que con el cierre de día automático** (§5 y `docs/checklist.md` §3.7): estado `aprobada_automatica`, `Clean` en Cloudbeds, sale de la bandeja y no se puede volver a inspeccionar (es un veredicto: mismo `UNIQUE` y mismo 409). Lo firma la supervisora que la marcó (origen `ui`), no el usuario Sistema.
- **KPIs, igual que el aprobado automático** (decisión de Nicolás): a la trabajadora le cuenta como aprobada y sin inspeccionar (créditos «sin inspección»); a la sección le baja la cobertura; no suma en las inspecciones ni en las Aprobadas de la supervisora. Ver `docs/kpis-sueldos.md`.
- **Nombres:** en las pantallas de uso (botón, detalle de la inspección, historial de la pieza, Ajustes) se llama «No se pudo inspeccionar». En los reportes se llama **«Sin inspección»**: en «Inspecciones pendientes al corte» (pantalla, CSV y correo de las 23:50) sale «Sin inspección (motivo)», en rojo como la del sistema, y el «Resumen mensual de inspecciones» (pantalla y Excel) la cuenta en su columna «Sin inspección», fuera del Total.
- La tarjeta de la pieza sigue mostrando el chip del estado («Aprobada auto.»).

---

## 3. Inmutabilidad post-auditoría (NO NEGOCIABLE)

Una vez una habitación recibe veredicto (cualquiera de los 3), **NO puede ser re-auditada**.

### 3.1 Backend

- Constraint `UNIQUE (ejecucion_id)` en `auditorias`.
- Endpoint `POST /api/auditoria/{habitacion_id}` chequea: si existe fila en `auditorias` para la ejecución actual, responde **409 Conflict**:
  ```json
  { "ok": false, "error": { "codigo": "AUDITORIA_YA_EXISTE", "mensaje": "Esta habitación ya fue auditada." } }
  ```
- El veredicto limpia el orden manual de la bandeja (`habitaciones.auditoria_orden = NULL`): ese orden es de la vuelta que se inspeccionó; si no, la pieza volvía arriba en su próxima inspección.
- El veredicto se escribe en una sola transacción (INSERT en `auditorias` → ítems desmarcados → ejecución `auditada` → estado de la habitación), con la habitación y la ejecución releídas con bloqueo. Si dos veredictos llegan casi juntos (dos auditoras, o una auditora y el cierre de día), el segundo recibe el mismo 409 y no deja nada escrito. La escritura `clean` a Cloudbeds va después del commit.

### 3.2 Frontend

Una habitación con auditoría:
- Aparece en listas de auditoría como **solo lectura**.
- Visualmente diferenciada: **opacidad 50%**, badge "Auditada" con color según veredicto (verde / amarillo / rojo).
- **No muestra botones de acción** (no aparecen los 3 botones de veredicto).
- Al tap → muestra modal **detalle histórico**: auditor, fecha, comentario, items desmarcados (si aplica). Solo lectura.

---

## 4. Flujo por veredicto

### 4.1 Aprobado (camino feliz)

1. Auditor tap "Aprobar" → confirmación modal ("¿Confirmar aprobación de la habitación 203?").
2. POST `/api/auditoria/{habitacion_id}` `{ "veredicto": "aprobado" }`.
3. Backend:
   - Crea fila en `auditorias`.
   - Actualiza `habitaciones.estado = 'aprobada'`.
   - Actualiza `ejecuciones_checklist.estado = 'auditada'`.
   - Encola sync Cloudbeds (Dirty → Clean). Ver [cloudbeds.md](cloudbeds.md).
   - Registra `audit_log`.
4. Response 201. UI: toast verde "Habitación aprobada", vuelve a bandeja.

### 4.2 Aprobado con observación

1. Auditor tap "Aprobar con observación".
2. Se abre **modal expandido**:
   - Checklist de la ejecución visible — el auditor **desmarca** las casillas que encontró mal (requiere `auditoria.editar_checklist_durante_auditoria`; sin ese permiso el botón no aparece). **Al menos una**: esas casillas son la observación (v7.3, sin comentario escrito).
   - Los desmarcados se recolectan en un array `item_ids[]`.
3. Confirmación → POST `/api/auditoria/{habitacion_id}`:
   ```json
   {
     "veredicto": "aprobado_con_observacion",
     "items_desmarcados": [3, 7]
   }
   ```
4. Backend:
   - Crea fila `auditorias` con el JSON de items desmarcados y `comentario` = «Observado: …» (las descripciones de esas casillas). Sin casillas: 400 `OBSERVACION_SIN_ITEMS`.
   - Actualiza `ejecuciones_items.marcado=0, desmarcado_por_auditor=1` para los items desmarcados.
   - Actualiza `habitaciones.estado = 'aprobada_con_observacion'`.
   - Encola sync Cloudbeds (→ Clean).
   - Registra `audit_log`.
5. Response 201. UI: toast amarillo "Aprobado con observación".

### 4.3 Rechazado

1. Auditor tap "Rechazar".
2. Modal:
   - Marcar los **ítems fallidos** (al menos uno): se desmarcan y son lo que se re-limpia (ver [creditos-rework.md](creditos-rework.md)).
   - Elegir el **motivo** de la lista «rechazo» (botones; v7.3, sin texto libre).
3. POST `/api/auditoria/{habitacion_id}` `{ "veredicto": "rechazado", "motivo_id": 4, "items_desmarcados": [2, 5] }`. Sin `motivo_id`: 400 `OPCION_REQUERIDA`; opción desactivada o de otra lista: 400 `OPCION_INVALIDA`.
4. Backend:
   - Crea fila `auditorias`.
   - Actualiza `habitaciones.estado = 'rechazada'`.
   - Actualiza `ejecuciones_checklist.estado = 'auditada'`.
   - **Cloudbeds: no hace nada** (ya estaba Dirty, idempotente).
   - Crea alerta P1 `habitacion_rechazada` en `alertas_activas` dirigida a supervisoras.
   - Registra `audit_log`.
5. Response 201. UI: toast rojo "Rechazada. Se notificó a supervisora.".

---

## 5. Visualización post-auditoría

### 5.1 En bandeja de auditoría

Las habitaciones auditadas **no aparecen** por defecto (la bandeja lista `completada_pendiente_auditoria`).

Cada tarjeta muestra, además del número, tipo, hotel y Nochero, **los mismos badges que ve el trabajador en su ficha** (`htmlBadgeOcupacion()` / `htmlBadgeCodigoRoomName()` en `public/assets/js/app.js`), para que la supervisora sepa qué revisar sin abrir la pieza:
- **Ocupación** (`cb_frontdesk_status`): Llega hoy / Se va hoy / Cambio / Sigue. Libre (`unused`) no lleva badge.
- **Código de camas** (último token de `cloudbeds_room_name`, ej. «2S»).

Son datos en vivo de Cloudbeds (no una foto del momento de la limpieza). Se muestran siempre, también en el celular: la fila hace wrap en vez de esconderlos.

Filtro opcional: "Incluir auditadas hoy" → muestra con opacidad 50% y badge. Útil para revisar decisiones recientes.

### 5.2 En detalle histórico

Cualquier usuario con `habitaciones.ver_historial` puede ver el historial de una habitación. Cada ejecución muestra:
- Trabajador, tiempo (para roles con `kpis.ver_globales`).
- Veredicto con color.
- Auditor, fecha, comentario.
- Items desmarcados (si observación).

---

## 6. Endpoint maestro

### `POST /api/auditoria/{habitacion_id}`

Requiere uno de: `auditoria.aprobar`, `auditoria.aprobar_con_observacion`, `auditoria.rechazar` (según el veredicto enviado — el middleware filtra).

Request:
```json
{
  "veredicto": "aprobado" | "aprobado_con_observacion" | "rechazado",
  "motivo_id": 4,                 // v7.3: obligatorio en rechazado (lista «rechazo»); se ignora en los demás
  "items_desmarcados": [1, 2, 3]  // observación y rechazo: al menos uno
}
```

Errores:
- `409` `AUDITORIA_YA_EXISTE` — inmutabilidad.
- `404` `HABITACION_NO_PENDIENTE` — estado != `completada_pendiente_auditoria`.
- `403` `PERMISO_INSUFICIENTE` — falta permiso del veredicto específico.
- `400` `COMENTARIO_REQUERIDO` — para observación/rechazo.
- `400` `ITEMS_DESMARCADOS_INVALIDO` — item_id no pertenece al template de la ejecución.

### `GET /api/auditoria/{id}/historial`

Devuelve detalle histórico de una auditoría específica.

---

## 7. Referencias cruzadas

- [habitaciones.md](habitaciones.md) — transiciones y sync Cloudbeds
- [checklist.md](checklist.md) §5 — items desmarcados por auditor
- [cloudbeds.md](cloudbeds.md) — sync Dirty ↔ Clean
- [alertas-predictivas.md](alertas-predictivas.md) — alerta P1 `habitacion_rechazada`
- [database-schema.sql](database-schema.sql) — tabla `auditorias`
- [roles-permisos.md](roles-permisos.md) §2.4 — permisos de auditoría
- [home-recepcion.md](home-recepcion.md), [home-supervisora.md](home-supervisora.md) — UI
- [CLAUDE.md](../CLAUDE.md) §"Auditoría con 3 estados"
