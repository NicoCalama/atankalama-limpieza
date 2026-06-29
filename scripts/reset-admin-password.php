<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$password = 'Admin2025!';
$hash     = password_hash($password, PASSWORD_BCRYPT);

// Pasa por Database (token #__ + driver configurado) → funciona en SQLite y MariaDB.
$filas = Database::execute(
    'UPDATE #__usuarios SET password_hash = ?, requiere_cambio_pwd = 1 WHERE rut = ?',
    [$hash, '11111111-1']
);

if ($filas === 0) {
    // En MariaDB rowCount cuenta filas CAMBIADAS; un hash bcrypt nuevo siempre difiere del
    // anterior, así que 0 ⇒ no existe el usuario (no un no-op por valor idéntico).
    echo "No se encontró usuario con RUT 11111111-1\n";
    exit(1);
}

echo "Contraseña reseteada correctamente.\n";
echo "RUT:        11111111-1\n";
echo "Contraseña: {$password}\n";
echo "Deberás cambiarla al primer inicio de sesión.\n";
