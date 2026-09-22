<?php

declare(strict_types=1);

/**
 * Rescate de emergencia: resetea la contraseña de un usuario (por defecto, el admin inicial
 * del seed) a una TEMPORAL generada al azar, y lo obliga a cambiarla al iniciar sesión.
 *
 * Nunca usa una contraseña fija en el código (regla 1 de CLAUDE.md) ni vacía (el login la
 * rechaza, así que dejaba al usuario sin forma de entrar).
 *
 * Uso (solo consola):
 *   php scripts/reset-admin-password.php           # RUT 11111111-1 (admin inicial del seed)
 *   php scripts/reset-admin-password.php <RUT>     # otro usuario
 *
 * Si además hay que reactivar al usuario o devolverle el rol de administrador, usar
 * `php scripts/promover-admin.php <RUT> --reset-pwd`.
 */

// Solo consola: nunca debe poder ejecutarse abriendo su URL.
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Helpers\Rut;
use Atankalama\Limpieza\Services\PasswordService;

Config::load(dirname(__DIR__));

$rut = Rut::normalizar($argv[1] ?? '11111111-1');

$passwords = new PasswordService();
$temporal  = $passwords->generarTemporal();

// Pasa por Database (token #__ + driver configurado) → funciona en SQLite y MariaDB.
$filas = Database::execute(
    'UPDATE #__usuarios SET password_hash = ?, requiere_cambio_pwd = 1 WHERE rut = ?',
    [$passwords->hash($temporal), $rut]
);

if ($filas === 0) {
    // En MariaDB rowCount cuenta filas CAMBIADAS; un hash bcrypt nuevo siempre difiere del
    // anterior, así que 0 ⇒ no existe el usuario (no un no-op por valor idéntico).
    fwrite(STDERR, "No se encontró un usuario con RUT {$rut}.\n");
    exit(1);
}

echo "Contraseña reseteada correctamente.\n";
echo "RUT:                 {$rut}\n";
echo "Contraseña temporal: {$temporal}\n";
echo "Deberá cambiarla al iniciar sesión.\n";
