# Ficha viva de KPIs — Sueldos / Aseo

> **Qué es esto.** El documento de referencia donde queda escrita la definición
> **cerrada** de cada KPI del equipo de Aseo que usaremos para alimentar al área
> de sueldos. Es la "hoja de planos" del reloj: se afina de a poco y, cuando esté
> firme, de acá salen el **reporte de KPIs** (pieza 2) y el **reporte de sueldos**
> (pieza 3). Si acá no está confirmado, no se construye.
>
> **Estado del documento:** Rol A (Trabajador de aseo) · N1, N2, N3 **cerrados** ✅. Rol B
> (Supervisora) · N1, N2, N3 **cerrados** ✅ — **definición de ambos roles COMPLETA**. Próximo:
> armar las piezas (reporte de KPIs + reporte de sueldos).
> **Última actualización:** 15/09/2026.

## Alcance y decisiones base (confirmadas)

- **Solo Aseo (limpieza)** en esta etapa. Recepción y Alimentación quedan fuera.
- **Cruce por RUT**, sin tocar el esquema (RUT ya es la llave con la planilla de
  sueldos). No se agrega campo cargo/posición por ahora.
- **Asistencia fuera de alcance**: días laborados / ausencias vendrán de una futura
  app de administración de horarios (también cruzará por RUT).
- **El dinero ($/crédito, montos de bono) queda en sueldos**, no en la app. La app
  entrega KPIs; sueldos aplica sus tarifas y fórmulas.
- **Población del reporte:** los trabajadores con ejecuciones de limpieza en el
  rango (es el equipo de aseo; recepción/cocina no limpian piezas → quedan fuera solos).

## Convenciones transversales (las "tuercas" que hacen encajar las piezas)

Para que los KPIs sean comparables entre sí y reproducibles, **todos** respetan:

| Tuerca | Regla | Estado |
|---|---|---|
| **Ventana de fecha** | Rango **configurable** (día / semana / mes / personalizado). Todos los KPIs usan **la misma ventana** = **fecha de limpieza** (`ejecuciones_checklist.timestamp_inicio`), en zona `America/Santiago`. | ✅ confirmado |
| **Motivo del rango libre** | Permite comparar **días de alta presión vs. holgura** para ajustar el equipo a futuro. | ✅ |
| **Alcance de ítems (créditos)** | Solo ítems **obligatorios** dan crédito. | ✅ confirmado |
| **No auditadas = aprobadas** | Una pieza que queda **sin auditar al cierre del día** se considera **aprobada** para el cálculo de KPIs del **trabajador** (la falta de auditoría no lo penaliza). La cobertura baja es señal de la **Supervisora**, no del trabajador. | ✅ confirmado |
| **Privacidad de tiempos** | El **tiempo NUNCA se muestra al trabajador** (regla del proyecto). Solo gestión/sueldos. | ✅ confirmado |
| **Atribución** | A quién se le cuenta cada métrica (por quien marcó el ítem vs. dueño de la ejecución). | 🔶 se define por KPI (ver cada uno) |
| **Reproducibilidad** | Los créditos se recalculan en vivo desde el template; editar un ítem re-valúa meses cerrados. Posible **snapshot/cierre de período** en fase posterior. | ⚠️ pendiente (no Nivel 1) |

---

## Orden de trabajo (roadmap)

Definido con Nicolás (13/09): **un rol a la vez, recorriendo sus niveles**, para no
mezclar información ni perder detalle entre roles.

1. **Rol A — Trabajador de aseo:** Nivel 1 → Nivel 2 → Nivel 3.
2. **Rol B — Supervisora:** Nivel 1 → Nivel 2 → Nivel 3.

**Motivo (además del orden):** los KPIs de la **supervisora se construyen sobre los del
trabajador**. En el propio Excel `BONO ASEO`, el bono de supervisión es la
productividad/calidad **de la sección** (agregado del equipo) + la cobertura de auditoría.
Definir bien al trabajador primero es también la dependencia correcta.

Las piezas (reporte de KPIs → reporte de sueldos) se **arman una vez** que los niveles del
rol están definidos.

---

# Rol A — Trabajador de aseo

## Nivel 1 — Base: cantidad · tiempo · créditos  `[CERRADO ✅]`

### KPI 1.1 — Créditos aprobados (en dos etapas)

- **Qué mide:** el trabajo de limpieza **válido** de cada trabajador en el rango,
  pesado por créditos, separado en *auditado* vs *no auditado*.
- **Dos etapas:**
  - **(A) Créditos aprobados (auditados):** de piezas con auditoría de veredicto
    `aprobado` o `aprobado_con_observacion`.
  - **(B) Créditos no auditados:** de piezas **limpiadas pero que no se alcanzaron
    a auditar** (estado `completada`, sin registro de auditoría) → **cuentan como aprobadas**.
  - **Total válido = A + B.**
- **Excluidos:** piezas **rechazadas**; ítems **desmarcados por el auditor** (a nivel
  de ítem); ítems **no obligatorios**.
- **Fórmula (créditos):** `SUM(items_checklist.creditos)` de ítems con
  `obligatorio = 1`, `ei.marcado = 1`, `ei.desmarcado_por_auditor = 0`, de
  ejecuciones en estado `completada`/`auditada` y `(veredicto IS NULL OR veredicto <> 'rechazado')`.
  La partición A/B sale del estado/veredicto de la ejecución.
- **Fuente:** `ejecuciones_items` (marcado, marcado_por, desmarcado_por_auditor) +
  `items_checklist` (creditos, obligatorio) + `auditorias` (veredicto) +
  `ejecuciones_checklist` (estado, timestamp_inicio).
- **Atribución:** por **`ei.marcado_por`** (quien marcó cada ítem) → reparte bien en
  re-limpiezas.
- **Derivado gratis — Cobertura de auditoría:** `B ÷ (A + B)` = cuánto quedó sin
  auditar. Es la señal directa de "las supervisoras no alcanzan a auditar todo"
  (equivale al *inspection coverage* de la industria).
- **Casos borde:** re-limpieza (ítems heredados no se doble-cuentan); áreas comunes
  (suman crédito pero no cuentan como "habitación", ver 1.3); pieza rechazada (fuera).
- **Estado:** ✅ definición **confirmada**. La app hoy ya suma A+B junto
  (`ReportesService::resumenMensual`); falta **separar A de B** y generalizar a rango libre.

### KPI 1.2 — Tiempo promedio por habitación

- **Qué mide:** minutos promedio que tarda el trabajador en dejar una pieza terminada.
- **Fórmula:** `AVG(timestamp_fin − timestamp_inicio)` en minutos, de ejecuciones
  `completada`/`auditada` con `timestamp_fin` no nulo.
- **Fuente:** `ejecuciones_checklist.timestamp_inicio` / `timestamp_fin`.
- **Atribución:** `ec.usuario_id` (dueño de la ejecución).
- **Meta / benchmark:** 30 min (industria: 25–30 min por pieza estándar).
- **Regla:** **oculto al trabajador**; solo gestión/sueldos.
- **Casos borde:** ejecuciones sin `timestamp_fin` se descartan; existe antifraude de
  mínimo 3 min (desde v6). Outliers pueden distorsionar el promedio → evaluar
  **mediana** en Nivel 3.
- **Estado:** ✅ existe (`ReportesService::kpiTiempoPromedio`); generalizar a rango.

### KPI 1.3 — Habitaciones limpiadas (cantidad)

- **Qué mide:** cantidad de **piezas de huésped** que el trabajador dejó limpias en el rango.
- **Fórmula:** `COUNT(DISTINCT habitacion)` de piezas de huésped
  (`es_espacio_comun = 0`) donde obtuvo ≥1 crédito válido (marcado, no desmarcado,
  ejecución no rechazada).
- **Fuente:** `ReportesService::resumenMensual` (columna `habitaciones`), generalizar a rango.
- **Atribución:** `ei.marcado_por` (coherente con créditos).
- **Meta / benchmark:** 12–18 habitaciones por turno de 8h (mid-range).
- **Notas:**
  - Las **áreas comunes NO cuentan** como "habitación" (sí suman crédito en 1.1). Si
    en algún momento se quiere contar espacios, se hace como métrica aparte.
  - **Métrica derivada disponible:** *Actividades/día* = créditos ÷ días con actividad
    (equivalente a la columna `ACTVDES × DÍA` del Excel `BONO ASEO`).
- **Dos etapas:** el conteo se separa en **aprobadas** (auditadas y aprobadas) y
  **no auditadas** (limpiadas sin auditar, cuentan como aprobadas), igual que los
  créditos en 1.1. Rechazadas fuera. *(Confirmado 13/09/2026.)*
- **Estado:** ✅ definición **confirmada**. El conteo existe; falta implementar el split
  A/B y generalizar a rango libre.

---

## Nivel 2 — Calidad y eficiencia  `[CERRADO ✅]`

Aplica al **Trabajador de aseo**, sobre **habitaciones (piezas de huésped)**. Los
**espacios comunes** llevan esta misma batería de KPIs **por separado** (decisión 13/09).

### La base: triángulo Esperado · Aprobado · Rechazado
- **Esperado (E):** lo **programado**. Por unidad = habitación asignada; en créditos = Σ
  créditos del checklist obligatorio de esa habitación, en la **versión vigente a la fecha**.
- **Aprobado (A):** lo que quedó bien (del Nivel 1: auditado aprobado + no auditado).
- **Rechazado (R):** ejecuciones rechazadas por auditoría.
- Se mide en **dos unidades**: habitaciones y créditos.

### Resultado de la auditoría sobre TU ejecución (créditos)
1. **Aprobada** → ganás **todos** los créditos de la habitación.
2. **Aprobada con observación** → ganás **solo los créditos de los ítems que quedaron
   marcados** (se descuentan los ítems que observó/desmarcó el auditor).
3. **Rechazada** → la habitación queda como **rechazada** en tu historial **y perdés
   TODOS sus créditos** (aunque hayas hecho cosas bien). Motivo: otra persona tendrá que
   usar su tiempo en rehacerla, y esos créditos son de quien la deje bien.

### Regla de atribución y re-limpieza (confirmada 13/09)
- Una habitación **suma a tu Esperado** cuando te la **asignan** (incluye asignártela
  para **rehacerla**). El esperado es **pegajoso: no se te quita** aunque la rechacen o
  la termine otra persona. Cuenta **una vez por habitación por persona**.
- **Re-limpieza de una pieza rechazada:**
  - Si la rehacés **vos mismo** → **no cambia nada**: la pieza sigue **rechazada, sin
    créditos** (no se recupera). Es el costo del rechazo.
  - Si la rehace **otra persona** → a esa persona se le **suma +1 habitación esperada** y
    **gana todos los créditos** de la pieza (si queda aprobada tras auditoría).
- **Consecuencias a recordar:**
  - Si te rechazan y lo rehacés **vos**, esos créditos **no los cobra nadie** — se pierden.
    Es intencional: el rechazo tiene costo real.
  - Una pieza re-limpiada por otro entra en el esperado de **dos** personas (quien la hizo
    mal + quien la rehízo). A nivel individual es correcto; al **agregar por sección**
    (etapa Supervisora) se contará dos veces — tenerlo presente.

### Familia de porcentajes (en habitaciones y en créditos)
| KPI | Fórmula | Qué dice |
|---|---|---|
| **% Realización** | (A + R) ÷ E | cuánto de lo programado ejecutó |
| **% Cumplimiento** | A ÷ E | cuánto de lo programado quedó **bien** |
| **% Calidad** | A ÷ (A + R) | de lo que hizo, cuánto pasó |
| **% Rechazo** | R ÷ (A + R) | lo que hubo que rehacer |

### Eficiencia (número único)
**Eficiencia = Créditos Aprobados ÷ Créditos Esperados.** Con un solo número castiga no
hacer lo asignado **y** hacerlo mal. Como el esperado es pegajoso, un rechazo **sí** baja
la eficiencia (no desaparece del denominador). Y pesa de forma **permanente**: aunque
rehagas vos mismo la pieza, no recuperás esos créditos.

### Créditos promedio por habitación
**Créditos ÷ habitaciones.** Indica la dificultad/mezcla del trabajo de cada persona.

### Tiempos
- **Tiempo promedio por habitación** (definido en Nivel 1).
- **Ritmo = créditos/hora** (o hab/hora): productividad temporal real.

### Nota técnica (a resolver al construir)
- Implementar "esperado pegajoso + acumulativo" depende de cómo la app registra las
  re-limpiezas (¿nueva ejecución/asignación por rehacer?).
- **Ojo:** hoy la app **sí** contaría los créditos de una re-limpieza aprobada aunque
  antes hubiera un rechazo. Para cumplir la regla 3 (rechazo = créditos perdidos, **sin**
  recuperación en auto-relimpieza), habrá que marcar la pieza como "rechazada para esa
  persona en el período" y excluir sus créditos aunque después la rehaga ella misma.

**Estado:** ✅ **cerrado** (13/09).

## Nivel 3 — Comparación con el grupo  `[CERRADO ✅]`

Aplica a **todos los KPIs** (Nivel 1 + 2) del trabajador de aseo. Cada KPI, por persona,
muestra 4 datos: **tu valor · promedio del equipo · Δ (tu valor − promedio) · semáforo**.

### Promedio y dispersión
- **Promedio del equipo** = media de los valores por trabajador (equipo de aseo, mismo
  período y filtro).
- **Dispersión** = **desviación estándar (σ)** del equipo en ese KPI → es la vara del semáforo.
- **Δ mostrado** = tu valor − promedio (el "+5 pts" del ejemplo).

### Semáforo (umbrales configurables, mismo patrón que el umbral de alertas predictivas)
- dentro de ~1σ → 🟢 en línea con el grupo
- 1–2σ del lado de alerta → 🟡 atención
- más de 2σ del lado de alerta → 🔴 fuera de rango

### Dirección de alerta por KPI
| KPI | Dirección | Se marca si… |
|---|---|---|
| Créditos, Habitaciones, % Realización, % Cumplimiento, % Calidad, Eficiencia | más = mejor | muy **por debajo** del promedio |
| % Rechazo | menos = mejor | muy **por encima** |
| **Tiempo por habitación** · **Ritmo (créditos/hora)** | **dos lados** | muy lejos **hacia cualquier lado** (lento *o* sospechosamente rápido) |
| Créditos promedio/habitación | contextual | desviación **informativa** (carga más pesada/liviana), sin rojo |

**Por qué el tiempo es de dos lados:** un valor muy por **debajo** del promedio no es
"mejor" — puede ser que la persona esté **usando el sistema a su favor** (marcar sin hacer)
o que **no sepa usarlo**. Si el equipo tarda ~30 min y alguien 5, la diferencia es
demasiado grande para ser normal: hay que revisarlo (engancha con el antifraude de 3 min).

### Reglas para que sea justo (casos borde)
- **Mínimo de datos:** un trabajador con muy pocas piezas en el período **no recibe
  semáforo** ("datos insuficientes") y **no entra** en el promedio/σ del grupo, para no
  distorsionar. Default **configurable** (p.ej. 10 habitaciones). Engancha con los casos
  borde ya resueltos en alertas predictivas (trabajador nuevo / sin histórico).
- **Población de comparación:** el "equipo" respeta el filtro activo — si mirás un hotel,
  comparás contra ese hotel. Configurable.
- La **cobertura de auditoría** NO entra en el semáforo del trabajador (es KPI de la
  Supervisora).

**Umbrales (σ 1/2 y mínimo de datos):** defaults propuestos, **ajustables** desde Ajustes.

**Estado:** ✅ cerrado (14/09). Con esto queda **completa la definición de KPIs del
Trabajador de aseo** (Niveles 1, 2 y 3).

---

# Rol B — Supervisora

> **Leveling (mismo criterio que el trabajador, confirmado 15/09):** Nivel 1 = **números
> planos**; Nivel 2 = **porcentajes**; Nivel 3 = **comparativa con el grupo/realidad**.

La supervisora se mide, en su base, por **su propia producción de auditoría** — auditar es su
trabajo, el espejo de lo que la limpieza es para el trabajador. **Los créditos NO aplican a la
supervisora** (no miden su trabajo — decisión 15/09): quedan fuera de sus KPIs.

## Nivel 1 — Base: números planos de su auditoría  `[CERRADO ✅]`

Aplica sobre **piezas de huésped** (la bandeja de auditoría excluye espacios comunes:
`AuditoriaService::bandejaPendientes` filtra `es_espacio_comun = 0`). Atribución por
**`auditorias.auditor_id`** (quien emite el veredicto). Se cuenta **por ejecución auditada**
(cada fila de `auditorias` es única por `ejecucion_id` → sin doble conteo; una re-limpieza es
una ejecución nueva con su propia auditoría).

**Ventana de fecha (propuesta):** para la supervisora la ventana es la **fecha de auditoría**
(cuándo auditó ella), **no** la fecha de limpieza. Su trabajo es auditar, así que sus KPIs se
cuentan por el evento de auditoría. Es una divergencia consciente respecto de la ventana única
del trabajador (= fecha de limpieza), porque miden trabajos y personas distintas.

**Fechas variables (requisito, confirmado 15/09):** todos los KPIs de la supervisora deben
poder **observarse en fechas variables** — día / semana / mes / **rango personalizado** — misma
tuerca transversal que el resto del proyecto. El reporte de la supervisora lo respeta como
requisito, no como opción. En la práctica hay un turno por día.

### KPI S1.1 — Piezas auditadas (por veredicto)
- **Qué mide:** cuántas piezas auditó la supervisora en el rango, desglosadas por resultado.
- **Números planos:** **total auditadas** · **aprobadas** (plenas) · **aprobadas con
  observación** · **rechazadas**.
- **Fórmula:** `COUNT(*)` sobre `auditorias` donde `auditor_id = <supervisora>` y la fecha de
  auditoría cae en el rango, agrupado por `veredicto`.
- **Fuente:** tabla `auditorias` (`auditor_id`, `veredicto`, fecha). Ya existe
  `ReportesService::resumenMensualAuditores` (hoy mensual) → **generalizar a rango libre**.
- **Casos borde:** Recepción también puede auditar (tiene permiso) → el KPI es por persona
  (`auditor_id`); para "la supervisora" se filtra por su id. Las piezas que **Cloudbeds
  auto-aprueba** NO generan fila en `auditorias` → correctamente **no** cuentan como auditadas
  por ella.
- **Estado:** ✅ confirmado. Falta generalizar de mes a rango libre.

### KPI S1.2 — Tiempo por auditación
- **Qué mide:** minutos promedio que le toma auditar una pieza — qué tan eficiente es auditando.
- **Fórmula:** `AVG(fin − inicio)` en minutos por pieza auditada.
- **Fuente:** ⚠️ **requiere cambio (decisión 15/09, opción C).** Hoy `auditorias` solo guarda
  `created_at` (instante del veredicto), no el inicio. Para este KPI hay que:
  1. Agregar al schema un **`auditorias.timestamp_inicio`** (o columna análoga).
  2. Grabar ese instante cuando la supervisora **abre la habitación** en `/auditoria` (nuevo
     evento "iniciar auditoría"; hoy `emitirVeredicto` es un único POST sin apertura previa).
  3. `fin` = `created_at` (instante del veredicto). **Duración = fin − inicio.**
- **Atribución:** por `auditor_id`.
- **Antifraude / outliers (a afinar al construir):** evaluar un mínimo razonable de duración
  (paralelo al `DELAY_MINIMO_COMPLETAR` del trabajador) para descartar aperturas accidentales,
  y mediana/descarte de outliers por pausas.
- **Pendiente menor (no bloquea la definición):** decidir si la propia supervisora ve su tiempo
  (para el trabajador el tiempo es oculto; acá el tiempo es de ella y mide su eficiencia). Se
  resuelve al construir.
- **Estado:** ✅ definición confirmada; ⚠️ **no computable hasta implementar el cambio (C)**
  (columna de inicio + evento de apertura en `/auditoria`). Al depender de un dato nuevo, este
  KPI **solo tendrá histórico desde que se implemente** — las auditorías previas no tienen inicio
  grabado, así que en fechas anteriores aparecerá vacío.

**Estado del Nivel 1:** ✅ cerrado (15/09). *Nota: S1.2 tiene una dependencia de build
(schema + flujo de `/auditoria`) que se ejecuta cuando toque construir, no ahora.*

## Nivel 2 — Porcentajes  `[CERRADO ✅]`

Los porcentajes que salen del N1. Set **acotado** (la tarea de la supervisora es más puntual,
decisión 15/09): tres KPIs.

**Ámbito / "sección" (confirmado 15/09):** hoy **no hay sección** — las supervisoras están a
cargo de **ambos hoteles** (quedan cerca), así que la sección es el **universo total**. El N2 se
calcula sobre todo el universo, sin filtrar por hotel. Queda **parametrizable**: si a futuro se
divide (una supervisora por hotel o por zona), el mismo cálculo se filtra por hotel — el código
ya está preparado (`ReportesService::kpis(..., usuarioId=null)` acepta el filtro de hotel). Sin
cambios de schema.

### KPI S2.1 — Cobertura de auditoría  *(su KPI estrella)*
- **Qué mide:** de todas las piezas que el equipo dejó limpias en el rango, **qué % se alcanzó a
  auditar**. Es el termómetro de la **gestión de supervisión** (¿dio abasto para revisar?), no de
  la calidad del trabajo.
- **Fórmula:** `piezas auditadas ÷ piezas limpiadas × 100`, sobre el universo total, contando por
  ejecución (sin doble conteo).
- **Ventana:** por **fecha de limpieza** (`ejecuciones_checklist.timestamp_inicio`). Es forzoso:
  las piezas sin auditar no tienen fecha de auditoría, así que la única fecha común es la de
  limpieza. (Distinto de los conteos del N1, que van por fecha de auditoría — miden cosas
  distintas.)
- **Piezas auto-aprobadas por Cloudbeds (decisión 15/09):** cuentan como **NO auditadas** →
  **bajan la cobertura** (nadie humano las revisó; la cobertura mide revisión humana real).
  **Contraparte para el trabajador:** esas mismas piezas quedan **aprobadas** al cierre de la
  noche (aprobación automática de la app, "por temas de app") y **NO lo perjudican** — coherente
  con "no auditadas = aprobadas" del trabajador. Mismo dato, dos lecturas: baja la cobertura de
  la supervisora, no la calidad del trabajador.
- **Fuente:** derivable del `LEFT JOIN ejecuciones_checklist → auditorias`; la señal base ya
  existe en `ReportesService::auditoriasPendientes` (hoy por día/turno) → **generalizar a rango**.
- **Enganche con el trabajador:** es el `A ÷ (A+B)` del trabajador (créditos auditados vs no
  auditados), pero contando **piezas de toda la sección**.
- **Estado:** ✅ confirmado. Falta el método por rango sobre el universo.

### KPI S2.2 — % de rechazo de la sección
- **Qué mide:** de lo que se auditó, cuánto **hubo que rehacer** (re-clean rate de la industria).
- **Fórmula:** `rechazadas ÷ total auditadas × 100`.
- **Fuente:** ✅ ya existe agregado — `ReportesService::kpiTasaRechazo(..., usuarioId=null)`.
  Meta actual 5 % (benchmark industria <5 %).

### KPI S2.3 — % de aprobación a la primera de la sección
- **Qué mide:** cuánto **pasó bien de una** (inspection pass rate de la industria).
- **Fórmula:** `(aprobadas + aprobadas con observación) ÷ total auditadas × 100`.
- **Fuente:** ✅ ya existe agregado — `ReportesService::kpiAprobacionPrimera(..., usuarioId=null)`.
  Meta actual 95 % (benchmark ~98 %).
- **Nota:** S2.2 y S2.3 son **complementarios** (suman ~100 %: lo que no se rechaza, se aprueba
  de una). Se mantienen los dos por ser intuitivos desde ángulos opuestos; se puede dejar uno
  solo si más adelante se prefiere.

**Fechas variables:** los tres KPIs del N2 respetan el requisito transversal — día / semana / mes
/ rango personalizado.

**Nota técnica (al construir):** al agregar la sección, cuidar el **doble conteo** de
re-limpiezas (una pieza rehecha por otra persona entra en el "esperado" de dos personas) y de
nocheros (2 ejecuciones/día) → contar por ejecución + la regla de "esperado" ya definida en el N2
del trabajador.

**Estado del Nivel 2:** ✅ cerrado (15/09).

## Nivel 3 — Comparativa  `[CERRADO ✅]`

La comparativa de la supervisora es **distinta a la del trabajador** por dos motivos ya
establecidos: hay **una sola sección** (el universo, compartida por todas las supervisoras) y son
**pocas** (cubren ambos hoteles). Por eso es **deliberadamente más simple**: no busca el rigor
estadístico del trabajador, sino una **vista rápida de qué está pasando en el hotel** — quién
audita y quién se queda atrás. Tiene **dos lentes** + metas configurables.

### Lente 1 (principal) — La sección contra metas + tendencia
Aplica a los 3 porcentajes del N2 (cobertura, rechazo, aprobación a la primera) sobre el universo.
- **Semáforo contra META** (no contra σ): 🟢 cumple · 🟡 cerca · 🔴 lejos de la meta.
- **Tendencia:** valor del período vs. el período anterior → flecha ▲▼ (¿la sección mejora o
  empeora en el tiempo?).
- **Dirección por KPI:** cobertura y aprobación a la primera → *más = mejor* (alerta si **bajo**
  la meta); % rechazo → *menos = mejor* (alerta si **sobre** la meta).

### Lente 2 (secundaria) — Entre supervisoras, en lo personal
Aplica a las métricas personales de auditoría del N1, para ver **quién audita y quién se queda
atrás**:
- **Piezas auditadas** por cada una · **tiempo por auditación** · **aporte a la cobertura**
  (piezas que auditó ella ÷ total limpiadas de la sección).
- **Versión simple (NO σ):** por cada supervisora → su valor · **promedio simple** del grupo · Δ
  (su valor − promedio). Nada más. Motivo: con 2-3 personas el σ sería ruidoso; el promedio simple
  basta para la vista rápida. Es a propósito menos sofisticada que la del trabajador.
- **Tiempo por auditación = dos lados** (muy lento = no da abasto; muy rápido = revisión
  superficial), igual que el trabajador.
- **Fuente:** `ReportesService::resumenMensualAuditores` (por `auditor_id`) → generalizar a rango;
  el aporte a la cobertura cruza lo auditado por ella con el total limpiado del universo.

### Metas y configuración
- **Metas configurables desde Ajustes** (mismo patrón que los umbrales de alertas predictivas /
  del trabajador), por rol con el permiso correspondiente.
- **Defaults:** % rechazo ≤ **5 %**, % aprobación a la primera ≥ **95 %** (ya existen en el
  código como metas), y **cobertura ≥ (meta a confirmar, propuesta inicial ~90 %)**. La meta de
  cobertura es la única sin número histórico → se fija contigo; al ser configurable, no bloquea.

**Estado del Nivel 3:** ✅ cerrado (15/09).

---

> **✅ Definición de KPIs de la SUPERVISORA COMPLETA (N1 + N2 + N3).** Con el Trabajador ya
> cerrado, ambos roles del equipo de Aseo quedan definidos. Próximo: armar las **piezas** —
> reporte de KPIs y reporte de sueldos (cruce por RUT, reusar `ExcelExport`).

---

## Banco de KPIs de la industria (referencia, NO comprometidos)

De la búsqueda del 13/09/2026 (detalle en la memoria del proyecto). Guardados por si
suben de nivel más adelante; **ninguno es Nivel 1**:

- **Room turnaround time** (checkout → habitación lista): no se puede hoy (falta hora
  de checkout de Cloudbeds). *Ops.*
- **% habitaciones listas a tiempo** (antes del check-in): falta concepto de deadline. *Ops.*
- **Tiempo de respuesta a incidencias** (pieza prioritaria/cambio de estado). *Ops.*
- **CPOR** (coste por habitación ocupada): necesita $. *Pieza sueldos.*
- **Score de limpieza del huésped** (Booking/encuestas): la app no ingiere reseñas. *Recepción/futuro.*

Benchmarks útiles como metas: hab/turno **12–18**, min/hab **25–30**, pass rate **~98%**,
re-clean **<5%**.

---

## Registro de decisiones

| Fecha | Decisión |
|---|---|
| 13/09/2026 | Alcance solo Aseo; cruce por RUT; asistencia fuera (futura app de horarios). |
| 13/09/2026 | Nivel 1 = **cantidad, tiempo, créditos**. |
| 13/09/2026 | Créditos en **dos etapas** (aprobados auditados + no auditados); no auditadas cuentan como aprobadas; rechazadas fuera. |
| 13/09/2026 | Ventana de fecha **variable** y **única** para todos los KPIs = fecha de limpieza. |
| 13/09/2026 | KPIs validados contra el estándar de housekeeping; no falta ninguno básico. |
| 13/09/2026 | KPI 1.3 (habitaciones limpiadas) también en dos etapas (aprobadas / no auditadas), coherente con 1.1. **Nivel 1 cerrado.** |
| 13/09/2026 | Orden de trabajo: **un rol a la vez por niveles** — primero Trabajador (N1→N2→N3), después Supervisora. |
| 13/09/2026 | Nivel 2 (trabajador): "esperado" = créditos del checklist **vigente a la fecha**; una re-limpieza cuenta **1 vez**. |
| 13/09/2026 | **Espacios comunes = KPIs propios y separados** de habitaciones (mismos índices, partición por `es_espacio_comun`). Mergeables a futuro si la administración lo decide; la base queda hecha igual. |
| 13/09/2026 | Atribución Nivel 2: Esperado **pegajoso** (no se quita al rechazar) y **acumulativo** (quien rehace también suma esperado); resultado (aprob/rech + créditos) a quien hizo cada ejecución. |
| 13/09/2026 | Nuevo KPI Nivel 2: **créditos promedio por habitación** (créditos ÷ habitaciones). |
| 13/09/2026 | Resultado de auditoría: aprobada = todos los créditos; con observación = solo ítems marcados; **rechazada = pierde TODOS los créditos + queda rechazada**. Auto-relimpieza **no recupera**; si la rehace otro, ese gana la habitación completa (+esperado, +créditos). **Nivel 2 del trabajador cerrado.** |
| 14/09/2026 | Explícito: piezas **sin auditar al cierre del día = aprobadas** para los KPIs del trabajador (no lo penaliza la falta de auditoría; cobertura baja = señal de la Supervisora). Realización se mantiene (mide ejecución de lo asignado; Cumplimiento = Realización × Calidad). |
| 14/09/2026 | Nivel 3 (trabajador): comparación con el grupo sobre **todos** los KPIs — valor · promedio · Δ · **semáforo por σ (1σ/2σ, configurable)**. Mínimo de datos configurable; nuevos/pocos datos fuera del promedio. Comparación respeta el filtro (hotel). |
| 14/09/2026 | **Tiempo y ritmo = semáforo de DOS lados**: alejarse mucho del promedio hacia cualquier lado alerta (muy lento = ayuda; muy rápido = posible mal uso/gaming). **Nivel 3 cerrado → definición de KPIs del Trabajador COMPLETA.** |
| 15/09/2026 | **Leveling de la Supervisora = mismo criterio que el trabajador**: N1 números planos, N2 porcentajes, N3 comparativa. La cobertura de auditoría (que es un %) baja al **Nivel 2**, no al 1. |
| 15/09/2026 | **Créditos NO aplican a la supervisora** — no miden su trabajo. Fuera de sus KPIs. |
| 15/09/2026 | **Nivel 1 supervisora = su producción de auditoría en crudo, lo más básico:** (S1.1) piezas auditadas por veredicto (aprobadas / con observación / rechazadas / total) y (S1.2) tiempo por auditación. Atribución por `auditor_id`; ventana propuesta = **fecha de auditoría** (no fecha de limpieza). |
| 15/09/2026 | **Tiempo por auditación → opción (C):** grabar el `timestamp_inicio` de la auditoría (agrega columna al schema + evento "abrir habitación" en `/auditoria`; fin = instante del veredicto). Hoy `auditorias` solo tiene `created_at`. **Nivel 1 de la Supervisora cerrado.** |
| 15/09/2026 | **Fechas variables (requisito):** todos los KPIs de la supervisora deben poder observarse en día / semana / mes / **rango personalizado** — misma tuerca transversal del proyecto. |
| 15/09/2026 | **Ámbito de la Supervisora = universo total (ambos hoteles):** hoy no hay "sección" — las supervisoras cubren ambos hoteles (quedan cerca). Parametrizable por hotel a futuro; sin tocar schema. |
| 15/09/2026 | **Nivel 2 supervisora = set acotado, 3 porcentajes:** (S2.1) cobertura de auditoría, (S2.2) % de rechazo de la sección, (S2.3) % de aprobación a la primera. Su tarea es más acotada. |
| 15/09/2026 | **Cobertura — Cloudbeds:** las piezas auto-aprobadas por Cloudbeds cuentan como **NO auditadas** (bajan la cobertura de la supervisora); contraparte: para el **trabajador** quedan **aprobadas** al cierre (no lo perjudican). Ventana de cobertura = fecha de limpieza. **Nivel 2 de la Supervisora cerrado.** |
| 15/09/2026 | **Nivel 3 supervisora = comparativa con dos lentes + metas** (más simple que la del trabajador, a propósito): **(1) sección vs metas + tendencia** (semáforo contra meta, no σ; flecha ▲▼ vs período anterior) sobre los 3 % del N2; **(2) entre supervisoras en lo personal** (piezas auditadas, tiempo por auditación, aporte a la cobertura) con **promedio simple + Δ** (no σ, son pocas) → "quién audita y quién se queda atrás". Metas configurables desde Ajustes (rechazo ≤5 %, aprobación ≥95 %, cobertura ~90 % a confirmar). Tiempo = dos lados. **Nivel 3 cerrado → definición de la Supervisora COMPLETA (N1+N2+N3).** |
