# Créditos por ítem y re-limpieza parcial (rework)

**Estado:** DISEÑO APROBADO (4 decisiones confirmadas por Nicolás) — **implementación pendiente**.
**Fecha:** 2026-07-01

Documenta el rediseño del flujo **rechazo → re-limpieza → créditos** para que los créditos midan la limpieza *realmente correcta* y se repartan por persona según lo que cada uno limpió.

---

## 1. Problema

Hoy los "créditos" se calculan así (`ReportesService::kpiCreditos` / `resumenMensual`):

```
crédito = ítem marcado (marcado=1) Y no desmarcado por el auditor (desmarcado_por_auditor=0)
```

Y el flujo actual de rechazo es:

1. **Rechazo:** solo toma un comentario. **No desmarca ningún ítem.**
2. **Reasignación:** la pieza vuelve a `sucia`.
3. **Re-limpieza:** el 2º trabajador arranca una **ejecución nueva en blanco** y **vuelve a marcar los 10 ítems**.

Consecuencias (verificadas con el test del 01/07 sobre `TES(1)`, 10 ítems):

- **Ana** (rechazada) → 10 ítems marcados, 0 desmarcados → **10 créditos**.
- **Berta** (aprobada) → 10 ítems marcados → **10 créditos**.
- Total: **20 créditos por una pieza de 10 ítems** (doble conteo) y una **limpieza rechazada cobrando el 100%**.

Como todos marcan todas las casillas, **todos tienden al 100%** y el KPI de créditos **no mide la calidad real** de la limpieza. El rechazo solo se refleja en la *tasa de rechazo*, no en los créditos.

> No es un bug de guardado (la data está bien según la fórmula actual); es que la **fórmula/semántica** no refleja la intención del negocio.

---

## 2. Modelo objetivo (el que pidió Nicolás)

1. **Al rechazar, el auditor/supervisor desmarca los ítems que quedaron mal** (lo "cochino") y deja su reporte (comentario).
2. **El 1er trabajador conserva los créditos de lo que sí limpió bien** (los ítems que quedaron marcados).
3. **El 2º trabajador continúa la misma pieza:** solo limpia **lo que quedó desmarcado**, no toda la habitación de nuevo.
4. **El 2º trabajador gana créditos solo por lo que él completó** (los ítems pendientes).

Ejemplo (pieza de 10 ítems; el auditor desmarca 3 al rechazar a Ana):

| Trabajador | Ítems que completó bien | Créditos |
|---|---|---|
| Ana | 7 (los que quedaron marcados) | **7** |
| Berta | 3 (los que estaban desmarcados) | **3** |
| **Total pieza** | 10 | **10** (sin doble conteo) |

Así los créditos **se reparten**, nadie cobra por trabajo rechazado, y el KPI empieza a reflejar la limpieza correcta.

---

## 3. Decisiones de diseño (CONFIRMADAS por Nicolás, 01/07/2026)

### 3.1 ¿Ejecución nueva por intento, o una sola que evoluciona?
**Propuesta: ejecución nueva por intento (como hoy), pero que *hereda* el estado marcado del intento anterior, con atribución por ítem.**

- Preserva la **inmutabilidad de auditoría** (`UNIQUE ejecucion_id`: una auditoría por ejecución) y el **histórico** (cada intento = su ejecución + su veredicto).
- Preserva el **tracking de tiempo por trabajador** (cada ejecución mide el tiempo de *ese* trabajador; la de Berta mide solo su rework).
- Requiere saber **quién marcó cada ítem** → nuevo campo `ejecuciones_items.marcado_por`.

*(Alternativa considerada — "una sola ejecución que evoluciona": rompería el `UNIQUE ejecucion_id` de auditoría y mezclaría los tiempos de los dos trabajadores. Descartada.)*

### 3.2 ¿El rechazo obliga a desmarcar al menos un ítem?
**CONFIRMADO: sí.** Un rechazo sin ítems marcados como fallidos no tiene sentido en este modelo — si no hay nada mal, es una aprobación. El modal de rechazo pasa a exigir: **comentario (ya obligatorio) + seleccionar ≥1 ítem fallido** (que se desmarca). Esto además hace el rechazo **más accionable** para el trabajador (sabe exactamente qué rehacer).

> Relación con "aprobado con observación": ambos **desmarcan ítems**. La diferencia es el **resultado**: observación → la pieza queda **Clean** (el auditor lo resolvió, sin rework); rechazo → la pieza vuelve **Dirty** y se re-limpia **solo lo desmarcado**.

### 3.3 ¿Se encadena (3er trabajador)?
**CONFIRMADO: sí, naturalmente, y SIN tope.** Si Berta también deja algo mal, el auditor desmarca lo suyo, un 3er trabajador completa esos ítems, y el crédito sigue a `marcado_por`. El modelo generaliza a N intentos, sin límite ni alerta especial por re-limpiezas repetidas.

### 3.4 ¿Cómo queda el % del KPI de créditos?
**CONFIRMADO: el % castiga el error.** Los **créditos absolutos** por persona son inequívocos (Ana 7, Berta 3). Para el **%**:

- **Numerador** = créditos obtenidos por la persona (ítems obligatorios que marcó y no le desmarcaron).
- **Denominador** = ítems obligatorios que la persona *intentó* (marcó, incluidos los que le desmarcaron).
- Resultado: **Ana 7/9 ≈ 78%** (se "castiga" por lo que arruinó), **Berta 2/2 = 100%** (si le tocaron 2 obligatorios) — números exactos según cuántos obligatorios se desmarquen.

Esto hace que el % refleje la **calidad** de cada quien, además de la tasa de rechazo.

### 3.6 ¿Los ítems opcionales dan crédito?
**CONFIRMADO: no.** Los créditos se calculan **solo sobre los 9 ítems obligatorios** (máx = 9). El único opcional hoy es *"Revisar iluminación y aire"*. Saltear un opcional **no baja** el %. Quien completa lo obligatorio queda 100%. (Evita castigar por no hacer algo explícitamente opcional.)

### 3.5 Re-limpieza por el mismo trabajador
Si la supervisora reasigna la pieza rechazada **al mismo** trabajador (para que corrija lo suyo), él completa los ítems desmarcados y termina ganando el crédito de todos los ítems que hizo. El rechazo igual queda contado en su **tasa de rechazo**. Se considera aceptable.

---

## 4. Cambios técnicos

### 4.1 Esquema (BD)
- **`ejecuciones_items`**: nuevo campo **`marcado_por INTEGER NULL`** (FK `usuarios`), seteado al valor del trabajador cuando marca el ítem. Migración: backfill `marcado_por = ec.usuario_id` para los ítems marcados existentes.
- (Opcional) `marcado_at` para trazabilidad fina — evaluar si hace falta.
- Actualizar `docs/database-schema.sql` y `docs/database-schema.mariadb.sql`.

### 4.2 Flujo de rechazo (`AuditoriaService::emitirVeredicto`, veredicto `rechazado`)
- Aceptar y **exigir `items_desmarcados` (≥1)** en el rechazo (hoy solo lo acepta `aprobado_con_observacion`).
- Desmarcar esos ítems en la ejecución (`desmarcado_por_auditor=1`, `marcado=0`) — reutilizar/generalizar `ChecklistService::desmarcarPorAuditor`.
- Guardar `items_desmarcados_json` en la auditoría (ya existe la columna).
- La pieza sigue yendo a `rechazada` + alerta P1 (sin cambios).
- **UI:** el modal de rechazo pasa a mostrar el checklist con los ítems para **seleccionar los fallidos** (como el de observación) + comentario obligatorio.

### 4.3 Flujo de re-limpieza (`ChecklistService::iniciarEjecucion` sobre pieza rechazada)
- Al iniciar la ejecución de re-limpieza, **heredar del intento anterior** los ítems que quedaron **marcados** (con su `marcado_por` original), y dejar **desmarcados** los que el auditor marcó como fallidos.
- El nuevo trabajador ve los heredados como **hechos (solo lectura)** y solo puede completar los pendientes → al marcarlos, `marcado_por = él`.
- El gate de "Habitación terminada" se desbloquea cuando **todos los obligatorios** (heredados + nuevos) están marcados.

### 4.4 Cálculo de créditos (`ReportesService`)
- Contar créditos **por `marcado_por`**, no por el dueño de la ejecución.
- **Solo ítems obligatorios** (los opcionales no dan crédito). Máx por pieza = 9.
- **Numerador** = obligatorios marcados y no desmarcados, por `marcado_por`.
- **Denominador (por persona)** = obligatorios que esa persona *intentó* (marcó o le desmarcaron) → el % castiga el error (Ana ≈78%).
- Contar solo desde la **ejecución final no-rechazada de cada pieza** (la aprobada, o la última pendiente), para no doble-contar los intentos rechazados heredados.
- Ajustar `kpiCreditos`, `resumenMensual` y el CSV correspondiente.
- Revisar de paso `tasa_desmarcados` y `aprobacion_primera` para consistencia.

### 4.5 Inmutabilidad de auditoría
- Sin cambios de fondo: cada ejecución (intento) mantiene su auditoría única. La de Ana (rechazado) y la de Berta (aprobado) conviven como hoy. La pantalla de auditoría ya distingue por ejecución (fix `45bee7b`).

---

## 5. Impacto en UI

- **Modal de rechazo** (`views/auditoria-detalle.php`): de "comentario" a "comentario + seleccionar ítems fallidos" (≥1).
- **Pantalla de limpieza** (`views/habitacion-detalle.php`): en una re-limpieza, mostrar los ítems heredados como **hechos/solo-lectura** y habilitar solo los pendientes.
- **Reportes** (`views/reportes.php`): los créditos por trabajadora ahora reparten; verificar textos ("créditos de lo que limpió").
- **Historial**: mostrar, por ítem, quién lo hizo (útil para la supervisora).

## 6. Casos borde a cubrir en tests
- Rechazo desmarcando K ítems → re-limpieza completa solo esos K → créditos K/(resto) repartidos.
- Encadenamiento (3 intentos).
- Re-limpieza por el mismo trabajador.
- Ítems **opcionales**: definir si cuentan para crédito (hoy no son obligatorios para completar).
- `aprobado_con_observacion` sigue funcionando (desmarca pero NO manda a rework).
- Backfill de `marcado_por` no rompe reportes históricos.

## 7. Plan de implementación por fases
1. **Schema + migración** (`marcado_por` + backfill) + actualizar los dos schemas de doc.
2. **Rechazo desmarca ítems** (backend + modal UI) + tests.
3. **Re-limpieza hereda estado parcial** (backend + pantalla) + tests.
4. **Créditos por `marcado_por`** (ReportesService + CSV) + tests de KPI.
5. **Verificación end-to-end** (script + UI) reproduciendo el caso Ana/Berta (7+3).

## 8. Preguntas abiertas — RESUELTAS (01/07/2026)
1. ✅ Ejecución nueva-que-hereda + `marcado_por` (propuesta 3.1 confirmada).
2. ✅ El % **castiga** al rechazado (denominador = lo que intentó; Ana ≈78%).
3. ✅ Los opcionales **no** dan crédito (solo los 9 obligatorios).
4. ✅ **Sin tope** de re-limpiezas (encadena N veces, sin alerta especial).

Nada bloqueante pendiente: el diseño está listo para implementar (fases §7).
