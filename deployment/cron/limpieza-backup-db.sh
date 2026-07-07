#!/bin/sh
# limpieza-backup-db.sh — respaldo diario de las tablas limpieza_* (SOLO esas:
# la BD cat6852_australia es COMPARTIDA con Maisterchef, no volcarla completa).
#
# Instalar en $HOME/cron/, chmod 755, saltos LF.
# Cron cPanel:
#   30 3 * * * $HOME/cron/limpieza-backup-db.sh
#
# Credenciales: $HOME/.my.cnf con chmod 600 (NUNCA en la línea de
# comandos). Formato:
#   [client]
#   user=USUARIO_MYSQL
#   password=LA_PASSWORD
#
# Si el binario se llama mariadb-dump o exige --skip-ssl en este hosting,
# ajustar DUMP_BIN / DUMP_ARGS (fragilidad conocida, ver runbook §backup).

DB="cat6852_australia"
DEST="$HOME/backups/limpieza"
CNF="$HOME/.my.cnf"
DUMP_BIN="mysqldump"
DUMP_ARGS="--single-transaction --quick --no-tablespaces --default-character-set=utf8mb4"
FECHA=$(date +%Y%m%d-%H%M)

mkdir -p "$DEST"

TABLAS=$(mysql --defaults-extra-file="$CNF" -N -B -e "SHOW TABLES LIKE 'limpieza\\_%'" "$DB")
if [ -z "$TABLAS" ]; then
    echo "$(date '+%F %T') ERROR: no hay tablas limpieza_* en $DB" >> "$DEST/backup.log"
    exit 1
fi

# Dump a archivo temporal ANTES de comprimir: en un pipe `mysqldump | gzip` el
# exit que ve el if es el de gzip, y un mysqldump muerto quedaría logueado como
# OK con un .gz vacío (y la retención iría borrando los backups buenos).
TMP="$DEST/.limpieza-$FECHA.sql"
# shellcheck disable=SC2086  # $TABLAS y $DUMP_ARGS deben expandirse por palabra
if ! "$DUMP_BIN" --defaults-extra-file="$CNF" $DUMP_ARGS "$DB" $TABLAS > "$TMP"; then
    echo "$(date '+%F %T') ERROR: $DUMP_BIN falló (revisar DUMP_BIN/DUMP_ARGS, p.ej. mariadb-dump o --skip-ssl)" >> "$DEST/backup.log"
    rm -f "$TMP"
    exit 1
fi

# Sanity: un dump real de 32 tablas nunca baja de ~50 KB; menos que eso es basura.
if [ "$(wc -c < "$TMP")" -lt 51200 ]; then
    echo "$(date '+%F %T') ERROR: dump sospechosamente chico ($(wc -c < "$TMP") bytes), descartado" >> "$DEST/backup.log"
    rm -f "$TMP"
    exit 1
fi

gzip -f "$TMP" && mv "$TMP.gz" "$DEST/limpieza-$FECHA.sql.gz"
echo "$(date '+%F %T') OK: limpieza-$FECHA.sql.gz ($(wc -c < "$DEST/limpieza-$FECHA.sql.gz") bytes)" >> "$DEST/backup.log"

# Retención: 14 días de dumps.
find "$DEST" -name 'limpieza-*.sql.gz' -mtime +14 -delete
