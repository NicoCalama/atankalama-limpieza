<?php

/**
 * Probe TEMPORAL para descubrir el binario PHP CLI real del hosting.
 *
 * El "php" del cron de cPanel es CGI (se traga los argumentos) y la ruta
 * canónica /opt/cpanel/ea-php84/... no existe en este hosting (lección de
 * Maisterchef). Uso:
 *   1. Renombrar con un token aleatorio: whichphp-K7X2M9.php
 *   2. Subirlo al docroot (public_html/limpieza/)
 *   3. Abrir https://atankalama.com/limpieza/whichphp-K7X2M9.php
 *   4. Anotar PHP_BINDIR y probar `<bindir>/php -v` en un cron de prueba
 *   5. BORRAR el archivo inmediatamente
 */

header('Content-Type: text/plain; charset=utf-8');
echo 'PHP_VERSION: ' . PHP_VERSION . "\n";
echo 'PHP_BINDIR:  ' . PHP_BINDIR . "\n";
echo 'PHP_BINARY:  ' . PHP_BINARY . "\n";
echo 'SAPI:        ' . PHP_SAPI . "\n";
echo 'extensiones clave: ';
foreach (['pdo_mysql', 'curl', 'mbstring', 'openssl', 'json'] as $ext) {
    echo $ext . '=' . (extension_loaded($ext) ? 'OK' : 'FALTA') . ' ';
}
echo "\n";
