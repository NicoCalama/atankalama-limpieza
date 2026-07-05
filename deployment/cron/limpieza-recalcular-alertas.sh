#!/bin/sh
# limpieza-recalcular-alertas.sh — recálculo de alertas predictivas.
#
# Instalar en /home/cat6852/cron/, chmod 755, saltos LF.
# Cron cPanel:
#   */15 * * * * /home/cat6852/cron/limpieza-recalcular-alertas.sh
#
# PHP_BIN: descubrir con el probe whichphp (ver runbook §crons).

PHP_BIN="/usr/local/bin/ea-php84"
APP="/home/cat6852/public_html/limpieza/app_core"
# Log fuera del webroot (ver limpieza-sync-cloudbeds.sh).
LOGDIR="/home/cat6852/logs"
LOG="$LOGDIR/limpieza-alertas.log"

mkdir -p "$LOGDIR"
cd "$APP" || exit 1
"$PHP_BIN" scripts/recalcular-alertas.php >> "$LOG" 2>&1

if [ -f "$LOG" ] && [ "$(wc -c < "$LOG")" -gt 1048576 ]; then
    tail -c 262144 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"
fi
