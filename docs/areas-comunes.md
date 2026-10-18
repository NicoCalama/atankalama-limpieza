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
  de espacios se rotulaba como "Listo", no como "Aprobada" (desde la v6.18 la tarjeta usa los textos
  de las habitaciones; ver «Tarjeta y cierre del día»).
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

### Preasignar un área para otro día (v6.17.1)

Pedir limpieza para una fecha futura no ensucia el área en ese momento: `asignarManual` solo resetea
el estado terminal si la asignación es para hoy. Cuando llega el día, la primera carga de una cola o
del tablero (`AsignacionService::reconciliarPreasignaciones`) pasa a `sucia` el área preasignada que
sigue «Lista» (aprobada, con observación o automática) y sin limpieza ligada a esa asignación:
**preasignar un área es un pedido firme de limpieza para ese día** (decisión de Nicolás, 07/10/2026).
Las habitaciones de huésped, en cambio, se retiran de la cola si amanecen aprobadas: no necesitaron la
limpieza planificada. Eso se decide recién **desde las 06:00** (v7.5.1,
`AsignacionService::HORA_RETIRO_PREASIGNACIONES`): de madrugada las piezas limpiadas el día anterior
siguen aprobadas hasta que la sincronización con Cloudbeds las pasa a sucia (00:30–01:30), y un Inicio
de supervisora abierto, que se recarga solo cada minuto, retiraba a medianoche todo lo planificado
(132 de 155 preasignaciones el 11/10/2026). Las áreas pasan a `sucia` a cualquier hora.

Antes las áreas pasaban por la misma regla que las habitaciones: se cancelaban «porque no necesitaron
limpieza» o, si las había aprobado el sistema, quedaban en la cola sin poder empezarse. En producción,
de 31 preasignaciones de áreas entre el 07/09 y el 06/10/2026, solo una se limpió.

### Tarjeta y cierre del día (v6.18)

Decisiones de Nicolás del 07/10/2026. Las asignaciones son por día, así que «no asignada» **no es un
estado nuevo**: es un área sin asignación activa para hoy. El estado guardado no cambia.

**Tarjeta** (`views/espacios.php`): la misma ficha que Habitaciones (borde del hotel, nombre grande, a
quién está asignada hoy) con la franja de estado al pie, con los textos y colores de las habitaciones
(Ajustes → Colores). Qué franja muestra lo decide `EspacioService::franjaVisible()`:

| Estado guardado | Con asignación hoy | Sin asignación hoy |
|---|---|---|
| `sucia` | PENDIENTE | sin franja |
| `en_progreso` | EN PROGRESO | EN PROGRESO |
| `completada_pendiente_auditoria` | POR INSPECCIONAR | POR INSPECCIONAR |
| `aprobada`, `aprobada_con_observacion`, `aprobada_automatica` | APROBADA / C/OBS. / AUTO. | sin franja |
| `rechazada` | RECHAZADA | RECHAZADA (hasta que alguien vuelva a pedir la limpieza) |

«C/obs.» y «auto.» solo para quien tiene `habitaciones.ver_todas`, como en Habitaciones. El filtro de la
pantalla suma «Sin asignar» y la tabla y el Excel, la columna «Asignada hoy». Al listar se ponen al día
las preasignaciones de hoy (`AsignacionService::ponerAlDiaPreasignaciones`), para que un área preasignada
no muestre la aprobación de su limpieza anterior si nadie abrió todavía una cola ni el tablero.

**Cierre del día** (`scripts/aprobar-pendientes-cierre-dia.php`, 23:55, **solo en la pasada de la
noche**: desde las 20:00; la de las 15:50 solo aprueba):

- **Aprobada o pendiente:** nada que hacer. Desde las 00:00 no tiene asignación para el día nuevo y la
  tarjeta queda sin franja.
- **Por inspeccionar:** el mismo cron la aprueba (`aprobada_automatica`) y desde las 00:00 queda sin franja.
- **En progreso (v6.19):** la limpieza se termina y el área queda aprobada, **sin créditos para quien no
  apretó «terminar»** (`CierreDiaService::cerrarSinTerminar`, igual que las habitaciones: ver
  [checklist.md](checklist.md) §3.7). Vale también para las que venían colgadas de días anteriores.
  Se avisa con una campanita (tipo `cerradas_sin_terminar`).
- **Rechazada:** sigue rechazada (con su franja) y se avisa con una campanita que no se volvió a limpiar
  (`CierreDiaService::avisarAreasRechazadas`). Se repite cada noche mientras siga así (una por persona y por día).

Las campanitas (bandeja + push a quien tenga turno activo) les llegan a las personas activas con
`espacios.pedir_limpieza` (Supervisora y Admin por defecto), sin el usuario «Sistema». Una sola por noche
y por tipo, con todas las piezas.

**Hasta la v6.18** el área en progreso se arrastraba cada noche a la cola de la misma persona
(`asignacion.arrastrada_cierre_dia` en el audit log), sin límite y sin mirar si esa persona tenía turno; y
la que venía de antes de hoy quedaba en progreso sin nadie. En producción, 17 de 626 limpiezas de áreas
entre el 14/09 y el 08/10/2026 quedaron así, y en 16 la persona había marcado todo en menos de 20
segundos: no apretó «terminar» porque todavía no se cumplían los 3 minutos mínimos.

**Menú ⋮ (v6.19, pedido de la supervisora):** la tarjeta y la tabla tienen «Marcar limpia» y «Marcar
sucia», los mismos de Habitaciones, con los mismos endpoints y el mismo permiso
(`habitaciones.marcar_limpia_manual`). «Marcar limpia» deja el área por inspeccionar y quien la estaba
limpiando conserva lo que marcó (`ChecklistService::marcarLimpiaManual`). «Marcar sucia» la deja
pendiente para quien la tenga hoy; sin nadie asignado hoy no tiene franja y hay que pedir su limpieza.
Marcarla sucia también destraba un área en progreso: ya no está en progreso, así que la supervisora puede
pedírsela a otra persona sin el permiso de Admin. Para mover un área **en progreso** a otra persona se
sigue necesitando `asignaciones.mover_en_progreso` (solo Admin por defecto, ver §5).

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

**Sin tiempo mínimo desde la v7.10 (decisión de Nicolás del 18/10/2026):** ninguna limpieza, de
habitación o de área, tiene ya un mínimo para terminar. Se sacaron el selector de «Editar», el texto de
la tarjeta y la columna de la tabla; `GET /api/espacios` ya no trae `minimo_terminar_min` y crear/editar
ignoran el campo si una pantalla vieja lo manda. La columna `habitaciones.minimo_terminar_min` queda en
la BD sin uso (sin SQL en el deploy). Lo que sigue es la historia de la v7.6.

**Tiempo mínimo para terminar (v7.6, decisión de Nicolás del 11/10/2026; RETIRADO en la v7.10):** toda limpieza tenía un
mínimo desde que se empieza antes de poder apretar «terminar» (antifraude; 3 min, `ChecklistService`,
`CHECKLIST_DELAY_MINIMO_SEGUNDOS`). En las áreas ese mínimo hacía esperar en las chicas: 212 de 609
áreas terminadas cerraron justo entre 3 y 4 min, y 16 quedaron con todo marcado y sin terminar (ver §2,
«Tarjeta y cierre del día»). Ahora **cada área tiene el suyo, en minutos**, en
`habitaciones.minimo_terminar_min`:

- Se elige al crear o con «Editar» en la tarjeta, de una lista: sin mínimo (0), 1, 2, 3, 5 o 10 min
  (`EspacioService::MINIMOS_TERMINAR_MIN`; otro valor → 400 `MINIMO_INVALIDO`). Mismo permiso que editar
  el área (`espacios.crear_editar`).
- `NULL` = el mínimo general (3 min): así quedan las áreas que existían antes de la v7.6 hasta que alguien
  las edite. Las habitaciones usan siempre el general, aunque la columna tuviera un valor.
- La tarjeta lo muestra («Mínimo 2 min para terminar» / «Sin tiempo mínimo») y la tabla en la columna
  «Mín. para terminar». `GET /api/espacios` y `GET /api/espacios/{id}` traen el que rige
  (`minimo_terminar_min`, entero, nunca `null`).
- `POST /api/espacios` y `PUT /api/espacios/{id}` aceptan `minimo_terminar_min`. Si no viene (una pantalla
  abierta desde antes de la v7.6), crear deja `NULL` y editar no lo toca.
- Al limpiar, la cuenta regresiva del botón y el bloqueo de `completar()` usan ese valor; el mensaje dice
  los minutos («Debes esperar al menos 2 min desde que empezaste»). Cambiarlo con un área en progreso rige
  desde la próxima vez que se abra su checklist.
- Lo marcado al pasar un área en progreso a otra persona sigue partiendo de cero (decidido el mismo día).

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

**Cambiar de persona (v7.4.1, pedido de gerencia: motivo obligatorio y sin texto):** si ese día el área
ya la tiene otra persona y esa limpieza no está hecha (pendiente, en progreso o rechazada; en otra fecha,
cualquier preasignación), pedírsela a otra es cambiarla de persona. La ventana «Pedir limpieza» muestra
primero «¿Por qué la cambias de persona?» con la lista «reasignar» (la misma de Asignaciones, Ajustes →
Checklists y listas) y quien la tiene hoy queda marcado «La tiene hoy». El backend exige `opcion_id`
(400 `OPCION_REQUERIDA` / `OPCION_INVALIDA`) y lo registra como `asignacion.reasignar` con el motivo.
Para otra fecha la pantalla no sabe quién la tiene: la lista aparece cuando el backend responde
`OPCION_REQUERIDA`. Sin motivo: pedírsela de nuevo a la misma persona, o pedir otra limpieza de un
área que ya se limpió (por inspeccionar o aprobada). `GET /api/espacios` trae `asignado_a_id` y
`cambio_pide_motivo` para eso.

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
