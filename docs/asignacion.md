# Asignación de habitaciones — Especificación del módulo

Pantalla `/asignaciones` (vista `views/asignaciones.php`). Es donde la supervisora
reparte las habitaciones del día entre los trabajadores con turno. Complementa los
paneles "Ver carga / Reasignar" del home (ver `docs/home-supervisora.md`), pero es
una pantalla propia y más completa.

> Esta pantalla estaba referenciada desde `home-supervisora.md` pero no tenía spec
> propia; se documenta acá desde la **v4** (tablero drag&drop, 10/08/2026).

## 1. Quién la usa y permisos

- La ve quien puede asignar a mano: el endpoint que la alimenta exige
  **`asignaciones.asignar_manual`** (la tiene la Supervisora y el Admin).
- Permisos relacionados: `asignaciones.auto_asignar` (botón Auto) y
  `asignaciones.reordenar_cola_trabajador` (reordenar la cola de un trabajador).

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
- `trabajadores[]` — equipo con turno hoy: `{ usuario:{id,nombre,rut}, progreso:{completadas,total,en_progreso,pendientes,rechazadas}, cola:[{habitacion_id,numero,tipo_nombre,estado,franja}] }`.

Refresco: re-fetch tras cada acción + polling cada 60 s + al volver a la pestaña +
al reconectar. El filtro de hotel se persiste en `localStorage['asignaciones_hotel']`.

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
| Arrastrar de un trabajador a otro | Reasignar (mover) | `POST /api/asignaciones/reasignar` | `{ habitacion_id, usuario_id, fecha, motivo }` |
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
- Mover o quitar una pieza **`en_progreso`** pide **confirmación** (se reinicia y el
  trabajador pierde lo avanzado).
- Si el backend responde **409 `ESTADO_NO_DESASIGNABLE`** (carrera: alguien la
  auditó entremedio), se muestra un mensaje amable y se re-sincroniza.

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
créditos por tipo, franjas y cercanía.*

## 7. Vista Guiada (botón «?»)

El catálogo de recorridos de esta pantalla vive en `src/Support/Tours.php`
(clave `'asignaciones'`), con 4 recorridos: **Repartir**, **Mover** (reasignar /
quitar / reordenar), **Auto-asignar** y **Cambiar de hotel**. Las 4 anclas
(`asig.hotel`, `asig.auto`, `asig.sin-asignar`, `asig.equipo`) viven en el markup del
**tablero**. `ToursAnchorTest` verifica que existan y no queden huérfanas.

> En **modo clásico** el «?» degrada con el fallback honesto del motor ("este paso no
> está en pantalla ahora") para los recorridos que apuntan a zonas del tablero — es a
> propósito: la ayuda enseña la forma nueva (tablero), que es el default.

## 8. Archivos

- `views/asignaciones.php` — la vista (ambos modos) + el componente Alpine `asignacionesApp()`.
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
