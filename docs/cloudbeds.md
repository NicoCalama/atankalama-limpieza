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
CLOUDBEDS_API_BASE_URL=https://api.cloudbeds.com/api/v1.1
CLOUDBEDS_API_KEY=<secret>
CLOUDBEDS_PROPERTY_ID_1SUR=<id>
CLOUDBEDS_PROPERTY_ID_INN=<id>
```

- **Nunca** hardcodear.
- **Nunca** commitear `.env` (solo `.env.example` con placeholders).
- **Nunca** loggear el valor de `CLOUDBEDS_API_KEY`, ni en errores, ni en sanitización, ni en debugging.
- Rotación: manual desde el panel de Cloudbeds. Cuando se rote, actualizar `.env` en producción y reiniciar PHP-FPM.

### 2.2 Acceso en código

```php
$apiKey = $_ENV['CLOUDBEDS_API_KEY'] ?? throw new \RuntimeException('Credencial Cloudbeds no configurada');
```

---

## 3. Endpoints que usamos

Wrapper en `src/Services/CloudbedsClient.php`. Métodos:

| Método PHP | Endpoint Cloudbeds | Uso |
|---|---|---|
| `getRooms(int $propertyId): array` | `GET /getRooms` | Listar habitaciones con estado |
| `getRoomStatuses(int $propertyId, string $date): array` | `GET /getRoomsStatus` | Estados de limpieza del día |
| `updateRoomStatus(int $propertyId, string $roomId, string $estado): void` | `POST /postHousekeepingStatus` | Cambiar a Clean/Dirty |

Nota: los endpoints exactos deben validarse contra la documentación actual de Cloudbeds API v1.1 al momento de codificar. **Usar `mcp__context7__query-docs` si hay dudas** sobre la API actual.

---

## 4. Sincronización entrante (Cloudbeds → app)

### 4.1 Cron automático

- **Frecuencia:** 2 veces al día (configurable desde Ajustes).
- **Default:** 07:00 y 15:00 hora Chile.
- Script: `scripts/sync-cloudbeds.php`.
- Configurado en crontab (no en MVP self-hosted, dejar instrucciones en README).

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
   a. GET /getRoomStatuses → lista con roomId + cleaningStatus.
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
| `sync_schedule_morning` | `07:00` | Hora del cron matutino |
| `sync_schedule_afternoon` | `15:00` | Hora del cron tarde |
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
