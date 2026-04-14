# Auditoría — 3 estados, inmutabilidad y efectos

**Versión:** 1.0 — 2026-04-14

Documenta el flujo de auditoría con 3 estados (`aprobado`, `aprobado_con_observacion`, `rechazado`), la regla no negociable de inmutabilidad post-auditoría, y los efectos de cada veredicto.

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
- Comentario del auditor es obligatorio (mínimo 10 caracteres).

### 2.3 `rechazado`

- Problemas serios. La habitación no puede entregarse.
- Habitación → `Dirty` en Cloudbeds (no disponible).
- Supervisora es notificada vía alerta P1 `habitacion_rechazada`.
- Supervisora decide a quién reasignar (mismo trabajador para corrección, o a otro).
- KPIs: cuenta como rechazo para el trabajador original.
- Comentario obligatorio (mínimo 10 caracteres).

---

## 3. Inmutabilidad post-auditoría (NO NEGOCIABLE)

Una vez una habitación recibe veredicto (cualquiera de los 3), **NO puede ser re-auditada**.

### 3.1 Backend

- Constraint `UNIQUE (ejecucion_id)` en `auditorias`.
- Endpoint `POST /api/auditoria/{habitacion_id}` chequea: si existe fila en `auditorias` para la ejecución actual, responde **409 Conflict**:
  ```json
  { "ok": false, "error": { "codigo": "AUDITORIA_YA_EXISTE", "mensaje": "Esta habitación ya fue auditada." } }
  ```

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
   - Campo obligatorio "Comentario" (textarea, min 10 chars).
   - Checklist de la ejecución visible — auditor puede **desmarcar** items específicos (si tiene `auditoria.editar_checklist_durante_auditoria`).
   - Los desmarcados se recolectan en un array `item_ids[]`.
3. Confirmación → POST `/api/auditoria/{habitacion_id}`:
   ```json
   {
     "veredicto": "aprobado_con_observacion",
     "comentario": "Faltaba reponer jabón. Resuelto.",
     "items_desmarcados": [3, 7]
   }
   ```
4. Backend:
   - Crea fila `auditorias` con el JSON de items desmarcados.
   - Actualiza `ejecuciones_items.marcado=0, desmarcado_por_auditor=1` para los items desmarcados.
   - Actualiza `habitaciones.estado = 'aprobada_con_observacion'`.
   - Encola sync Cloudbeds (→ Clean).
   - Registra `audit_log`.
5. Response 201. UI: toast amarillo "Aprobado con observación".

### 4.3 Rechazado

1. Auditor tap "Rechazar".
2. Modal:
   - Campo obligatorio "Comentario" (min 10 chars).
   - Opcional: seleccionar items específicos que motivan el rechazo (solo marcador visual para la supervisora, no desmarca).
3. POST `/api/auditoria/{habitacion_id}` `{ "veredicto": "rechazado", "comentario": "..." }`.
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
  "comentario": "string (opcional para aprobado, obligatorio para los otros 2)",
  "items_desmarcados": [1, 2, 3]  // solo para aprobado_con_observacion
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
