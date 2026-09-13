<?php

declare(strict_types=1);

/**
 * Migración: agrega la columna tickets.novedad_id a una BD existente.
 *
 * Guarda el `id` que devuelve `novedades` al sincronizar la creación del
 * ticket (NovedadesSyncService::sincronizar) — hoy ese dato se descartaba.
 * Al cerrar el ticket, se reenvía como `origen_novedad_id` para que
 * `novedades` pueda vincular la novedad de cierre con la de creación (ver
 * sql/2026_09_08_agregar_columna_origen_novedad.sql del lado de novedades).
 *
 * En installs frescos la columna la crea init-db.php desde los schemas; este
 * script la agrega a BDs ya creadas. Portable (SQLite dev + MariaDB prod) e
 * idempotente: seguro de correr múltiples veces.
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$driver  = Database::driver();
$esMaria = $driver === 'mysql' || $driver === 'mariadb';
$pdo     = Database::pdo();
$tabla   = Database::tabla('tickets');

if ($esMaria) {
    $columnaExiste = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tabla}' AND COLUMN_NAME = 'novedad_id'"
    )->fetchColumn();

    if ($columnaExiste === 0) {
        $pdo->exec("ALTER TABLE {$tabla} ADD COLUMN novedad_id INT NULL DEFAULT NULL AFTER idempotency_key");
        echo "Columna novedad_id agregada a {$tabla}.\n";
    } else {
        echo "Columna novedad_id ya existía en {$tabla} — omito.\n";
    }
} else {
    $columnas = $pdo->query("PRAGMA table_info({$tabla})")->fetchAll(PDO::FETCH_ASSOC);
    $existe = false;
    foreach ($columnas as $col) {
        if ($col['name'] === 'novedad_id') {
            $existe = true;
            break;
        }
    }

    if (!$existe) {
        $pdo->exec("ALTER TABLE {$tabla} ADD COLUMN novedad_id INTEGER");
        echo "Columna novedad_id agregada a {$tabla}.\n";
    } else {
        echo "Columna novedad_id ya existía en {$tabla} — omito.\n";
    }
}

echo "Migración completa.\n";
