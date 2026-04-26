# Política de retención de datos

Cumplimiento RGPD — principio de minimización temporal. La auditoría
`/seguridad-rgpd` identificó tablas que crecen indefinidamente; este documento
define cuánto tiempo se conservan los datos y cómo se purgan automáticamente.

## Resumen

| Tabla                    | Política por defecto                              | Variable `.env`                  |
|--------------------------|---------------------------------------------------|----------------------------------|
| `sesiones`               | Borrar las expiradas hace > 7 días                | `SESSION_CLEANUP_DAYS=7`         |
| `intentos_login`         | Borrar > 1 día (ventana de throttle es 15 min)    | `THROTTLE_CLEANUP_DAYS=1`        |
| `logs_eventos` (INFO/WARN) | Borrar > 90 días                                | `LOG_RETENTION_DAYS_INFO=90`     |
| `logs_eventos` (ERROR)   | Borrar > 365 días                                 | `LOG_RETENTION_DAYS_ERROR=365`   |
| `audit_log`              | **Nunca borrar** (compliance) salvo override      | `AUDIT_RETENTION_DAYS=0`         |
| `copilot_conversaciones` | Borrar > 365 días (mensajes caen por cascade)     | `COPILOT_RETENTION_DAYS=365`     |
| `copilot_mensajes`       | Limpieza de huérfanos siempre                     | (sin variable)                   |
| `notificaciones`         | Borrar leídas > 90 días (las no leídas se conservan) | `NOTIFICATIONS_RETENTION_DAYS=90` |

`audit_log` está exento por defecto porque registra acciones de negocio
deliberadas (auditorías, cambios de permisos, etc.) y suele ser exigido por
compliance. Solo se purga si se establece `AUDIT_RETENTION_DAYS > 0`.

## Cómo funciona

El script CLI `scripts/cleanup-retention.php`:

1. Lee los días desde `.env` (con defaults razonables si la variable falta).
2. Calcula el timestamp de corte en UTC con formato ISO 8601 (mismo que el schema).
3. Ejecuta los `DELETE` envueltos en una transacción SQLite.
4. Loggea el resumen en `logs_eventos` con módulo `retencion`.
5. Imprime por STDOUT cuántas filas borró por tabla.

Flags soportados:

- `--dry-run` — solo cuenta, no borra. Útil para revisar antes de programar el cron.
- `--verbose` (`-v`) — muestra la configuración cargada y detalle por tabla.

Ejemplos:

```bash
# Inspección sin riesgo (recomendado antes del primer run real)
php scripts/cleanup-retention.php --dry-run --verbose

# Ejecución real
php scripts/cleanup-retention.php

# Ejecución con detalles
php scripts/cleanup-retention.php --verbose
```

## Cron sugerido

A las 03:00 todos los días (hora baja de uso, fuera del turno mañana):

```cron
# Retención automática de datos — limpia logs, sesiones y throttle viejos.
# Corre todos los días a las 03:00 (zona horaria del servidor: America/Santiago).
0 3 * * * php /var/www/atankalama-limpieza/scripts/cleanup-retention.php >> /var/www/atankalama-limpieza/storage/logs/retencion.log 2>&1
```

Ajusta la ruta absoluta del proyecto según el VPS. El stdout queda en
`storage/logs/retencion.log`; los errores van por `2>&1` al mismo archivo y
adicionalmente quedan registrados en `logs_eventos` (módulo `retencion`).

## Decisiones de diseño

- **Mensajes huérfanos del copilot:** la FK tiene `ON DELETE CASCADE`, así que
  borrar la conversación borra sus mensajes. La limpieza adicional de huérfanos
  es defensiva (por si en algún momento falla el cascade o quedó algo inconsistente).
- **Notificaciones no leídas:** se preservan siempre. El usuario aún no las vio,
  borrarlas sería una mala UX.
- **Logs de error más largos:** los `ERROR` se mantienen 365 días por defecto
  para diagnóstico post-incidente. Los `INFO`/`WARNING` solo 90 días.
- **Dry-run no escribe en logs_eventos:** evitamos contaminar la tabla con runs
  de inspección. Solo la ejecución real deja registro.

## Recuperación ante errores

Si el script falla a mitad de ejecución, la transacción se revierte (no quedan
borrados parciales) y el error se registra en `logs_eventos` con módulo
`retencion`. El siguiente run del cron lo reintentará.
