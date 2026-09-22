<?php

/**
 * Migración puntual: agrega la tabla festivos a una BD existente.
 * Seguro de ejecutar múltiples veces (usa CREATE TABLE IF NOT EXISTS).
 */

declare(strict_types=1);

// Solo consola: nunca debe poder ejecutarse abriendo su URL.
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

// Solo-SQLite: migración puntual (ya aplicada en la BD de desarrollo). En MariaDB la tabla
// #__festivos la crea init-db.php desde docs/database-schema.mariadb.sql. Este DDL usa
// AUTOINCREMENT/strftime (dialecto SQLite) y nombres sin prefijo: no portable a MariaDB.
if (Database::driver() !== 'sqlite') {
    fwrite(STDERR, "Migración solo-SQLite. En MariaDB la tabla festivos la crea init-db.php desde el schema MariaDB.\n");
    exit(1);
}

$pdo = Database::pdo();

$pdo->exec("
CREATE TABLE IF NOT EXISTS festivos (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    fecha        TEXT NOT NULL UNIQUE,
    nombre       TEXT NOT NULL,
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
CREATE INDEX IF NOT EXISTS idx_festivos_fecha ON festivos(fecha);
");

echo "Tabla festivos creada (o ya existía).\n";
