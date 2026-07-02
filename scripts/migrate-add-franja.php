<?php

declare(strict_types=1);

/**
 * Migración: agrega asignaciones.franja (ventana de la limpieza: mañana/tarde/noche) a una BD
 * existente. Necesario para el feature "varias limpiezas por día" (docs/limpiezas-multiples-dia.md):
 * distinguir la limpieza de día de la de noche cuando una pieza se limpia varias veces el mismo día.
 *
 * En installs frescos la columna la crea init-db.php desde los schemas; este script la agrega a BDs
 * ya creadas. Portable (SQLite dev + MariaDB prod) e idempotente: seguro de correr múltiples veces.
 * No necesita backfill (NULL = sin etiqueta describe bien las asignaciones existentes).
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$driver  = Database::driver();
$esMaria = $driver === 'mysql' || $driver === 'mariadb';
$pdo     = Database::pdo();
$tabla   = Database::tabla('asignaciones');

if ($esMaria) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$tabla, 'franja']);
    $existe = (int) $stmt->fetchColumn() > 0;
} else {
    $cols = $pdo->query('PRAGMA table_info(' . $tabla . ')')->fetchAll(\PDO::FETCH_ASSOC);
    $existe = in_array('franja', array_column($cols, 'name'), true);
}

if ($existe) {
    echo "La columna {$tabla}.franja ya existe — omito ALTER.\n";
} else {
    // Nullable; el CHECK (franja IN ('mañana','tarde','noche')) va en los schemas frescos. Sobre datos
    // existentes agregamos solo la columna nullable (la validación real la hace AsignacionService).
    $tipo = $esMaria ? 'VARCHAR(10) NULL' : 'TEXT';
    $pdo->exec("ALTER TABLE {$tabla} ADD COLUMN franja {$tipo}");
    echo "Columna {$tabla}.franja agregada.\n";
}

echo "Migración completa.\n";
