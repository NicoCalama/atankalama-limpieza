# Varias limpiezas por día (ocupación día/noche)

**Versión:** 1.0 — 2026-07-01
**Estado:** Implementado y verificado (2026-07-02) — suite 237/237, UI verificada, revisión independiente sin bloqueantes. Pendiente prod: correr `scripts/migrate-add-franja.php`.

Documenta el feature **F**: permitir que una misma habitación reciba **más de una limpieza en el
mismo día**, en distintas ventanas horarias. Nace del relevamiento de Rodrigo Jaque (01/07/2026):

> "Una habitación puede tener ocupaciones de día y de noche. Por lo que a veces solo tenemos una
> hora en la mañana y una hora en la noche para entrar a la habitación y hacer el aseo. Actualmente
> se ha solucionado duplicando la habitación, pero es muy engorroso. Así que a una habitación se le
> puede hacer aseo en distintos horarios en un mismo día."

El objetivo es hacer esto **sin duplicar la pieza** en el inventario.

---

## 1. Modelo elegido: F-A liviano/secuencial

Se descartó el modelo "servicio de limpieza como entidad nueva" (F-B, refactor grande) a favor de
**F-A**: reusar la maquinaria existente, que **ya soporta N limpiezas por pieza en un día**.

**Por qué ya funciona casi todo:**
- El flujo **rechazo → re-limpieza** ya crea, para la misma pieza y el mismo día, una `asignación`
  nueva + una `ejecución_checklist` nueva + una `auditoría` nueva. Es decir, la pieza ya sabe pasar
  por varios ciclos de limpieza en un día.
- Los **KPIs cuentan por ejecución / auditoría** (no por pieza), así que N limpiezas se contabilizan
  como N eventos separados.
- La **primitiva de re-abrir** ya existe (se construyó para áreas comunes): `AsignacionService::
  asignarManual` resetea cualquier estado terminal (`aprobada` / `aprobada_con_observacion` /
  `rechazada`) → `sucia` al crear una asignación nueva. Ver [areas-comunes.md](areas-comunes.md) §2.

**Lo único que falta** para que sea usable:
1. **Surfacear** las piezas ya limpias del día para pedirles **otra** limpieza (hoy la vista de
   asignaciones solo ofrece piezas `sucia`/`rechazada`; una pieza `aprobada` de la mañana no aparece).
2. **Distinguir** las limpiezas del día con una etiqueta de **franja/ventana** (mañana/tarde/noche).

Las limpiezas son **secuenciales** (ventana mañana, luego ventana noche), no simultáneas — que es
como ocurre en la realidad. No se necesita tener dos limpiezas abiertas a la vez.

---

## 2. Garantía de créditos (NO NEGOCIABLE)

**Una 2ª limpieza NO perjudica los créditos ni los porcentajes de ningún trabajador.** Esto es
consecuencia directa del rework de créditos ([creditos-rework.md](creditos-rework.md)) y debe
preservarse:

- Cada limpieza es una **ejecución independiente**, acreditada por `marcado_por` a quien la hizo.
  Los créditos de la limpieza de la mañana **no se tocan** cuando otra persona limpia de noche.
- **Al re-abrir una pieza que estaba `aprobada`, la nueva limpieza arranca de cero (checklist
  vacío).** La herencia de ítems (`ChecklistService::heredarItemsSiEsRelimpieza`) SOLO se dispara
  cuando el último veredicto fue **`rechazado`**; tras una **aprobación** no hereda. Así el trabajador
  de la noche marca todo él mismo y gana su propio crédito completo — nadie recibe crédito "gratis"
  por lo que hizo otro.
- El **%** (créditos obtenidos / intentados) de una persona solo baja cuando **el auditor le desmarca
  ítems en su propia ejecución** — igual que con una sola limpieza. F no agrega ninguna vía nueva para
  bajar el % de un trabajador.
- **Tasa de rechazo, tiempo, productividad, eficiencia:** por ejecución/auditoría, cada una atribuida
  a quien corresponde.

**Este invariante se blinda con un test** (§6): re-abrir una pieza `aprobada` → la nueva limpieza
arranca vacía y acredita al nuevo trabajador, sin alterar los créditos del primero.

Única consecuencia (no es castigo a nadie): en los totales agregados, una pieza limpiada 2 veces en
el día cuenta como **2 servicios de limpieza**, no como 1 pieza. Es lo esperable; si se quiere, el
resumen puede distinguir "piezas" de "servicios" (fuera de alcance de este MVP).

---

## 3. Inmutabilidad de auditoría (respetada)

La regla NO NEGOCIABLE "una pieza con veredicto NO puede re-auditarse" se respeta: la 2ª limpieza es
una **ejecución nueva** con su **propia auditoría** (la tabla `auditorias` tiene `UNIQUE(ejecucion_id)`).
La auditoría de la 1ª limpieza queda **inmutable**. Es exactamente el mismo mecanismo que ya usa el
flujo rechazo → re-limpieza.

---

## 4. Decisiones de producto

1. **Disparador:** **manual por el coordinador** en el MVP. El coordinador sabe (por Cloudbeds / la
   recepción) que la pieza tiene ocupación de noche y pide la 2ª limpieza. El disparo **automático
   desde las reservas** queda para cuando se implemente el feature **A** (ocupación/reservas).
2. **Franja/ventana:** etiqueta **opcional y simple** por limpieza — `mañana` / `tarde` / `noche` —
   para que el historial y el trabajador sepan de cuál se trata. Nueva columna nullable
   `asignaciones.franja`. No afecta ningún KPI (es solo una etiqueta).
3. **Dónde vive la acción:** en la **vista de Asignaciones**, una sección "Volver a limpiar" que
   lista las piezas ya limpias del día y permite pedir otra limpieza (elegir trabajador + franja).

---

## 5. Cambios técnicos

### 5.1 Datos
- Nueva columna **`asignaciones.franja`** (nullable) — `mañana`/`tarde`/`noche` (o NULL = sin etiqueta).
  En ambos schemas (SQLite + MariaDB) + migración portable idempotente `scripts/migrate-add-franja.php`.

### 5.2 Backend
- **`AsignacionService::asignarManual`**: agregar parámetro opcional `?string $franja = null` (no
  rompe los call-sites existentes: round-robin, reasignar, espacios). Se persiste en la asignación.
- **Surfacear piezas re-limpiables:** en `AsignacionService::vistaConsolidada`, agregar una lista de
  piezas en estado terminal-limpio (`aprobada` / `aprobada_con_observacion`) **limpiadas hoy** y sin
  asignación activa, para la sección "Volver a limpiar". (Excluye espacios: `es_espacio_comun = 0`.)
- **Sin tocar** `heredarItemsSiEsRelimpieza` — ya hace lo correcto (solo hereda tras `rechazado`).
  Se agrega test que lo fija como invariante (§2).
- La cola del trabajador (`colaDelTrabajador`) ya devuelve `a.*`, así que la `franja` viaja sola.

### 5.3 UI
- **Asignaciones:** nueva sección "Volver a limpiar" (piezas limpias del día) con acción "pedir otra
  limpieza" → modal: elegir trabajador (como el de asignar) + selector de franja opcional.
- **Cola del trabajador:** badge de franja (mañana/tarde/noche) junto al número de pieza cuando existe.
- **Historial de la pieza:** las limpiezas del día se ven como ejecuciones separadas (ya es así).

### 5.4 Permisos
- Reusa `asignaciones.asignar_manual` (pedir otra limpieza = asignar). No se agregan permisos nuevos.

---

## 6. Plan de fases

- **Fase 1 — Datos:** `asignaciones.franja` en ambos schemas + migración portable idempotente.
- **Fase 2 — Backend:** parámetro `franja` en `asignarManual`; lista de piezas re-limpiables en
  `vistaConsolidada`; endpoint para "pedir otra limpieza" (reusa el de asignar o uno dedicado).
- **Fase 3 — UI:** sección "Volver a limpiar" + selector de franja + badge en la cola del trabajador.
- **Fase 4 — Tests:**
  - **Invariante de créditos:** re-abrir pieza `aprobada` → nueva ejecución vacía; el nuevo trabajador
    acredita sus ítems; los créditos/% del primero quedan intactos.
  - N limpiezas/día de la misma pieza se cuentan como N ejecuciones/auditorías separadas.
  - `franja` persiste y viaja a la cola del trabajador.
  - Re-abrir tras `rechazado` SÍ hereda (regresión del flujo existente).
- **Fase 5 — Verificación por UI real** (Playwright): pieza limpiada en la mañana → pedir otra
  limpieza (franja noche) a otro trabajador → limpiar → aprobar; verificar créditos repartidos y KPIs.

---

## 7. Fuera de alcance (MVP)

- Disparo automático de la 2ª limpieza desde las reservas de Cloudbeds → feature **A**.
- Pre-planificar mañana + noche en paralelo (dos limpiezas abiertas a la vez) → sería el modelo F-B.
- Distinguir "piezas" vs "servicios" en los totales de reportes.

---

## 8. Referencias cruzadas

- [creditos-rework.md](creditos-rework.md) — modelo de créditos por persona (la garantía de §2 sale de acá)
- [areas-comunes.md](areas-comunes.md) §2 — la primitiva de re-abrir on-demand que F reusa
- [habitaciones.md](habitaciones.md) §3 — máquina de estados
- [database-schema.sql](database-schema.sql) / [database-schema.mariadb.sql](database-schema.mariadb.sql) — columna `asignaciones.franja`
