# Asignación de habitaciones — Especificación del módulo

Pantalla `/asignaciones` (vista `views/asignaciones.php`). Es donde la supervisora
reparte las habitaciones del día entre el personal de aseo con turno y la gente de
otras áreas que limpia (pestañas «Aseo» y «Otros», §3.1). Complementa los
paneles "Ver carga / Reasignar" del home (ver `docs/home-supervisora.md`), pero es
una pantalla propia y más completa.

> Esta pantalla estaba referenciada desde `home-supervisora.md` pero no tenía spec
> propia; se documenta acá desde la **v4** (tablero drag&drop, 10/08/2026).

## 1. Quién la usa y permisos

- La ve quien puede asignar a mano: el endpoint que la alimenta exige
  **`asignaciones.asignar_manual`** (la tiene la Supervisora y el Admin).
- Permisos relacionados: `asignaciones.auto_asignar` (botón Auto) y
  `asignaciones.reordenar_cola_trabajador` (reordenar la cola de un trabajador).
- **Fuera de esta pantalla (v7):** «Re-limpiar» de la inspección pre-entrega
  (`docs/revision-entrega.md` §3b) asigna desde la tarjeta de Habitaciones por el
  mismo camino que reasignar (`AsignacionService::reasignar`, con una frase extra en
  el aviso a la trabajadora) y, con prioridad, deja la pieza primera en su cola
  (`subirAlInicioDeCola`, exige `asignaciones.reordenar_cola_trabajador`). Esa
  asignación no cuenta como pieza asignada en los KPIs.

## 2. Dos modos (elegibles con un interruptor)

Desde la v4 la pantalla tiene un interruptor **«Tablero / Clásico»** arriba del
contenido. La elección se recuerda **por dispositivo** en
`localStorage['asignaciones_modo']` (default `tablero`), igual que el tema y el
filtro de hotel. Los dos modos comparten el header (volver, título, **selector de
hotel**, refrescar, tema) y consumen los mismos datos.

- **Tablero (default):** arrastrar-y-soltar. Es la forma principal.
- **Clásico:** el flujo previo (chips + modal). Es el **respaldo**, sobre todo en
  teléfono si el arrastre incomoda. Queda intacto; no se quitó.

## 3. Datos que consume

Un solo endpoint alimenta la pantalla:

```
GET /api/asignaciones/vista?fecha=<hoy>&hotel=<1_sur|inn>   (hotel se omite si es "ambos")
```

Respuesta (`data`):
- `sin_asignar[]` — piezas sucias sin asignar: `{ id, numero, tipo_nombre, hotel_codigo, hotel_nombre }`.
- `re_limpiar[]` — piezas ya limpias hoy que necesitan otra pasada (ocupación día/noche); misma forma. **Al asignarlas se re-abren y la limpieza arranca de cero** (el backend resetea el estado terminal a `sucia`).
- `trabajadores[]` — a quién se le puede asignar ese día: `{ usuario:{id,nombre,rut,hotel_default,grupo,con_turno}, progreso:{completadas,total,en_progreso,pendientes,rechazadas}, cola:[{habitacion_id,numero,tipo_nombre,estado,franja}] }`.
- `reservas` — solo en un día futuro (v7.15, §3.2): `{ hoteles_sin_dato: [nombres] }`; `null` hoy y en días pasados. Cada pieza de `sin_asignar` y de las colas trae además `tipo_dia`.

Refresco: re-fetch tras cada acción + polling cada 60 s + al volver a la pestaña +
al reconectar. El filtro de hotel se persiste en `localStorage['asignaciones_hotel']`.

### 3.2 Planificar un día futuro: el pool según las reservas (v7.15, 06/11/2026)

Pedido de Nicolás: al planificar otro día, el pool ofrecía **todo el inventario** sin preasignar (un
domingo sin planificar mostraba las 148 piezas), también las que ese día no iban a tener a nadie.
Ahora `AsignacionService::vistaConsolidada` le pregunta a Cloudbeds las reservas de ese día
(`OcupacionFuturaService`, misma consulta `getReservations` que usa el sync para contar huéspedes) y
deja solo lo que ese día necesita limpieza. Cada pieza lleva **una sola** etiqueta (`tipo_dia`):

| `tipo_dia` | Tarjeta | Qué pasa ese día | ¿Sale en el pool? |
|---|---|---|---|
| `se_va` | «Se va» (naranjo) | el huésped se va | sí |
| `cambio` | «Cambio» (morado) | se va uno y llega otro | sí |
| `sabanas` | «Sábanas» (ámbar) | sigue, y le toca cambio de sábanas (días N, 2N… desde su llegada, N del hotel) | sí |
| `sigue` | *(sin etiqueta)* | sigue, sin sábanas: aseo liviano | sí |
| — | — | solo llega (la pieza ya se limpió cuando salió el anterior) | **no** |
| — | — | sin reserva | **no** |

Decisiones de Nicolás (06/11/2026):
- **Una sola etiqueta por tarjeta.** «Se va» y «Cambio» siempre llevan sábanas, así que no se repite
  «Sábanas»: con etiqueta = lleva sábanas, sin etiqueta = aseo liviano.
- **Pensado para el celular:** la etiqueta es texto (no un punto con `title`, que sin mouse no se lee),
  y arriba del pool hay una fila de botones de 44 px con el conteo que también filtra: «Todas · Se va ·
  Cambio · Sábanas · Sigue» (`views/componentes/filtro-dia-pool.php`). El filtro no se recuerda: vuelve a
  «Todas» al cambiar de día o de hotel, y suelta las piezas elegidas que quedan ocultas.
- **Las tarjetas del día futuro van en blanco:** el color era el estado de hoy, que no dice nada de ese día.
- **Las que solo reciben huésped no salen.**
- **La cola de cada persona ese día lleva la misma etiqueta** en vez del estado de hoy (una sola burbuja;
  «Sigue» sin nada), para ver qué le tocó a cada una y repartir parejo. Una pieza preasignada que ese día
  no necesita aseo (sin reserva o solo llega) dice **«Sin limpieza»** (gris): sin etiqueta se
  confundiría con «Sigue». Sin dato de Cloudbeds queda el estado de hoy, como antes.
  (`AsignacionService::anotarColaFutura`; pool y colas comparten la consulta de cada hotel.)

Detalles:
- Se ignoran lo cancelado, los no-show, lo que ya hizo check-out y las reservas que todavía no tienen
  pieza en Cloudbeds (no se sabe cuál les va a tocar: la pieza aparece cuando recepción la asigna).
- **Cache de 10 minutos** por hotel y fecha en `storage/cache/ocupacion/` (se crea sola, fuera de git;
  los archivos de más de un día se borran solos): el tablero se recarga después de cada arrastre. Una
  reserva nueva en Cloudbeds aparece en el pool a lo más 10 minutos después.
- **Si Cloudbeds no contesta** (o el hotel no tiene `cloudbeds_property_id`), ese hotel muestra todo,
  como antes, sin etiquetas, y la pantalla avisa «No pudimos consultar las reservas de Cloudbeds de …».
  La consulta va **sin reintentos** (la pantalla espera; 10 s de timeout) y el fallo no se guarda: el
  botón de refrescar vuelve a preguntar.
- Las piezas que no están enlazadas a Cloudbeds (la PRUEBA) salen siempre, sin etiqueta.
- **No cambian:** el día de hoy (pool por estado sucia/rechazada, puntos de ocupación), los días
  pasados (todo el inventario) ni las preasignaciones ya hechas (si ese día la pieza no necesitó
  limpieza, la reconciliación de las 06:00 la retira, v7.5.1; mientras, sale como «Sin limpieza»).

### 3.1 Pestañas «Aseo» y «Otros» (v7.14, 06/11/2026)

Pedido de Nicolás: la supervisora solo veía al personal de aseo, porque la lista salía de quienes tienen
**turno cargado** ese día y solo el aseo tiene turno (Excel de Breik). Ahora la lista la arma
`PersonalAsignableService::listar($fecha, $hotel)`, la misma que usa «Pedir limpieza» de Áreas comunes
(`GET /api/espacios`), y cada persona trae su `grupo`. La regla va por permiso, nunca por nombre de rol:

- **Aseo** (`grupo: "aseo"`): sin la marca `asignaciones.excluir_auto` (personal de aseo) y **con turno**
  ese día. Sin turno no sale: el turno es lo que dice que trabaja ese día.
- **Otros** (`grupo: "otros"`): con la marca `asignaciones.excluir_auto` (no es de aseo) que además
  **puede limpiar** (`habitaciones.ver_asignadas_propias`, o sea ve su cola en el celular), **tenga o no
  turno**. No sale el Admin (permiso llave `permisos.asignar_a_rol`): tiene todos los permisos y no limpia.
  En la práctica es el rol Apoyo; si alguien de Recepción, Mantención o una Supervisora tiene que aparecer,
  el Admin le agrega el rol Apoyo en Usuarios (sin código).
  - **v7.15.1 (06/11/2026):** hasta la v7.15 se excluía a quien reparte (`asignaciones.asignar_manual`),
    y eso dejaba fuera a la Supervisora aunque tuviera el rol Apoyo: los permisos se suman entre roles,
    así que seguía teniendo `asignar_manual`. Ahora solo se excluye al Admin. La Supervisora sin Apoyo
    sigue sin salir, porque no tiene `habitaciones.ver_asignadas_propias` (decisión de Nicolás).
- **Quien ya tiene piezas asignadas ese día sale siempre**, en su grupo, aunque no calce arriba (por
  ejemplo, el Admin con la habitación PRUEBA): ninguna asignación queda escondida del tablero.
- Mismo filtro de hotel de siempre (`hotel_default` igual al elegido o `ambos`).
- Cambio respecto de antes: una Supervisora (sin el rol Apoyo) o un Admin con turno cargado y sin piezas
  ya no aparecen como destino.
- El **auto-asignar no cambia**: solo aseo con turno (§6).
- Las alertas predictivas no corren para alguien sin turno (no tiene hora de fin).

En pantalla: pestañas «Aseo (n) · Otros (n)» bajo el buscador, compartidas por Tablero y Clásico (se
recuerda en `localStorage["asignaciones_grupo"]`, también la usa «Pedir limpieza»). Los modales de asignar
y reasignar listan a todos, con una etiqueta «Otros» en los que no son de aseo.

### 3.3 Registro de un día pasado (v7.20, reunión del 08/11/2026)

Un día pasado no muestra el tablero: `GET /api/asignaciones/vista` trae `registro[]`, con `sin_asignar`, `re_limpiar` y
`trabajadores` vacíos. Sale de `ReportesService::registroDelDia()` y queda **solo para ver**: asignar, repartir,
reasignar, quitar y reordenar con una fecha pasada responden 409 `DIA_CERRADO`.

Cada persona es una ficha que se abre al tocarla:
- **Limpiezas:** cada una con su pieza, Mañana/Tarde, hora de término y estado de **esa** limpieza, según su veredicto (Aprobada,
  Aprobada c/obs., Rechazada, Aprobada auto., Sin inspección o Sin inspeccionar). Lee `ejecuciones_checklist` por persona sin filtrar
  `asignaciones.activa`: reasignar en la tarde apaga la tarjeta de la mañana, pero su limpieza sigue en la ficha de quien la hizo.
  Si dos personas limpiaron la misma pieza, cada una ve la suya.
- **Le quedaron sin limpiar:** tarjetas activas ese día sin una limpieza terminada por esa persona.
- **Totales:** `piezas` calza con «Limpiadas» de Reportes (una por pieza · franja · vuelta, solo de huésped); `areas` va aparte. Las
  re-limpiezas de la inspección pre-entrega se listan, pero no suman.
- **Mañana o Tarde:** la franja si la eligieron; si no, la hora de inicio con corte fijo a las 16:00, para que los días ya pasados no
  cambien de rótulo si se mueve la hora de la tarde en Ajustes.

No entran las piezas que Cloudbeds dio por limpias sin que nadie las limpiara en la app, ni el atajo «Marcar limpia»: no son una
limpieza de esa persona. Es la misma regla de Reportes. El tablero de hoy no cambia.

### 3.4 Pestañas «Día» y «Noche» (v7.25, reunión del 08/11/2026)

Plan aprobado el 09/11; detalles con Nicolás el 10/11/2026. Pestañas **Día (n) · Noche (n)** arriba del buscador, compartidas
por Tablero y Clásico (se recuerda en `localStorage["asignaciones_ventana"]`). El número es lo que queda por repartir en esa
ventana. Depende de la chapa de la v7.23 ([nocheros.md](nocheros.md)) y de la hora de la tarde de Ajustes.

**Qué cambia entre pestañas: el pool y las colas.**
- **Pool «Sin asignar»:** Día trae las piezas Día y Día/Noche. Noche trae las Noche y, **hoy**, también las Día/Noche. Para un
  **día futuro**, Noche trae **solo las Noche**: con una tarjeta por pieza y día, preasignar la de noche de una Día/Noche dejaría
  sin nadie su limpieza de la mañana (las dos tarjetas llegan con la v7.26). El pool viaja entero desde el backend, cada pieza con
  su `chapa`, y la pantalla lo reparte.
- **Colas:** cada persona muestra solo sus tarjetas de esa ventana. Una tarjeta es de **noche** si su `franja` es `noche`; sin
  franja, `mañana` o `tarde` es de **día**. La línea «N/M completadas · P pendientes» y el «menor carga» de los modales usan
  `progreso_ventana[dia|noche]`. Reordenar (arrastre o ▲▼) mueve solo entre las tarjetas de la pestaña: las de la otra ventana
  quedan en su lugar en la cola completa.
- Lo que se asigna desde **Noche** queda con `franja = 'noche'` (en el modal no se elige franja: dice «Quedan como limpieza de
  noche»). En Día la franja opcional es mañana o tarde.
- **«Volver a limpiar»** sale en las dos pestañas; desde Noche, la tarjeta nueva es de noche.

**Antes de la hora de la tarde (hoy):** la pestaña Noche muestra «Disponibles desde las HH:MM» y cuántas piezas Noche esperan,
en vez del pool. Las piezas Noche no se pueden asignar: `asignarManual` y `asignarMultiple` responden 409 `NOCHE_ANTES_DE_HORA`
(el lote completo, sin dejar nada a medias). Solo frena la asignación **nueva**: una Noche que ya está en una cola se queda y se
puede mover entre personas. Las colas de noche se ven igual. Un día futuro se planifica a cualquier hora.

**Día/Noche barridas (hoy, desde la hora):** el barrido devuelve a sucia la Día/Noche ya aprobada, pero su tarjeta sigue siendo
la de la mañana. Salen aparte en el pool de Noche, «Día/Noche de la mañana», con el nombre de quien la tiene
(`dia_noche_barridas`: sucias, barridas hoy, con tarjeta activa que no es de noche, sin bloqueo). Al asignarlas, `asignarManual`
apaga la tarjeta de la mañana y crea la de noche. **La limpieza de la mañana no se pierde:** el Registro (§3.3) y Reportes leen
las limpiezas, no las tarjetas, y son dos ciclos distintos (franja distinta). A quien la limpió en la mañana no le queda como
«sin limpiar».

**«Auto»** sale solo en la pestaña Día y nunca reparte piezas Noche, ni antes ni después de la hora: se asignan a mano desde Noche
(§6). El plan las sacaba solo antes de la hora; se dejó así para que «Auto» no reparta piezas que no se ven en la pestaña Día.

**Preasignaciones:** una tarjeta de noche no se retira en la mañana aunque su pieza siga aprobada (`reconciliarPreasignaciones`):
recién desde la hora de la tarde se sabe si de verdad no la necesitó.

`GET /api/asignaciones/vista` suma `ventana_noche: {hora, disponible}`, `dia_noche_barridas[]` y, en cada persona,
`progreso_ventana: {dia, noche}`. Reloj inyectable en `AsignacionService` (`reloj:`) para los tests; `EscenarioCicloLimpieza`
fija las 17:00.

## 4. Modo Tablero (arrastrar-y-soltar)

### 4.1 Layout

Dos columnas (`grid md:grid-cols-[1fr_20rem] lg:[1fr_24rem]`), responsive:
- **Izquierda = Equipo** (`data-tour="asig.equipo"`): una tarjeta por trabajador con
  su avatar, progreso y su **cola**. Cada tarjeta es una **zona de drop**
  (`data-drop="worker"`).
- **Derecha = Sin asignar** (`data-tour="asig.sin-asignar"`): el pool de piezas
  (sin asignar + "Volver a limpiar") + el botón **Auto** (`data-tour="asig.auto"`).
  Es una **zona de drop** (`data-drop="pool"`). `md:sticky` para no perderla al
  scrollear.
- En **teléfono** las columnas se apilan (pool arriba, equipo abajo).

Cada tarjeta de habitación muestra **número + tipo + badge de estado**.

### 4.2 Gestos → acciones (reutiliza endpoints existentes)

| Gesto | Acción | Endpoint | Payload |
|---|---|---|---|
| Arrastrar 1..N del pool a un trabajador | Asignar | `POST /api/asignaciones` (lote) | `{ habitacion_ids:[…], usuario_id, fecha }` (**sin franja**) |
| Arrastrar de un trabajador a otro | Reasignar (mover) | `POST /api/asignaciones/reasignar` | `{ habitacion_id, usuario_id, fecha, opcion_id }` — v7.3: al soltar se abre «¿Por qué la cambias?» con la lista «reasignar»; la pieza se mueve al confirmar y «Cancelar» la deja donde estaba |
| (Áreas comunes, v7.4.1) «Pedir limpieza» a otra persona de un área que alguien tiene sin limpiar | Reasignar | `POST /api/espacios/{id}/pedir-limpieza` | `{ usuario_id, fecha, opcion_id }` — mismo motivo de la lista «reasignar»; ver `docs/areas-comunes.md` |
| Arrastrar de vuelta al pool, o tocar la **X** de la tarjeta | Quitar (desasignar) | `POST /api/asignaciones/desasignar` | `{ habitacion_id, fecha }` |
| Arrastrar dentro del mismo trabajador | Reordenar la cola | `PUT /api/asignaciones/orden` | `{ usuario_id, fecha, orden:[habitacion_id…] }` |

- **Multi-selección:** tocar varias piezas del pool las marca (azul); al arrastrar
  una del grupo se mueven todas juntas.
- **Optimista + reconciliación:** el gesto muta la vista al instante y dispara el
  endpoint; al terminar, `cargar()` reconcilia con el servidor (y revierte si falló).
- **Ninguno de estos endpoints escribe a Cloudbeds** (solo lo hace "Aprobar" de
  auditoría). Son seguros de probar en dev.

### 4.3 Motor de arrastre

`public/assets/js/drag-asignaciones.js` — mixin sin librerías, con **pointer events
unificados (mouse + dedo)**. Se carga solo en esta pantalla (`<script src>?v=filemtime`).

- **Mouse:** el arrastre arranca al superar ~5px; menos que eso = tap → selecciona.
- **Táctil:** **long-press ~200ms** con el dedo quieto = arrastre; si el dedo se
  mueve antes, es scroll (las tarjetas tienen `touch-action:pan-y`, la lista
  scrollea normal). Durante el arrastre, `body.arrastrando { touch-action:none }`.
- El **fantasma** que sigue al puntero lleva `pointer-events:none` (imprescindible
  para que `elementFromPoint` detecte la zona de abajo). Hay una guarda contra el
  "click fantasma" que los navegadores táctiles sintetizan tras un toque.

### 4.4 Reglas de negocio en la UI

- Una pieza **ya auditada** (`completada_pendiente_auditoria` / `aprobada` /
  `aprobada_con_observacion`) **no se arrastra ni tiene X** (no es reasignable).
- **Pieza `en_progreso` (desde v6.7, decisión de jefatura 22/09/2026):** reasignarla o
  quitarla le borra al trabajador lo avanzado, así que solo puede hacerlo quien tiene
  **`asignaciones.mover_en_progreso`** (por defecto solo Admin; se cambia en Ajustes → Roles
  y Permisos).
  - **Sin el permiso** (la supervisora): la pieza se ve **bloqueada**. No se arrastra y no
    tiene X, «Reasignar», «Desasignar» ni flechas ▲▼. En su lugar lleva un candado, y debajo
    de la cola del trabajador aparece «En progreso: solo un administrador puede moverla». Lo
    mismo en el modal «Reasignar carga» del Inicio de la supervisora (la ficha sale
    deshabilitada, con candado).
  - **Con el permiso:** mover o quitar la pieza pide **confirmación** en las tres pantallas:
    Tablero, Clásico y el Inicio.
  - **El backend lo exige igual**, sin depender de la pantalla. `POST /api/asignaciones`,
    `/reasignar`, `/desasignar`, «Pedir limpieza» de Espacios y el copilot responden
    **403 `HABITACION_EN_PROGRESO`**. La regla vive en
    `AsignacionService::exigirPuedeMoverEnProgreso`, con default restrictivo: un llamador que
    no pasa el permiso no la mueve. Solo aplica a la asignación de **hoy**: planificar la
    pieza para otra fecha no toca el aseo en curso. En un lote con una pieza en progreso no
    se asigna ninguna.
- Si el backend responde **409 `ESTADO_NO_DESASIGNABLE`** (carrera: alguien la
  auditó entremedio), se muestra un mensaje amable y se re-sincroniza.
- **Estas reglas valen para HOY, no para la planificación (v7.14, 06/11/2026).** El estado que
  muestra cada pieza es el de hoy. Antes, una pieza limpiada o en progreso hoy dejaba trabada su
  preasignación de un día futuro: no se podía arrastrar, quitar ni reasignar, ni siquiera el
  Admin. Ahora:
  - En un **día futuro** toda preasignación se mueve y se quita. No hay candado ni aviso de
    «se reinicia». Los contadores de ese día cuentan todo como pendiente. Desde la v7.15 el badge
    de la cola es la etiqueta de ese día (§3.2); el estado de hoy queda solo si no hay dato de
    Cloudbeds.
  - `AsignacionService::desasignar` con una fecha futura no exige estado y no reinicia nada: la
    limpieza en curso de hoy y Cloudbeds quedan como estaban. `reasignar` ya lo permitía.
  - Hoy y los días pasados siguen igual.

## 5. Modo Clásico (respaldo)

El flujo previo, intacto: se **tocan chips** para seleccionar (multi-select), aparece
una **barra flotante** inferior con el conteo, y "Asignar a…" abre un **modal** que
lista trabajadores ordenados por menor carga y permite elegir **franja** (opcional).
Reasignar y desasignar individuales son **modales** por pieza. Usa los mismos
endpoints que el tablero.

## 6. Auto-asignar (round-robin)

Botón **Auto** (gate `asignaciones.auto_asignar`) → `POST /api/asignaciones/auto`
`{ hotel, fecha }` → `AsignacionService::autoAsignar`. Reparte las piezas sucias sin
asignar entre los trabajadores con turno con un **round-robin simple** (`$i % $n`):
parejo por cantidad, sin mirar carga/tiempo/tipo. Después se afina a mano (arrastrando
en el tablero). *Mejora pendiente de este reparto: repartir por carga/tiempo real,
créditos por tipo, franjas y cercanía.* Desde la v7.25 el botón está solo en la pestaña
Día y las piezas con chapa Noche vigente no entran al reparto (§3.4).

## 7. Vista Guiada (botón «?»)

El catálogo de recorridos de esta pantalla vive en `src/Support/Tours.php`
(clave `'asignaciones'`), con 4 recorridos: **Repartir**, **Mover** (reasignar /
quitar / reordenar), **Auto-asignar** y **Cambiar de hotel**. Las anclas
(`asig.hotel`, `asig.auto`, `asig.sin-asignar`, `asig.equipo`; y desde la v7.25
`asig.ventana`, en las pestañas Día | Noche, que valen para los dos modos) viven en el
markup. `ToursAnchorTest` verifica que existan y no queden huérfanas.

> En **modo clásico** el «?» degrada con el fallback honesto del motor ("este paso no
> está en pantalla ahora") para los recorridos que apuntan a zonas del tablero — es a
> propósito: la ayuda enseña la forma nueva (tablero), que es el default.

## 8. Archivos

- `views/asignaciones.php` — la vista (ambos modos) + el componente Alpine `asignacionesApp()`.
- `views/componentes/filtro-dia-pool.php` — filtro del pool de un día futuro (v7.15), en los dos modos.
- `src/Services/OcupacionFuturaService.php` — reservas de Cloudbeds de un día futuro, con su cache (v7.15).
- `public/assets/js/drag-asignaciones.js` — motor de arrastre (pointer events).
- `public/assets/css/custom.css` — estilos del arrastre (`.arrastrando`, fantasma, `.drop-activa`, línea de inserción).
- `src/Services/AsignacionService.php` + `src/Controllers/AsignacionesController.php` — backend (sin cambios en v4; se reutilizó).
- `src/Support/Tours.php` — recorridos de la Vista Guiada.

## 9. Verificación en dev

- `php -S localhost:8000 -t public/`; login como la supervisora **Sofía**
  (`15000001-7` / `Test1234!`); `/asignaciones`.
- Necesita **trabajadores con turno hoy** para poblar "Equipo del día" (sembrar en
  `usuarios_turnos` con la fecha de la app = `date('Y-m-d')` en `America/Santiago`) y
  **revertir** lo sembrado al terminar.
- Probar en Tablero: asignar (1 y en lote), reasignar, reordenar, desasignar (arrastre
  y X); el interruptor a Clásico; en táctil, que el scroll no dispare arrastre.
- **OJO:** asignar/reasignar/desasignar **no** escriben a Cloudbeds (seguros), pero
  asignar una pieza **terminal** (rechazada/aprobada, las de "Volver a limpiar") la
  **resetea a `sucia`** — revertir si se tocó una real. NO tocar Aprobar/Rechazar/
  Habitación-terminada en dev (esos sí escriben a Cloudbeds).
