# Import del inventario real de habitaciones desde Cloudbeds

> **Detección automática por alerta (21/07/2026).** Ya no hace falta acordarse de correr
> el import a mano ni tener consola en el hosting. El cron de sync (`scripts/sync-cloudbeds.php`)
> dispara un **chequeo throttleado a 1 vez por día** (`InventarioCheckService`) que corre el
> import en **dry-run** (solo lee de Cloudbeds) y, si detecta altas/bajas de piezas, levanta la
> alerta `inventario_cambios_pendientes` (P2) para la supervisora, con el detalle **hotel + número**
> de cada cambio. En la Home de la supervisora aparece con dos botones:
> - **Aceptar** → `POST /api/inventario/aplicar` aplica el import (crea/da de baja las piezas) y
>   resuelve la alerta.
> - **Rechazar** → `POST /api/inventario/rechazar` descarta los cambios y guarda su "huella"; **no
>   se vuelve a molestar hasta que el set cambie** (si Cloudbeds cambia de nuevo, avisa otra vez).
>
> Ambos endpoints exigen el permiso `habitaciones.importar_inventario` (Admin + Supervisora). Si
> en un chequeo ya no hay diferencias, la alerta se auto-resuelve. Piezas: `InventarioCheckService`,
> `InventarioController`, `scripts/check-inventario-cloudbeds.php` (chequeo manual con `--force`),
> tipo de alerta en `AlertaActiva` + `scripts/migrate-add-inventario-alerta.php` (CHECK).
> Esto NO cambia el import en sí: sigue siendo el mismo `InventarioImportService` idempotente.

> **Estado:** IMPLEMENTADO (30/06/2026). Script `scripts/import-inventario-cloudbeds.php` +
> `src/Services/InventarioImportService.php` + `tests/Integration/InventarioImportServiceTest.php`.
>
> **Decisiones tomadas por Nicolás:**
> - **#1 → El import reemplaza todo el seed demo.** Se eliminó `scripts/seed-demo-data.php`
>   (y `prepare-demo-video.php` + `SeedDemoDataTest`). Dev y prod arrancan con inventario real.
> - **#2 → `numero` = prefijo numérico del `roomName`** (`101-BOT2 M` → `101`). Verificado
>   contra los datos reales: **0 colisiones** (99 prefijos únicos en 1_sur, 57 en INN). Si algún
>   día colisionan, el import los reporta y salta (no viola el `UNIQUE(hotel_id, numero)`).
> - **#3 → SUPERSEDIDO (22/07/2026): el tipo es el `roomTypeName` real de Cloudbeds, no `maxGuests`.**
>   El import hace get-or-create de un `tipos_habitacion` por cada `roomTypeName` y le crea su checklist
>   default. `maxGuests` colapsaba dos piezas de igual capacidad pero limpieza distinta en un mismo
>   balde; el tipo real las separa. Ver [checklist.md](checklist.md) §2.6. (El mapeo viejo por
>   `maxGuests` — `Singular`/`Doble/Matrimonial`/`Suite/Familiar` — quedó retirado de `catalogos.php`.)
> - **#4 → NO era bloqueante.** Cada tipo nuevo obtiene su checklist default compartido al importarlo
>   (auto-create en `InventarioImportService` + red anti-500 en `iniciarEjecucion`), así que las piezas
>   son usables desde el arranque; la supervisora personaliza por UI después.
> - **#5 → `roomBlocked` se importa con `activa=0`** (conserva el mapeo y el histórico). Hoy: 2 piezas.
> - **#6 → Upsert idempotente, corrida on-demand.** Match por `(hotel_id, cloudbeds_room_id)` en
>   código (portable SQLite/MariaDB). Las piezas cuyo room_id desaparece de Cloudbeds → `activa=0`,
>   nunca se borran. No hay cron todavía (decisión aparte).
>
> El resto de este documento es el plan original que motivó la implementación.

## El problema

La app gestiona limpieza por **habitación**, matcheando cada pieza con Cloudbeds vía
`habitaciones.cloudbeds_room_id`. Pero hoy:

- El inventario se siembra **hardcodeado como demo** en `scripts/seed-demo-data.php`
  (`seedHabitacionesDemo`): **20 habitaciones ficticias** (12 en `1_sur`, 8 en `inn`),
  con números inventados (101, 102…) y 4 tipos genéricos (Singular/Doble/Matrimonial/Suite).
- Esas 20 habitaciones tienen **`cloudbeds_room_id = NULL`** → no matchean con nada real.
- **No existe ningún pipeline** que traiga las habitaciones reales de Cloudbeds a la app.

### Lo que hay en Cloudbeds (verificado el 30/06/2026, lectura real)

| Propiedad | property_id | Habitaciones reales | Tipos | isVirtual | isPrivate |
|---|---|---|---|---|---|
| Atankalama (1_sur) | 209760 | **99** | 12 | 0 | 99 |
| Atankalama INN (inn) | 209761 | **57** | 4 | 0 | 57 |

**Ninguna es virtual; todas son reales.** Ejemplos de `roomName`: `101-BOT2 M`,
`300-EXE2 M`, `403-INN2 2S`. Ejemplos de `roomTypeName`: *Premium triple* (×28),
*Executive Matri o Doble (M)* (×17), *Loft Familiar*, *Atan Inn2 Hab Doble / Matrimonial* (×24).
Campos disponibles por room: `roomID, roomName, roomDescription, maxGuests, isPrivate,
isVirtual, roomBlocked, roomTypeID, roomTypeName, roomTypeNameShort`.

**Conclusión:** para operar en producción, la app necesita las **156 habitaciones reales**
(no las 20 demo), cada una con su `cloudbeds_room_id`. Sin eso, el sync entrante
(`getHousekeepingStatus`) solo afecta a las piezas que la app conozca, y el resto del
hotel queda fuera del sistema.

## Decisiones abiertas (necesito tu input, Nicolás)

Ninguna se puede asumir; cada una cambia el diseño:

1. **Demo vs. real.** ¿El import **reemplaza** el inventario demo (dev y prod usan lo real),
   o se mantiene el demo para desarrollo/tests y el import corre **solo en producción**
   (u on-demand)?
   - *Recomendación:* mantener demo para dev/tests (tests deterministas) + script de
     import separado para prod. Así no atamos la suite a datos reales que cambian.

2. **`numero` de la app ← ¿qué campo de Cloudbeds?** `roomName` viene como `101-BOT2 M`.
   ¿El `numero` operativo es el `roomName` completo, o un prefijo parseado (`101`)?
   Ojo: `numero` es `UNIQUE (hotel_id, numero)`, así que debe ser único por hotel.
   *(Vos conocés la nomenclatura que usan las camareras.)*

3. **Mapeo de tipos.** Cloudbeds tiene 12 tipos en 1_sur + 4 en inn. Opciones:
   - **(a)** Importar los `roomTypeName` tal cual → ~16 `tipos_habitacion`. Fiel, pero
     cada tipo nuevo necesita su plantilla de checklist (ver #4).
   - **(b)** Mapear los tipos de Cloudbeds → un set chico de "tipos de limpieza" de la app
     (p. ej. por `maxGuests`: 1→Singular, 2→Doble/Matrimonial, 3-4→Suite/Familiar) vía una
     tabla de equivalencia. Menos checklists que mantener.
   - *Recomendación:* (b) si los protocolos de limpieza no cambian por sub-tipo comercial;
     (a) si la supervisora quiere checklists distintos por tipo real.

4. **Checklists por tipo.** Los checklists son **por `tipo_habitacion`** (el flujo de la
   trabajadora exige una plantilla activa para el tipo). Cualquiera sea la decisión #3,
   **cada tipo resultante necesita su checklist template activo**. Esto es trabajo
   operativo (definir los ítems con la supervisora), no solo código.

5. **`roomBlocked` / fuera de servicio.** ¿Importar las piezas con `roomBlocked=true`
   (marcándolas inactivas/`activa=0`), o saltarlas?

6. **Sincronización continua vs. carga única.** ¿El import corre una vez (carga inicial) o
   periódicamente para reflejar altas/bajas de habitaciones en Cloudbeds? Si es continuo:
   ¿qué hacer con una pieza de la app cuyo `cloudbeds_room_id` desaparece de Cloudbeds
   (desactivar `activa=0`, nunca borrar por el histórico)?

## Diseño propuesto (contingente a las decisiones)

- **Nuevo `scripts/import-inventario-cloudbeds.php`** (+ posible `InventarioImportService`):
  1. Por cada hotel activo con `cloudbeds_property_id`, llamar `obtenerHabitaciones()`
     (ya paginado → trae las 99/57 completas).
  2. Filtrar según #5 (saltar o marcar `roomBlocked`).
  3. Resolver `tipo_habitacion_id` según el mapeo #3 (creando tipos faltantes si aplica).
  4. **Upsert idempotente por `cloudbeds_room_id`**: crear la habitación si su room_id no
     existe; actualizar `numero`/`tipo` si cambiaron. (Nota: `cloudbeds_room_id` hoy **no**
     tiene índice único — conviene agregar `UNIQUE (hotel_id, cloudbeds_room_id)` como
     índice parcial que ignore NULLs, o resolver el upsert en código.)
  5. Según #6, desactivar (`activa=0`) las piezas de la app cuyo room_id ya no venga.
  6. Reportar contadores: creadas / actualizadas / saltadas / desactivadas.
- **Barandas:** modo `--dry-run` (imprime el plan de cambios sin tocar la BD), correr en
  baja ocupación, logging de cada alta/baja. Respeta el rate limit (getRooms ya pagina
  con backoff; ~1 request por propiedad a pageSize=100).
- **Tests:** unitarios del mapeo de tipos y del upsert idempotente (con `FakeHttpTransport`
  + BD de test), incluyendo el caso "room_id que desaparece → se desactiva".

## Riesgos / notas

- Reemplazar el inventario demo por 156 piezas reales **rompe** los datos de demo
  (asignaciones, ejecuciones, KPIs sembrados) → por eso la recomendación de separar
  demo (dev) de import (prod).
- Sin checklists para los tipos nuevos, la trabajadora no puede completar esas piezas
  → el checklist es **bloqueante** para que el import sea usable, no un extra.
- La decisión #2/#3 afecta reportes y el histórico: cambiar el criterio después obliga a
  re-mapear datos ya cargados.

## Siguiente paso

~~Nicolás decide #1–#6~~ → **Resuelto e implementado** (ver bloque de estado arriba).

Pendiente operativo (no de código):
- Correr el import real en baja ocupación: `php scripts/import-inventario-cloudbeds.php --dry-run`
  para revisar el plan, y luego sin `--dry-run` para aplicarlo.
- La supervisora personaliza los checklist templates por tipo desde la UI si el default no alcanza.
- ~~Evaluar si el import pasa a cron periódico (hoy es on-demand).~~ **Resuelto (21/07/2026):**
  detección automática diaria + alerta con Aceptar/Rechazar (ver bloque de estado arriba). El
  import a mano por CLI sigue disponible para casos puntuales.
