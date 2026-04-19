#!/usr/bin/env bash
#
# backup-db.sh — Backup de la base SQLite con rotación.
#
# Uso:
#   ./scripts/backup-db.sh [ruta_dir_backup]
#
# Por defecto escribe a /var/backups/atankalama/. Sobrescribible con argumento.
# Conserva los últimos 7 backups; el resto se borra.
#
# Cron sugerido (diario a las 03:30, usuario del servicio):
#   30 3 * * * /var/www/atankalama-limpieza/scripts/backup-db.sh >> /var/log/atankalama/backup.log 2>&1

set -euo pipefail

cd "$(dirname "$0")/.."
PROJECT_DIR="$(pwd)"

BACKUP_DIR="${1:-/var/backups/atankalama}"
DB_PATH="${PROJECT_DIR}/database/atankalama.db"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
DESTINO="${BACKUP_DIR}/atankalama-${TIMESTAMP}.db"
RETENCION_DIAS=7

if [[ ! -f "${DB_PATH}" ]]; then
    echo "!! No existe ${DB_PATH} — nada que respaldar."
    exit 1
fi

mkdir -p "${BACKUP_DIR}"

echo "==> Respaldando ${DB_PATH} → ${DESTINO}"

# sqlite3 .backup garantiza copia consistente incluso con writers activos.
if command -v sqlite3 >/dev/null 2>&1; then
    sqlite3 "${DB_PATH}" ".backup '${DESTINO}'"
else
    echo "   (sqlite3 CLI no disponible — usando cp; puede no ser consistente bajo carga)"
    cp "${DB_PATH}" "${DESTINO}"
fi

gzip --force "${DESTINO}"
echo "==> Listo: ${DESTINO}.gz"

echo "==> Purgando backups con más de ${RETENCION_DIAS} días"
find "${BACKUP_DIR}" -maxdepth 1 -name 'atankalama-*.db.gz' -mtime +"${RETENCION_DIAS}" -print -delete || true

echo "==> Backup OK."
