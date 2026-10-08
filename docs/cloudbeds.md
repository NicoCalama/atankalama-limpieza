# Integración con Cloudbeds

**Versión:** 1.0 — 2026-04-14

Documenta la integración con la API de Cloudbeds: credenciales, endpoints utilizados, algoritmo de sincronización, cola de reintentos y logging.

---

## 1. Alcance

Cloudbeds es el PMS (Property Management System) usado por Atankalama. Nuestra app se integra para:

1. **Leer** el estado de limpieza de las habitaciones (Dirty / Clean) y cambios de check-out.
2. **Escribir** el estado Clean cuando una habitación es aprobada (o aprobada con observación) en nuestra app.

Cloudbeds es la **fuente de verdad** para el estado comercial de la habitación. Nuestra app gestiona el proceso de limpieza; Cloudbeds gestiona la disponibilidad comercial.

---

## 2. Credenciales

### 2.1 Variables de entorno (`.env`)

```
CLOUDBEDS_BASE_URL=https://api.cloudbeds.com/api/v1.1

# Una API key + propertyID por propiedad (no commitear valores reales):
CLOUDBEDS_API_KEY_PRINCIPAL=<secret>   # Atankalama (1 Sur 858)
CLOUDBEDS_PROPERTY_ID_PRINCIPAL=209760
CLOUDBEDS_API_KEY_INN=<secret>         # Atankalama INN (Chorrillos 558)
CLOUDBEDS_PROPERTY_ID_INN=209761
```

> **Importante:** la base URL es **`/api/v1.1`** (no `/api/v1`, que devuelve 404).

- **Nunca** hardcodear.
- **Nunca** commitear `.env` (solo `.env.example` con placeholders).
- **Nunca** loggear el valor de ninguna `CLOUDBEDS_API_KEY_*`, ni en errores, ni en sanitización, ni en debugging.
- Cada propiedad tiene su **propia** API key. Nunca mezclar la key de una propiedad con el `propertyID` de la otra.
- Rotación: manual desde el panel de Cloudbeds. Cuando se rote, actualizar `.env` en producción y reiniciar PHP-FPM.

### 2.2 Acceso en código

El `CloudbedsClient` resuelve la key correcta según el `propertyID`. Construirlo siempre
con el factory, que arma el mapa `propertyID => apiKey` desde el `.env`:

```php
$client = CloudbedsClient::desdeConfig();
$habitaciones = $client->obtenerHabitaciones('209761'); // usa CLOUDBEDS_API_KEY_INN
```

---

## 3. Endpoints que usamos

Wrapper en `src/Services/CloudbedsClient.php`. Métodos:

| Método PHP | Endpoint Cloudbeds | Uso |
|---|---|---|
| `obtenerHabitaciones(string $propertyId): array` | `GET /getRooms` | Listar habitaciones (paginado: `count`/`total`) |
| `obtenerEstadosHabitaciones(string $propertyId, ?string $fecha = null): array` | `GET /getHousekeepingStatus` | Estados de limpieza (data plano: `roomID` + `roomCondition`) |
| `obtenerAsignacionesReservas(string $propertyId, ?string $fecha = null): array` | `GET /getReservationAssignments` | Nombre del huésped por pieza (`cb_huesped`) |
| `obtenerReservasDelDia(string $propertyId, string $fecha): array` | `GET /getReservations` | Cantidad de huéspedes por pieza (`cb_huespedes`, `cb_huespedes_llegan`; v6.14). Filtra `checkInTo`/`checkOutFrom` = el día, `datesQueryMode=rooms`, `includeAllRooms=true`; paginado. Ver `docs/ocupacion-y-sabanas.md` §2.5 |
| `actualizarEstadoHabitacion(string $propertyId, string $roomId, string $estadoCloudbeds): HttpResponse` | `POST /postHousekeepingStatus` | Cambiar a Clean/Dirty |

Nota: endpoints validados contra la API v1.1 real el 30/06/2026. **`getRoomsStatus` NO existe (devuelve 404)** — el endpoint correcto para leer estados de limpieza es `getHousekeepingStatus`. `getRooms` está **paginado** (`count`/`total`); trae 20 por página aunque la propiedad tenga más. **Usar `mcp__context7__query-docs` si hay dudas** sobre la API actual.

### 3.1 Campos de `getHousekeepingStatus` (verificado en v1.1 — 02/07/2026)

La respuesta trae `data` como **array plano** de habitaciones. Hoy el sync solo usa `roomID` +
`roomCondition`, pero **cada fila trae mucho más** — la base de los features de ocupación, sábanas y
multi-limpieza automática (ver `docs/ocupacion-y-sabanas.md`):

| Campo | Valores | Uso |
|---|---|---|
| `roomID` | string | match con `habitaciones.cloudbeds_room_id` |
| `roomCondition` | `clean` / `dirty` | estado de limpieza (lo único que usamos hoy) |
| `roomOccupied` | bool | ocupada ahora |
| `roomBlocked` | bool | fuera de servicio |
| **`frontdeskStatus`** | `check-in` / `check-out` / `stayover` / `turnover` / `unused` | **ocupación del día** (llega / sigue / se va / día-noche) |
| **`arrivalDate`** | `YYYY-MM-DD` o `-` | entrada del huésped actual → noches de estadía (sábanas) |
| **`departureDate`** | `YYYY-MM-DD` o `-` | salida prevista |
| `doNotDisturb`, `refusedService`, `vacantPickup` | bool | flags operativos |
| `housekeeperID`, `housekeeper` | | housekeeper asignado en Cloudbeds |
| `roomTypeID`, `roomTypeName`, `roomName`, `id`, `date` | | metadatos |

**Semántica confirmada con datos reales:** `stayover` → `arrivalDate` = entrada original (pasada);
`check-out` → `departureDate` = hoy; `turnover` / `check-in` → `arrivalDate` = hoy (huésped entrante).
Los estados los **calcula Cloudbeds** desde el calendario de reservas; las reglas de cadencia de
sábanas de cada propiedad **NO** se exponen por la API (se replican del lado nuestro).

**OJO `count`/`total`:** la API reporta un `count`/`total` mayor que las filas reales de habitación
(p. ej. 119 vs 99). Matchear siempre por `roomID`, no confiar en el conteo.

---

## 4. Sincronización entrante (Cloudbeds → app)

### 4.1 Cron automático (auto-regulado)

- **Modelo:** el crontab invoca el script con un **tick corto** (cada 10 min) y el script se
  **auto-regula**: consulta la última sync entrante que sirvió (`exito`/`parcial`) y solo corre si
  pasaron ≥ `sync_intervalo_minutos` (default **30**, editable vía `PUT /api/cloudbeds/config`).
  Así la cadencia se cambia desde la app **sin tocar el crontab**. Las syncs con `error` no
  throttlean: el siguiente tick reintenta.
- **Crontab recomendado (cPanel):** `*/10 * * * * php /ruta/al/proyecto/scripts/sync-cloudbeds.php`
- **Flags:** `--force` salta el throttle; `--hotel=<codigo>` sincroniza una sola propiedad.
- Con el intervalo default (30 min) son ~290 requests/día a Cloudbeds: 3 GET por hotel en cada
  corrida (`getHousekeepingStatus`, `getReservationAssignments` y, desde la v6.14, `getReservations`)
  × 2 hoteles — irrelevante para su límite de 5 req/s por propiedad. La frecuencia importa doble desde que el sync también refresca
  la **ocupación** (frontdeskStatus/arrival) — ver `docs/ocupacion-y-sabanas.md`.
- *(Histórico: hasta el 02/07/2026 el modelo era 2 corridas/día en horas fijas
  `sync_schedule_morning`/`sync_schedule_afternoon`; esas claves fueron reemplazadas por
  `sync_intervalo_minutos`.)*

### 4.2 Sync manual

- Requiere permiso `cloudbeds.forzar_sincronizacion`.
- Endpoint: `POST /api/cloudbeds/sync` (opcional body: `{ "hotel": "1_sur" }`).
- Respuesta con `sync_id` que permite consultar progreso:
  ```json
  { "ok": true, "data": { "sync_id": 42, "estado": "en_progreso" } }
  ```

### 4.3 Algoritmo

```
1. Insertar fila en cloudbeds_sync_historial con tipo=auto_cron|manual, resultado=en_progreso.
2. Por cada hotel configurado:
   a. GET /getHousekeepingStatus → lista plana con roomID + roomCondition.
   b. Por cada habitación:
      - Matchear por cloudbeds_room_id.
      - Si cleaningStatus=Dirty y estado actual en app es (aprobada | aprobada_con_observacion | rechazada):
          → hubo check-out, pasar a 'sucia', crear nueva ejecución disponible.
          → EXCEPCIÓN (ver abajo): si la aprobación es de HOY y la pieza está OCUPADA,
            NO se revierte — ese 'dirty' es la marca del servicio del día siguiente.
          → Si se revierte una aprobación de HOY, queda WARNING en el log + alerta P1
            'aprobacion_deshecha' (se resuelve sola al volver a aprobarse). La de otro día
            (ciclo normal) y la rechazada vuelven a la cola sin alerta (v6.16).
      - Si cleaningStatus=Dirty y estado actual es 'sucia': no-op.
      - Si cleaningStatus=Clean y estado actual es 'completada_pendiente_auditoria': WARN (inconsistencia — auditamos por un lado, Cloudbeds por otro).
3. Actualizar sync_historial: finalizada_at=now, resultado=exito|parcial|error, contadores.
4. Si hubo error → crear alerta P0 cloudbeds_sync_failed.
```

### 4.x La excepción del check-in (incidente del 22/09/2026)

**Cloudbeds marca una pieza `dirty` apenas entra un huésped.** Para ellos eso no significa «está
sucia ahora», sino «va a necesitar aseo mañana» — está en su propia documentación de housekeeping.

La app leía ese `dirty` sin distinguir y **deshacía la aprobación del día**, mandando a limpiar de
nuevo una pieza recién hecha y ocupada. El caso testigo: la pieza **706** se aprobó a las 11:15, la
escritura a Cloudbeds respondió `success: true`, entró un huésped, y a las 11:41 el sync la devolvió
a sucia. La limpiaron dos veces. Ese día le pasó a ~8 piezas; en la semana previa, a varias por día.

**La regla** (`CloudbedsSyncService::aprobadaHoy()` + `motivoParaConservarAprobacion()`, al día de
la v6.20):

```
Cloudbeds dice 'dirty' y la pieza está en estado terminal:
  ¿Rechazada, o su última limpieza la cerró el cierre de la noche sin terminar (v6.19)?
    └─ SÍ → revertir                     (no la aprobó nadie / no se puede dar por limpia)
  ¿Su último cambio de estado (audit_log) fue HOY, día de Chile?
    ├─ NO → ¿frontdesk 'check-in' y ya ocupada?   (llega un huésped a una pieza aprobada antes — v6.20)
    │         ├─ SÍ → NO revertir, INFO al log    (queda aprobada hasta el aseo de mañana)
    │         └─ NO → revertir, sin alerta        (ciclo normal: el aseo diario 'stayover', el check-out)
    └─ SÍ → ¿frontdesk 'turnover'?                (se va un huésped y entra otro el mismo día)
              ├─ SÍ → ¿ya ocupada y la limpieza se TERMINÓ con la pieza vacía? (R1, v6.20)
              │         ├─ SÍ → NO revertir, INFO al log   (se limpió entre un huésped y otro)
              │         └─ NO → revertir + WARNING + alerta P1   (se limpió con el anterior adentro, o sin dato)
              └─ NO → ¿ocupada, o frontdesk 'check-in'/'stayover'?
                        ├─ SÍ → NO revertir, INFO al log
                        └─ NO → revertir + WARNING + alerta P1
```

La alerta P1 (`aprobacion_deshecha`) se resuelve sola cuando la pieza vuelve a quedar aprobada.

**«Se terminó con la pieza vacía» (v6.20).** Al pasar a `completada_pendiente_auditoria`,
`HabitacionService::cambiarEstado()` anota en el detalle del historial (`audit_log`) la ocupación de
la última lectura de Cloudbeds: `cb_ocupada` (0/1), `cb_frontdesk` y `cb_leida_at`. La sincronización
mira la última limpieza terminada de la pieza (`limpiezaTerminadaVaciaHoy()`): tiene que ser de hoy y
con `cb_ocupada = 0`. Sin la anotación (limpiezas anteriores a la v6.20) o si el sync no alcanzó a leer
la salida del huésped antes de que terminara la limpieza, vuelve a la cola como antes: nunca queda peor.
Depende de que Cloudbeds reporte la pieza vacía entre un huésped y otro (consulta Q2 del documento
«Ciclo de limpieza y Cloudbeds»); si no lo hace, el dato hay que sacarlo de las reservas (plan B, con SQL).

Pedidos que la originaron (08/10/2026): la supervisora contó que el turnover ya limpiado volvía a la
cola de la misma trabajadora al llegar el huésped nuevo; y Nicolás decidió que la pieza aprobada un día
anterior que recibe un huésped hoy se queda aprobada hasta el aseo de mañana. Si Recepción la marca
sucia antes de que llegue el huésped (la pieza todavía vacía), se respeta y vuelve a la cola.

**Qué NO rompe:**
- La re-limpieza legítima del mismo día (se fue un huésped, entra otro) llega **desocupada** y con
  frontdesk `check-out`/`turnover` → sigue revirtiendo igual que siempre.
- Los **nocheros** no dependen de esta rama: los revierte su propio barrido de las 16:00
  (`HabitacionService::barrerNocheros()`, lo llama `scripts/sync-cloudbeds.php`), que además avisa
  `dirty` a Cloudbeds. Desde la v6.17 el barrido solo toma piezas **aprobadas**: una rechazada espera a
  que se rehaga y apruebe, y recién ahí se pide la limpieza de la tarde (R4).
- **Marcar un nochero después de las 16:00 (v6.17):** si la pieza ya está aprobada y no se barrió
  hoy, pasa a sucia en el mismo momento (con aviso `dirty` a Cloudbeds y el historial a nombre de
  quien la marcó), sin esperar la próxima pasada del cron (cada 10 min). Antes de las 16:00 la toma
  el barrido normal; una pieza en limpieza o esperando inspección espera a que la aprueben.
- **Aviso de vencimiento (v6.17):** el último día de una marca (`nochero_hasta` = hoy), desde las
  08:00, el cron manda una notificación (bandeja + push, tipo `nochero_por_vencer`) a quienes tienen
  `habitaciones.marcar_nochero`, con las piezas por hotel. Una por persona y por día. Motivo: al día
  siguiente el cron apaga la marca en silencio y el barrido ya no la toma — así quedaron 11 piezas
  del INN sin limpieza de tarde el 02/10/2026. Es notificación y no alerta porque los tipos de
  alerta son una lista cerrada en la base de producción (agregar uno exige SQL).

**Pieza que cambió después de leer Cloudbeds (v6.17, R1/R6).** Lo que responde
`getHousekeepingStatus` es una foto del momento de la lectura. Si mientras el sync recorre la lista
una pieza cambia de estado en la app (la reasignan, la aprueban), esa pieza **no se toca**: la decide el
sync siguiente, con una foto nueva (INFO al log). Antes, una reasignación hecha justo entonces se
deshacía (la foto vieja decía `clean`) y una aprobación hecha justo entonces se revertía con una
alerta falsa. El instante de la lectura sale del reloj de la base, el mismo que fecha el `audit_log`.
Para eso todo cambio de estado tiene que pasar por `cambiarEstado()`: desde la v6.17 también los
reseteos a sucia al reasignar y al desasignar (antes eran un `UPDATE` directo, sin historial).

«Cuándo se aprobó» sale del último cambio de estado de la pieza en `audit_log` (la fila
`habitacion.cambiar_estado` que escribe `cambiarEstado()`). Hasta la v6.16 salía de
`habitaciones.updated_at`, con el supuesto de que solo lo movía `cambiarEstado()`. Era falso: también
lo mueven la nota de Recepción, marcar o desmarcar nochero, editar la estructura y el vencimiento de
nocheros de las 16:00. Con eso, una aprobación de ayer con una nota de hoy pasaba por «de hoy».

---

## 5. Sincronización saliente (app → Cloudbeds)

### 5.1 Disparadores

- Auditor aprueba (o aprueba con observación) una habitación → **tiene que escribirse Clean en Cloudbeds**.
- Rechazo no dispara nada (en Cloudbeds ya está Dirty).

### 5.2 Cola y reintentos

Por cada escritura:
1. Se llama `updateRoomStatus()` con timeout de 10s.
2. Si éxito (HTTP 200) → log INFO, fin.
3. Si error de red / timeout / 5xx:
   - Reintento 1 tras 1s.
   - Reintento 2 tras 2s.
   - Reintento 3 tras 4s.
4. Si los 3 reintentos fallan:
   - Crear alerta P0 `cloudbeds_sync_failed` en `alertas_activas` con contexto (habitación, error).
   - Log ERROR.
   - La habitación queda en estado `aprobada` localmente pero Cloudbeds sigue Dirty hasta intervención manual.
5. Si error 401 (credencial inválida):
   - NO reintentar.
   - Alerta P0 inmediata con mensaje "Credenciales Cloudbeds inválidas — revisar en Ajustes".

### 5.3 Tabla `cloudbeds_sync_historial`

Cada escritura queda registrada con:
- `tipo = 'escritura_estado'`
- `iniciada_at`, `finalizada_at`
- `payload_request` (JSON sanitizado — SIN tokens, SIN API key)
- `payload_response` (JSON sanitizado)
- `resultado`, `error_mensaje`

---

## 6. Sanitización de payloads en logs

**Regla absoluta:** los campos `API-KEY`, `Authorization`, `x-api-key`, `Bearer *` deben reemplazarse por `[REDACTED]` antes de guardar en BD o log.

Helper: `src/Helpers/LogSanitizer.php` — método `sanitize(array $payload): array` recursivo.

---

## 7. Configuración desde Ajustes

Tabla `cloudbeds_config` almacena settings editables:

| Clave | Valor ejemplo | Descripción |
|---|---|---|
| `sync_intervalo_minutos` | `30` | Cadencia del sync automático (el script se auto-regula; ver §4.1) |
| `reintentos_max` | `3` | Número de reintentos al escribir |
| `timeout_segundos` | `10` | Timeout por request |

Editables con permiso `cloudbeds.configurar_credenciales` desde Ajustes → Cloudbeds. Los **cambios de credenciales van a `.env`**, no a esta tabla.

---

## 8. Endpoints internos

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/cloudbeds/estado` | `cloudbeds.ver_estado_sincronizacion` | Última sync + health |
| POST | `/api/cloudbeds/sync` | `cloudbeds.forzar_sincronizacion` | Disparar manual |
| GET | `/api/cloudbeds/historial` | `cloudbeds.ver_estado_sincronizacion` | Lista paginada |
| PUT | `/api/cloudbeds/config` | `cloudbeds.configurar_credenciales` | Editar tabla `cloudbeds_config` |

---

## 9. Referencias cruzadas

- [habitaciones.md](habitaciones.md) §4 — sync con estados
- [auditoria.md](auditoria.md) §4 — disparador de escritura
- [alertas-predictivas.md](alertas-predictivas.md) — alerta P0 `cloudbeds_sync_failed`
- [logs.md](logs.md) — logging y audit
- [ajustes.md](ajustes.md) — UI de config Cloudbeds
- [database-schema.sql](database-schema.sql) — `cloudbeds_sync_historial`, `cloudbeds_config`
- [CLAUDE.md](../CLAUDE.md) §"Seguridad" — nunca loggear credenciales
