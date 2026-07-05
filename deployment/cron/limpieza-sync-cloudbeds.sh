#!/bin/sh
# limpieza-sync-cloudbeds.sh — sync entrante de Cloudbeds (auto-regulado).
#
# Instalar en /home/cat6852/cron/ (FUERA del webroot), chmod 755, saltos LF.
# Cron cPanel (el campo Command NO admite metacaracteres, por eso el wrapper):
#   */10 * * * * /home/cat6852/cron/limpieza-sync-cloudbeds.sh
# El script PHP se auto-throttlea por cloudbeds_config.sync_intervalo_minutos
# (default 30 min): el tick corto NO implica 96 syncs/día reales.
#
# PHP_BIN: descubrir la ruta CLI real con el probe whichphp (ver runbook §crons)
# — el "php" que resuelve el cron de cPanel es CGI y NO sirve.

PHP_BIN="/usr/local/bin/ea-php84"
APP="/home/cat6852/public_html/limpieza/app_core"
# Log FUERA del webroot: si el hosting dejara de aplicar .htaccess, que el
# output del sync (estados de piezas) no quede descargable.
LOGDIR="/home/cat6852/logs"
LOG="$LOGDIR/limpieza-sync.log"

mkdir -p "$LOGDIR"
cd "$APP" || exit 1
"$PHP_BIN" scripts/sync-cloudbeds.php >> "$LOG" 2>&1

# Rotación simple: si el log pasa de 1 MB, conservar solo la cola (~256 KB).
if [ -f "$LOG" ] && [ "$(wc -c < "$LOG")" -gt 1048576 ]; then
    tail -c 262144 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"
fi
