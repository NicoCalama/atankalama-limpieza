<?php

declare(strict_types=1);

/**
 * Migración: agrega items_checklist.creditos (peso de créditos por ítem) a una BD existente.
 * Necesario para el editor de checklist con créditos configurables (docs/creditos-rework.md):
 * cada ítem obligatorio puede valer N créditos en vez de 1 fijo.
 *
 * En installs frescos la columna la crea init-db.php desde los schemas; este script la agrega a
 * BDs ya creadas. Portable (SQLite dev + MariaDB prod) e idempotente: seguro de correr varias veces.
 *
 * Backfill automático: ADD COLUMN ... NOT NULL DEFAULT 1 pone 1 en todas las filas existentes, así
 * los reportes de créditos históricos dan EXACTAMENTE lo mismo que antes (1 obligatorio = 1 crédito).
 * El CHECK (creditos >= 0) va solo en los schemas frescos (SQLite no lo agrega bien vía ALTER); la
 * validación real la hace ChecklistService al editar.
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$driver  = Database::driver();
$esMaria = $driver === 'mysql' || $driver === 'mariadb';
$pdo     = Database::pdo();
$tabla   = Database::tabla('items_checklist');

if ($esMaria) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$tabla, 'creditos']);
    $existe = (int) $stmt->fetchColumn() > 0;
} else {
    $cols = $pdo->query('PRAGMA table_info(' . $tabla . ')')->fetchAll(\PDO::FETCH_ASSOC);
    $existe = in_array('creditos', array_column($cols, 'name'), true);
}

if ($existe) {
    echo "La columna {$tabla}.creditos ya existe — omito ALTER.\n";
} else {
    $tipo = $esMaria ? 'INT NOT NULL DEFAULT 1' : 'INTEGER NOT NULL DEFAULT 1';
    $pdo->exec("ALTER TABLE {$tabla} ADD COLUMN creditos {$tipo}");
    echo "Columna {$tabla}.creditos agregada (backfill = 1 en filas existentes).\n";
}

echo "Migración completa.\n";
