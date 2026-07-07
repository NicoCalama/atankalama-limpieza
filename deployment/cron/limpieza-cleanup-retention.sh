#!/bin/sh
# limpieza-cleanup-retention.sh — poda de datos por política de retención (RGPD).
#
# Instalar en $HOME/cron/, chmod 755, saltos LF.
# Cron cPanel:
#   0 3 * * * $HOME/cron/limpieza-cleanup-retention.sh
#
# PHP_BIN: descubrir con el probe whichphp (ver runbook §crons).

PHP_BIN="/opt/alt/php84/usr/bin/php"
APP="$HOME/public_html/limpieza/app_core"
# Log fuera del webroot (ver limpieza-sync-cloudbeds.sh).
LOGDIR="$HOME/logs"
LOG="$LOGDIR/limpieza-retencion.log"

mkdir -p "$LOGDIR"
cd "$APP" || exit 1
"$PHP_BIN" scripts/cleanup-retention.php >> "$LOG" 2>&1

if [ -f "$LOG" ] && [ "$(wc -c < "$LOG")" -gt 1048576 ]; then
    tail -c 262144 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"
fi
