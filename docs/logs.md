# Logs

**Versión:** 1.0 — 2026-04-14

Documenta el sistema de logging técnico (`logs_eventos`) y el registro de auditoría de acciones de usuario (`audit_log`).

---

## 1. Dos tablas, dos propósitos

### 1.1 `logs_eventos` — eventos técnicos del sistema

Registra cosas que pasan en el backend: errores, warnings, operaciones relevantes. Enfoque de **debugging y monitoreo**.

Niveles:
- `INFO`: operación normal (login, sync exitoso, tool call del copilot).
- `WARNING`: validación fallida, input rechazado, retry en curso.
- `ERROR`: fallo de integración, excepción no manejada, 3 reintentos fallidos.

### 1.2 `audit_log` — acciones de usuario

Registra quién hizo qué acción de negocio, cuándo. Enfoque de **trazabilidad y compliance**.

Ejemplos:
- `usuario.crear`, `usuario.reset_password`, `usuario.asignar_rol`
- `auditoria.aprobar`, `auditoria.rechazar`
- `asignacion.asignar_manual`, `asignacion.reasignar`
- `cloudbeds.sync_manual`
- `copilot.reasignar_habitacion` (con `origen='copilot'`)

---

## 2. Qué NO loggear (regla absoluta)

Nunca en `logs_eventos` ni en `audit_log`:
- Passwords (ni en claro ni en hash).
- Tokens de sesión (ni API keys).
- Headers `Authorization`, `Cookie`, `x-api-key`.
- Valor de `CLOUDBEDS_API_KEY`, `ANTHROPIC_API_KEY`.
- RUTs completos en contextos donde no agregan valor (el `usuario_id` es suficiente).

Helper `src/Helpers/LogSanitizer.php` — `sanitize(array $payload): array` elimina estos campos recursivamente antes de persistir.

---

## 3. Formato

### 3.1 `logs_eventos`

| Campo | Ejemplo |
|---|---|
| `nivel` | `'ERROR'` |
| `modulo` | `'cloudbeds'` |
| `mensaje` | `'Timeout al actualizar habitación 203 (intento 3/3)'` |
| `contexto_json` | `'{"habitacion_id": 203, "intento": 3, "error_http": 504}'` |
| `usuario_id` | `7` (quien disparó la acción, NULL si sistema/cron) |
| `created_at` | ISO 8601 |

### 3.2 `audit_log`

| Campo | Ejemplo |
|---|---|
| `usuario_id` | `7` |
| `accion` | `'auditoria.aprobar'` |
| `entidad` | `'habitacion'` |
| `entidad_id` | `203` |
| `detalles_json` | `'{"veredicto": "aprobado", "comentario": null}'` |
| `origen` | `'ui'` / `'copilot'` / `'api'` / `'cron'` / `'script'` |
| `ip` | `'190.100.x.x'` |
| `created_at` | ISO 8601 |

---

## 4. Viewer de logs (Ajustes → Logs)

Permiso: `logs.ver`.

### 4.1 UI

Dos tabs: "Eventos técnicos" | "Auditoría de acciones".

### 4.2 Filtros (comunes)

- **Rango de fecha** (default: últimas 24h).
- **Usuario** (autocomplete).
- **Módulo/acción** (select).

Específicos de `logs_eventos`:
- **Nivel** (multiselect: INFO/WARNING/ERROR).

Específicos de `audit_log`:
- **Origen** (ui/copilot/api/cron/script).
- **Entidad** (habitacion/usuario/ticket/etc.).

### 4.3 Tabla

Paginada 50 por página. Click en fila → modal con `contexto_json` / `detalles_json` formateado (JSON pretty print).

Botón "Exportar CSV" del set filtrado (post-MVP).

---

## 5. Rotación y retención

MVP: **sin rotación automática**. Tablas crecen indefinidamente.

Para producción post-MVP:
- `logs_eventos`: retener 90 días.
- `audit_log`: retener 1 año (razones de compliance).
- Script `scripts/rotate-logs.php` corre semanalmente vía cron.

---

## 6. Endpoints

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/logs/eventos` | `logs.ver` | Query filtros + paginado |
| GET | `/api/logs/audit` | `logs.ver` | Query filtros + paginado |

---

## 7. Referencias cruzadas

- [cloudbeds.md](cloudbeds.md) §5.3 — escrituras de sync se loggean
- [auth.md](auth.md) §9 — reglas de seguridad de logging
- [copilot-ia.md](copilot-ia.md) §9 — acciones con `origen='copilot'`
- [database-schema.sql](database-schema.sql) — `logs_eventos`, `audit_log`
- [CLAUDE.md](../CLAUDE.md) §"Reglas de seguridad" #10
