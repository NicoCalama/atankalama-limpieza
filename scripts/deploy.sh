#!/usr/bin/env bash
#
# deploy.sh — Deploy idempotente desde el VPS.
#
# Uso (en el VPS, como usuario de servicio):
#   cd /var/www/atankalama-limpieza
#   ./scripts/deploy.sh
#
# Hace:
#   1. git pull --ff-only
#   2. composer install --no-dev --optimize-autoloader
#   3. Aplica el schema (idempotente — usa IF NOT EXISTS)
#   4. Recarga PHP-FPM (si está disponible) para refrescar OPcache
#
# No hace:
#   - Reset de la BD (usa init-db.php --fresh manualmente si necesitas eso)
#   - Recarga de Caddy (el Caddyfile casi nunca cambia; recárgalo aparte)

set -euo pipefail

cd "$(dirname "$0")/.."
PROJECT_DIR="$(pwd)"

echo "==> Deploy desde ${PROJECT_DIR}"

if [[ -n "$(git status --porcelain)" ]]; then
    echo "!! Hay cambios locales sin commitear. Aborto."
    git status --short
    exit 1
fi

echo "==> git pull"
git pull --ff-only

echo "==> composer install (producción)"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Aplicando schema (idempotente)"
php scripts/init-db.php

if command -v systemctl >/dev/null 2>&1; then
    SERVICE=$(systemctl list-units --type=service --no-pager 2>/dev/null | grep -oE 'php[0-9.]+-fpm\.service' | head -1 || true)
    if [[ -n "${SERVICE}" ]]; then
        echo "==> Recargando ${SERVICE}"
        sudo systemctl reload "${SERVICE}"
    else
        echo "   (PHP-FPM no detectado vía systemctl — omito reload)"
    fi
fi

echo "==> Deploy OK."
