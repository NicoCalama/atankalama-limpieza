# Ficha viva de KPIs — Sueldos / Aseo

> **Qué es esto.** El documento de referencia donde queda escrita la definición
> **cerrada** de cada KPI del equipo de Aseo que usaremos para alimentar al área
> de sueldos. Es la "hoja de planos" del reloj: se afina de a poco y, cuando esté
> firme, de acá salen el **reporte de KPIs** (pieza 2) y el **reporte de sueldos**
> (pieza 3). Si acá no está confirmado, no se construye.
>
> **Estado del documento:** Rol A (Trabajador de aseo) · Niveles 1, 2 y 3 **cerrados** ✅ — definición del Trabajador COMPLETA. Próximo: Rol B (Supervisora).
> **Última actualización:** 14/09/2026.

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

# Rol B — Supervisora  `[PENDIENTE — después del trabajador]`

Se define cuando cerremos los tres niveles del trabajador. Anticipo (de `BONO ASEO`):
desempeño de la **sección** = productividad + calidad del equipo (agregado de los KPIs del
trabajador) + **cobertura de auditoría** (% de piezas efectivamente auditadas por turno).

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
