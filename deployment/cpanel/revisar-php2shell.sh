#!/bin/sh
# revisar-php2shell.sh — revisión de SOLO LECTURA tras PHP2Shell
# (CVE-2026-109618 / CVE-2026-109619, octubre 2026). Ver docs/seguridad-php2shell-2026-10.md.
#
# No modifica nada: solo lista, busca y calcula huellas. Correr UNA vez por la
# Terminal de cPanel:
#   sh $HOME/cron/revisar-php2shell.sh
# o como cron de una sola vez (y borrar el cron al terminar). Deja dos archivos
# en $HOME/logs/ (fuera del webroot):
#   revision-php2shell.txt          el informe
#   revision-php2shell-huellas.txt  huella (sha1, sin \r) de cada archivo de la app,
#                                   para compararla con git en el PC de dev

DESDE="2026-10-17"   # primer intento de ataque conocido (antes del anuncio del 21/10)
WEB="$HOME/public_html"
APP="$WEB/limpieza"
OUT="$HOME/logs/revision-php2shell.txt"
HUELLAS="$HOME/logs/revision-php2shell-huellas.txt"
mkdir -p "$HOME/logs"

{
echo "== Revisión PHP2Shell — $(date -u '+%Y-%m-%d %H:%M UTC')"
echo

echo "== 1. Versiones de PHP instaladas (parchadas: 8.4.27 o más, 8.5.12 o más)"
for v in 84 85; do
    b="/opt/alt/php$v/usr/bin/php"
    if [ -x "$b" ]; then "$b" -v 2>/dev/null | head -1; else echo "php$v: no instalada"; fi
done
echo

echo "== 2. Extensiones de la 8.5 (la app usa: curl exif gd json mbstring openssl PDO pdo_mysql)"
if [ -x /opt/alt/php85/usr/bin/php ]; then /opt/alt/php85/usr/bin/php -m 2>/dev/null | tr '\n' ' '; fi
echo
echo

echo "== 3. PHP, .htaccess e ini nuevos o modificados desde $DESDE (toda la cuenta web)"
find "$WEB" -type f \( -name '*.php' -o -name '*.phtml' -o -name '*.phar' -o -name '.htaccess' \
    -o -name '.user.ini' -o -name 'php.ini' \) -newermt "$DESDE" \
    -printf '%TY-%Tm-%Td %TH:%TM  %8s  %p\n' 2>/dev/null | sort
echo

echo "== 4. Archivos que no son fotos .webp dentro de las carpetas uploads"
find "$WEB" -type d -name uploads 2>/dev/null | while read -r d; do
    find "$d" -type f ! -name '*.webp' ! -name 'index.html' ! -name '.htaccess' \
        -printf '%TY-%Tm-%Td %TH:%TM  %p\n' 2>/dev/null
done
echo

echo "== 5. Código típico de webshell en los .php modificados desde $DESDE"
find "$WEB" -type f -name '*.php' -newermt "$DESDE" 2>/dev/null \
    | xargs -r grep -l -E 'eval\(|base64_decode\(|gzinflate\(|str_rot13\(|assert\(\$|shell_exec\(|passthru\(|proc_open\(|\$_(GET|POST|REQUEST|COOKIE)\[[^]]+\]\(' 2>/dev/null
echo

echo "== 6. Crons de la cuenta"
crontab -l 2>/dev/null
echo

echo "== 7. Errores de PHP desde $DESDE (memoria o tiempo agotados, caídas)"
find "$HOME" -maxdepth 6 -type f -name 'error_log' -newermt "$DESDE" 2>/dev/null | while read -r f; do
    grep -H -E 'Allowed memory size|Out of memory|Maximum execution time|Segmentation|core dumped|zend_mm_heap corrupted' "$f" | tail -20
done
echo

echo "== 8. Logs de acceso disponibles"
ls -la "$HOME/access-logs/" "$HOME/logs/" 2>/dev/null
echo

echo "== Fin"
} > "$OUT" 2>&1

# Huellas de la app (sin datos: fuera uploads, storage, .env y error_log). Se quitan
# los \r porque algunos archivos quedaron con CRLF en el servidor (FileZilla en ASCII).
cd "$APP" || exit 1
find . -type f ! -path '*/uploads/*' ! -path './app_core/storage/*' ! -name '.env' ! -name 'error_log' \
    2>/dev/null | sort | while read -r f; do
    printf '%s  %s\n' "$(tr -d '\r' < "$f" | sha1sum | cut -c1-40)" "$f"
done > "$HUELLAS" 2>/dev/null

echo "Listo: $OUT y $HUELLAS"
