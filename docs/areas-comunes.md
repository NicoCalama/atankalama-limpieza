# Áreas comunes (espacios)

**Versión:** 1.0 — 2026-07-01
**Estado:** Aprobado — en implementación

Documenta el feature de **áreas comunes / espacios**: lugares que no son habitaciones de huésped
pero necesitan limpieza (piscina, pasillos, patio, bodega, vidrios, etc.), con checklist propio y
servicio **no diario, on-demand**. Nace del relevamiento operativo de Rodrigo Jaque (01/07/2026):

> "Debe tener otro tipo de espacios que no sean habitaciones y que también necesitan servicio de
> limpieza. Ejemplo: la piscina, pasillos, patio, bodega, etc. No tienen los mismos requerimientos
> de las habitaciones, no son diarios, pero debe poderse crear y ver qué tipo de servicio necesita.
> Ejemplo limpiar vidrios de pasillo. Eso se coloca en un checklist para que el que limpia sepa que
> es lo que se espera de su trabajo."

---

## 1. Decisión de modelado

Un espacio se modela como una **"habitación especial"**: una fila más en `habitaciones` con:

- `es_espacio_comun = 1` (columna nueva; las piezas de huésped quedan en `0`)
- `cloudbeds_room_id = NULL` — un espacio **no existe en Cloudbeds**
- `tipo_habitacion_id` → un tipo dedicado **"Área común"** (relleno del FK NOT NULL; el checklist
  real es por-espacio, no por-tipo — ver §3)
- `numero` = nombre corto del espacio ("Piscina", "Pasillo 2º piso")

**Por qué reusar `habitaciones` y no una tabla nueva:** toda la maquinaria existente
(asignaciones → ejecuciones_checklist → ejecuciones_items, la cola del trabajador, la persistencia
tap-a-tap del checklist) cuelga de `habitacion_id`. Reusarla evita un refactor grande y hereda gratis
el flujo de limpieza probado. El costo es un flag y algunos filtros (§4).

---

## 2. Ciclo de vida

> **Vigente desde v6.2 (cambio del jefe, reintegrado en v6.5): los espacios SÍ pasan por
> inspección.** Al completar el checklist quedan en `completada_pendiente_auditoria`, aparecen en
> la bandeja de Inspección como cualquier pieza, y el cierre automático de las 23:55 los aprueba
> (`aprobada_automatica`) si nadie alcanzó a revisarlos. Se quitó la transición
> `en_progreso → aprobada` de `EstadoHabitacionService`. Siguen sin tocar Cloudbeds (no tienen
> `cloudbeds_room_id`: la escritura `clean` se omite). **Decisión de producto pendiente**
> (Nicolás, 22/09/2026): confirmar si se queda así y, si se queda, si las áreas cuentan en la tasa
> de rechazo, la cobertura de la supervisora, el reporte de las 23:50 y la escalera 100/50/0 de la
> ficha de KPIs — hoy `ReportesService::hotelCond` las sigue excluyendo de esos KPIs.
>
> El resto de esta sección describe el diseño ORIGINAL (auto-cierre), que rigió hasta v6.1.

Los espacios **no pasaban por auditoría** (decisión de producto: limpiar la piscina no requiere el
control de una habitación de huésped). Se **auto-cierran** al completar el checklist:

```
aprobada (idle / listo)                     ← estado inicial de un espacio recién creado
   │  coordinador "pide limpieza" (asignación)   → reset terminal→sucia
   ▼
sucia (pendiente de limpiar)
   │  trabajador inicia checklist
   ▼
en_progreso
   │  trabajador completa el checklist (100% obligatorios)
   ▼
aprobada (auto-cierre, sin auditoría)        ← vuelve a idle; listo para el próximo servicio
```

- **Reusa el estado `aprobada`** como terminal "listo/idle" — no se agrega un estado nuevo. En la UI
  de espacios se rotula como "Listo", no como "Aprobada".
- La transición `en_progreso → aprobada` se agrega a la matriz de `EstadoHabitacionService`
  **solo la ejercita el auto-cierre de espacios** (`ChecklistService::completar` bifurca por
  `es_espacio_comun`; las piezas siguen yendo a `completada_pendiente_auditoria`).
- **Nunca tocan Cloudbeds:** la escritura de estado `clean` vive en `AuditoriaService` (al aprobar
  una auditoría). Como los espacios se auto-cierran en `completar` sin pasar por auditoría, no hay
  escritura saliente. Y sin `cloudbeds_room_id`, el sync entrante los ignora naturalmente.

### Primitiva compartida: re-abrir on-demand

"Pedir limpieza" de un espacio ya listo = **generalizar `AsignacionService::asignarManual`** para que
resetee cualquier estado terminal (`aprobada`, `aprobada_con_observacion`, `rechazada`) → `sucia` al
crear una asignación nueva (hoy solo resetea `rechazada`). La matriz ya permite las tres transiciones.
Esta misma primitiva es la que reusará el feature **F (varias limpiezas por día)**.

---

## 3. Checklist propio por espacio

Una piscina y un pasillo no comparten tareas, así que cada espacio tiene **su propio checklist**
(no un template compartido por tipo). Se reusa `checklists_template` + `items_checklist` agregando
`checklists_template.habitacion_id` (nullable):

- Template **de pieza** (piezas de huésped): `habitacion_id IS NULL`, se resuelve por `tipo_habitacion_id`.
- Template **de espacio**: `habitacion_id = <id del espacio>` + `tipo_habitacion_id = <Área común>`.

`ChecklistService` resuelve el template con `templateParaHabitacion($h)`: primero busca por
`habitacion_id`; si no hay, cae al de tipo (`templateParaTipo`, que ahora filtra `habitacion_id IS NULL`
para no confundir con los de espacio). Al crear un espacio se exige ≥1 ítem, de modo que siempre tenga
su template y nunca caiga al checklist genérico de piezas.

Todos los ítems de un espacio son **obligatorios** en el MVP (no hay opcionales).

**Créditos por ítem (julio 2026):** al crear/editar un espacio, cada ítem lleva su
peso en `items_checklist.creditos` (0–100, default 1, mismo clamp que el editor de
checklists por tipo). La API acepta `items: [{descripcion, creditos}]` (o strings
pelados, compat → créditos 1). Como todos los ítems de espacio son obligatorios,
su peso siempre cuenta.

---

## 4. Exclusiones (para no contaminar lo existente)

Los espacios se filtran de los flujos de piezas con `es_espacio_comun = 0`:

| Flujo | Filtro |
|---|---|
| Round-robin automático (`autoAsignar`) | Solo piezas (`= 0`) — los espacios se asignan a mano |
| Vista de asignaciones "sin asignar" (`vistaConsolidada`) | Solo piezas — los espacios viven en su pantalla |
| Bandeja de auditoría (`bandejaPendientes`) | Piezas **y espacios** desde v6.2 (ver §2); una fila por pieza, unida a su ejecución completada más reciente |
| Listado de habitaciones (`HabitacionService::listar`) | Solo piezas por defecto |
| KPIs de tiempos / tasas de piezas (`ReportesService::hotelCond`) | Solo piezas |
| **KPIs de CRÉDITOS** (`kpiCreditos`, `resumenMensual`, `trabajadoras`) | **Piezas + espacios** desde jul-2026 (`hotelCondCreditos`): los créditos de los ítems de áreas comunes suman al total del trabajador. El conteo `habitaciones` del resumen sigue siendo solo piezas |
| Sync + escritura Cloudbeds | Natural: exigen `cloudbeds_room_id` (los espacios lo tienen NULL) |
| Lista de templates (`listarTemplates`) | Solo `habitacion_id IS NULL` (templates de tipo) |

El trabajador **sí** ve el espacio en su cola normal (`colaDelTrabajador`), con un badge distintivo.

---

## 5. RBAC

Permisos nuevos (catálogo `database/seeds/permisos.php`, categoría "Espacios"):

- `espacios.ver` — ver el listado de espacios
- `espacios.crear_editar` — crear/editar espacios y su checklist
- `espacios.pedir_limpieza` — solicitar (asignar) la limpieza de un espacio a un trabajador

Se otorgan a **Supervisora** y **Admin** (Admin ya tiene `__ALL__`). El trabajador no gestiona
espacios; solo los limpia con su permiso existente `habitaciones.marcar_completada`.

**Área en progreso (v6.7):** pedir la limpieza de un área que alguien está limpiando hoy la mueve igual
que reasignarla (el trabajador pierde lo avanzado), así que además exige
`asignaciones.mover_en_progreso` (solo Admin por defecto). Sin ese permiso, el backend responde
403 `HABITACION_EN_PROGRESO` («El área X está en progreso: solo un administrador puede reasignarla
o quitarla»), que la pantalla de Espacios muestra como aviso. Ver `docs/asignacion.md` §4.4.

---

## 6. Cadencia

**MVP: on-demand.** El coordinador pide el servicio (asigna el espacio a un trabajador) cuando
corresponde. No hay automatización.

**Fase 2 (fuera de este MVP):** campo de frecuencia sugerida por espacio + generar la solicitud
automáticamente cada N días.

---

## 7. Fuera de alcance (MVP)

- ~~Auditoría de espacios (se auto-cierran).~~ Activada en v6.2 — ver §2 (decisión definitiva pendiente).
- Cadencia automática (§6).
- Ocupación día/noche y varias limpiezas por día → feature **F**, doc aparte.

---

## 8. Referencias cruzadas

- [database-schema.sql](database-schema.sql) / [database-schema.mariadb.sql](database-schema.mariadb.sql) — columnas `habitaciones.es_espacio_comun`, `checklists_template.habitacion_id`
- [habitaciones.md](habitaciones.md) §3 — máquina de estados (se agrega `en_progreso → aprobada` para el auto-cierre)
- [checklist.md](checklist.md) — resolución de template (ahora por-habitación con fallback por-tipo)
- [roles-permisos.md](roles-permisos.md) — permisos `espacios.*`
- `scripts/migrate-add-espacios.php` — migración portable para BDs existentes
