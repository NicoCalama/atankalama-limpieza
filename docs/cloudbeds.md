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
- Con el intervalo default (30 min) son ~96 requests/día a Cloudbeds (2 GET por corrida) —
  irrelevante para sus rate limits. La frecuencia importa doble desde que el sync también refresca
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
      - Si cleaningStatus=Dirty y estado actual es 'sucia': no-op.
      - Si cleaningStatus=Clean y estado actual es 'completada_pendiente_auditoria': WARN (inconsistencia — auditamos por un lado, Cloudbeds por otro).
3. Actualizar sync_historial: finalizada_at=now, resultado=exito|parcial|error, contadores.
4. Si hubo error → crear alerta P0 cloudbeds_sync_failed.
```

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
