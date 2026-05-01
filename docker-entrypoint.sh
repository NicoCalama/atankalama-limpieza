#!/bin/sh
set -e

# Directorio de la BD, siempre relativo al root del proyecto
DB_RELATIVE="${DB_PATH:-database/atankalama.db}"
DB_ABS="/var/www/html/${DB_RELATIVE}"
DB_DIR=$(dirname "$DB_ABS")

mkdir -p "$DB_DIR"
chown -R www-data:www-data "$DB_DIR"
chmod 775 "$DB_DIR"

# Inicializar / migrar la base de datos
php /var/www/html/scripts/init-db.php

# Arrancar Apache en primer plano
exec apache2-foreground
