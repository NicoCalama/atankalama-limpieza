# Actualizaciones para `plan.md` y `claude-code-setup.md`

**Fecha:** 13 de abril de 2026
**Versión propuesta:** plan.md v3.1, claude-code-setup.md v2.1
**Origen:** decisiones tomadas durante el diseño detallado de la Home de la Supervisora

> Este documento contiene todas las decisiones nuevas que surgieron al diseñar `home-supervisora.md` v2.0 y v2.1. Hay que aplicarlas a `plan.md` y `claude-code-setup.md` para mantener la coherencia. Puedes pedirle a Claude Code en VS Code que las aplique automáticamente con un solo prompt al final de este documento.

---

## Cambios para `plan.md` v3.1

### Cambio 1 — Sección 5.3 (catálogo de permisos): agregar tabla `bitacora_alertas`

Agregar al **catálogo de tablas de la base de datos** (sección 12 del plan.md) la siguiente tabla nueva:

```
- `bitacora_alertas` — id, alerta_id (FK), accion, usuario_id (FK), timestamp, datos_json
```

**Propósito:** registrar todas las acciones que se hacen sobre las alertas (crear, ver, atender, descartar, resolver). Necesario para trazabilidad y para alimentar KPIs de respuesta de la supervisora a alertas.

**Índices sugeridos:** `alerta_id`, `usuario_id`, `timestamp`.

### Cambio 2 — Sección 8.2.2 (Home de la Supervisora): marcar como completada

En la sección 8.2 del plan.md, donde dice:

> **8.2.2 Home de la Supervisora** — PENDIENTE DE DISEÑO DETALLADO

Cambiar a:

> **8.2.2 Home de la Supervisora** — ✅ DISEÑADA EN DETALLE en `docs/home-supervisora.md` v2.1
>
> Layout en 4 secciones mobile-first: Header (con selector de hotel y opción "Ambos hoteles") + Sección de Alertas Urgentes (top 5 con jerarquía de prioridades) + Estado del Equipo (lista vertical con números visibles) + Bottom Tab Bar (Inicio / Auditoría / Tickets / Ajustes) + FAB del copilot. Refresco cada 60 segundos. Los detalles completos viven en el archivo de documentación.

### Cambio 3 — Sección 9 (alertas predictivas): agregar tipos de alerta

La sección 9 del plan.md describe alertas predictivas como un solo tipo. En realidad, durante el diseño de la Supervisora se definieron **6 tipos de alertas** con distintas prioridades. Agregar al final de la sección 9.2:

> **Tipos de alerta y prioridades (definidos en `docs/home-supervisora.md`):**
>
> - **Prioridad 0 (Crítica máxima):** Sincronización Cloudbeds falló
> - **Prioridad 1 (Crítica):** Trabajador en riesgo predictivo, Habitación rechazada por Recepción, Fin de turno con pendientes
> - **Prioridad 2 (Importante):** Trabajador disponible sin carga, Ticket de mantenimiento nuevo
> - **Prioridad 3 (Menos urgente):** Trabajador disponible (anteriormente "inactivo")
>
> Las prioridades son **editables desde Ajustes** por Admin. En el MVP, todos los tickets entran como Prioridad 2.

### Cambio 4 — Sección 8.4 (auditoría): agregar regla de inmutabilidad

Agregar al final de la sección 8.4 (Módulo de Auditoría):

> **Inmutabilidad post-auditoría:** una vez una habitación recibe veredicto de auditoría (`aprobada`, `aprobada_con_observacion` o `rechazada`), **no puede ser re-auditada**. Aparece en las listas de auditoría como solo lectura, visualmente diferenciada (opaca, badge "Auditada"), sin botones de acción. Esto mantiene la trazabilidad histórica para KPIs sin ambigüedades. Aplica tanto a Supervisora como a Recepción.

### Cambio 5 — Sección 13 (próximos pasos): actualizar lista

En la sección 13 del plan.md (próximos pasos), donde dice:

> 1. **Diseño detallado de la pantalla Home** — las cuatro versiones por rol (Admin, Supervisora, Trabajador, Recepción)

Cambiar a:

> 1. ~~Home del Trabajador~~ ✅ COMPLETADA en `docs/home-trabajador.md`
> 2. ~~Home de la Supervisora~~ ✅ COMPLETADA en `docs/home-supervisora.md` v2.1
> 3. **Home de Recepción** — siguiente paso natural (más simple, mayormente auditoría)
> 4. **Home del Admin** — la última, gestión completa

### Cambio 6 — Versionar el plan a v3.1

En el header del documento:

```
**Versión:** 3.1 (incluye decisiones del diseño detallado de la Home de la Supervisora)
**Fecha del documento:** 13 de abril de 2026
```

---

## Cambios para `claude-code-setup.md` v2.1

### Cambio 7 — Sección 3 (estructura de carpetas): actualizar lista de docs

En la sección 3 (estructura de carpetas), donde está la lista de archivos esperados en `docs/`, marcar:

```
├── docs/
│   ├── home-trabajador.md       # ✅ v1.0 COMPLETO
│   ├── home-supervisora.md      # ✅ v2.1 COMPLETO
│   ├── home-recepcion.md        # 🚧 siguiente
│   ├── home-admin.md            # 🚧 pendiente
│   ├── handoff-2026-04-08.md    # ✅ documento de traspaso
│   ├── ...
```

### Cambio 8 — Sección 6 (CLAUDE.md): agregar sección sobre auditoría inmutable

En el `CLAUDE.md` raíz, en la sección "Arquitectura clave — Auditoría con 3 estados", agregar al final:

```markdown
**Inmutabilidad post-auditoría (NO NEGOCIABLE):**

Una vez una habitación recibe veredicto de auditoría (cualquiera de los 3 estados), **NO puede ser re-auditada**. En la UI:
- Aparece en las listas de auditoría como solo lectura
- Visualmente diferenciada: opacidad reducida, badge "Auditada"
- Sin botones de acción (no muestra los 3 botones)
- Tap → muestra detalle histórico (auditor, fecha, comentario, ítems desmarcados si aplica)

Backend: el endpoint `POST /api/auditoria/{habitacion_id}` debe rechazar con error 409 (Conflict) si la habitación ya tiene un registro en `auditorias` para esa ejecución.
```

### Cambio 9 — Sección 6 (CLAUDE.md): agregar sección sobre tipos de alertas

En el `CLAUDE.md` raíz, agregar nueva subsección bajo "Arquitectura clave — Alertas predictivas":

```markdown
**Tipos de alertas y prioridades (definidos en `docs/home-supervisora.md`):**

El sistema maneja 6 tipos de alertas con prioridades 0-3:

- P0: `cloudbeds_sync_failed`
- P1: `trabajador_en_riesgo`, `habitacion_rechazada`, `fin_turno_pendientes`
- P2: `trabajador_disponible`, `ticket_nuevo`
- P3: (reservado para casos futuros)

Cada tipo de alerta tiene:
- Un título claro y accionable
- Una descripción con datos concretos
- Máximo 2 botones de acción
- NO tiene botón "descartar" (las alertas persisten hasta resolverse o hasta que la condición desaparezca)

Las acciones sobre alertas se registran en la tabla `bitacora_alertas`.
```

### Cambio 10 — Sección 10 (orden de codificación): reorganizar etapas

En la sección 10 del setup, agregar a la **Etapa E (Alertas predictivas)**:

```
24. **Servicio `AlertasService`** con los 6 tipos de alertas definidos
25. **Tabla `bitacora_alertas`** con sus índices
26. **Cálculo de prioridades** según tipo y antigüedad
27. **Endpoints** para listar alertas top 5 + ver todas + ejecutar acciones
28. **Refresco automático** al completar habitaciones y cada 15 min
29. **Validación de inmutabilidad de auditoría** en backend (error 409)
```

### Cambio 11 — Versionar el setup a v2.1

En el header del documento:

```
**Versión:** 2.1 (incluye decisiones del diseño detallado de la Home de la Supervisora)
```

---

## Cómo aplicar estos cambios

### Opción recomendada: pedirle a Claude Code en VS Code

Copia este prompt entero y pégalo en el chat de Claude Code dentro del proyecto:

```
Hola Claude Code. Acabamos de terminar el diseño detallado de la Home de la
Supervisora (docs/home-supervisora.md v2.1). Esto generó decisiones nuevas
que hay que reflejar en plan.md y CLAUDE.md.

Por favor lee el archivo `docs/actualizaciones-post-supervisora.md` que
acabo de copiar al repo, y aplica TODOS los cambios listados ahí a:

1. plan.md (cambios 1-6)
2. CLAUDE.md (cambios 8-9)
3. claude-code-setup.md (cambios 7, 10-11)

Reglas:
- Cada cambio especifica qué texto buscar y qué texto poner en su lugar
- Si no encuentras exactamente el texto a reemplazar, avísame antes de
  inventar — puede que la sección esté un poco distinta
- Mantén el resto del contenido de los archivos intacto
- Al terminar, hace un commit con mensaje:
  "docs: actualizar plan.md y CLAUDE.md con decisiones del diseño de Home Supervisora"
- Push a main

Cuando termines, dame un resumen de qué cambió en cada archivo.
```

### Opción manual

Si prefieres aplicar los cambios tú mismo, abre cada archivo en VS Code y haz los reemplazos uno por uno siguiendo las instrucciones de las secciones de arriba.

---

## Resumen ejecutivo de qué cambió en el proyecto

Lista corta de las **decisiones nuevas** que surgieron al diseñar la Home de la Supervisora y que ahora son parte oficial del proyecto:

1. **Tabla `bitacora_alertas`** agregada al schema (registro de acciones sobre alertas)
2. **6 tipos de alertas con prioridades 0-3** definidos formalmente
3. **Inmutabilidad post-auditoría** — una habitación auditada no puede re-auditarse
4. **Header de la Supervisora simplificado** a 2 líneas (saludo + selector de hotel)
5. **Selector "Ambos hoteles"** como opción válida para supervisoras multi-propiedad
6. **`alertas.configurar_umbrales` exclusivo del Admin** en el MVP
7. **Bitácora de cambios v2.1** documentada en `home-supervisora.md`
8. **Bottom tab bar para la Supervisora** — recuperando consistencia con el Trabajador
9. **Renombrado "Trabajador inactivo" → "Trabajador disponible"** (cambio de framing)
10. **Eliminación del flujo "Lo haré yo"** — reemplazado por "Resolver ahora" en auditoría con observación

---

*Fin del documento de actualizaciones. Una vez aplicado, este documento puede archivarse o eliminarse del repo (su contenido ya estará reflejado en plan.md, CLAUDE.md y claude-code-setup.md).*
