<?php

declare(strict_types=1);

/**
 * Migración: agrega el soporte de ocupación (Cloudbeds) y cambio de sábanas (Gaps A y B).
 * Ver docs/ocupacion-y-sabanas.md.
 *
 *   habitaciones:   cb_frontdesk_status, cb_ocupada, cb_arrival_date, cb_departure_date,
 *                   cb_ocupacion_sync_at   (todas nullable)
 *   items_checklist: es_cambio_sabanas   (NOT NULL DEFAULT 0)
 *   hoteles:        sabanas_cada_n_dias  (NOT NULL DEFAULT 4)
 *
 * En installs frescos estas columnas las crea init-db.php desde los schemas; este script las agrega
 * a BDs ya creadas. Portable (SQLite dev + MariaDB prod) e idempotente. No necesita backfill (los
 * defaults describen las filas existentes). Los CHECK de los enums van en los schemas frescos; la
 * validación real la hace el código.
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$driver  = Database::driver();
$esMaria = $driver === 'mysql' || $driver === 'mariadb';
$pdo     = Database::pdo();

$columnaExiste = static function (string $tabla, string $columna) use ($pdo, $esMaria): bool {
    if ($esMaria) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$tabla, $columna]);
        return (int) $stmt->fetchColumn() > 0;
    }
    $cols = $pdo->query('PRAGMA table_info(' . $tabla . ')')->fetchAll(\PDO::FETCH_ASSOC);
    return in_array($columna, array_column($cols, 'name'), true);
};

$agregar = static function (string $tablaLogica, string $columna, string $tipoSqlite, string $tipoMaria) use ($pdo, $esMaria, $columnaExiste): void {
    $tabla = Database::tabla($tablaLogica);
    if ($columnaExiste($tabla, $columna)) {
        echo "  {$tabla}.{$columna} ya existe — omito.\n";
        return;
    }
    $tipo = $esMaria ? $tipoMaria : $tipoSqlite;
    $pdo->exec("ALTER TABLE {$tabla} ADD COLUMN {$columna} {$tipo}");
    echo "  {$tabla}.{$columna} agregada.\n";
};

echo "Ocupación + sábanas:\n";
$agregar('habitaciones', 'cb_frontdesk_status',  'TEXT',                      'VARCHAR(20) NULL');
$agregar('habitaciones', 'cb_ocupada',           'INTEGER',                   'TINYINT NULL');
$agregar('habitaciones', 'cb_arrival_date',      'TEXT',                      'VARCHAR(10) NULL');
$agregar('habitaciones', 'cb_departure_date',    'TEXT',                      'VARCHAR(10) NULL');
$agregar('habitaciones', 'cb_ocupacion_sync_at', 'TEXT',                      'VARCHAR(30) NULL');
$agregar('items_checklist', 'es_cambio_sabanas', 'INTEGER NOT NULL DEFAULT 0','TINYINT NOT NULL DEFAULT 0');
$agregar('hoteles', 'sabanas_cada_n_dias',       'INTEGER NOT NULL DEFAULT 4','INT NOT NULL DEFAULT 4');

// Backfill: en BDs existentes los templates ya están sembrados (el seed no los re-toca), así que
// hay que marcar el ítem canónico de sábanas. Idempotente (solo filas aún en 0).
$marcados = Database::execute(
    "UPDATE #__items_checklist SET es_cambio_sabanas = 1
      WHERE es_cambio_sabanas = 0 AND descripcion = 'Hacer cama con sábanas limpias'"
);
echo "Backfill: {$marcados} ítem(s) de sábanas marcados en templates existentes.\n";

echo "Migración completa.\n";
