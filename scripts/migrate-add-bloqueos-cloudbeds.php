<?php

declare(strict_types=1);

/**
 * Migración (v7.8): habitaciones en mantención o cerradas en Cloudbeds.
 *
 *   habitaciones: cb_bloqueo_tipo, cb_bloqueo_motivo, cb_bloqueo_desde, cb_bloqueo_hasta,
 *                 limpieza_completa_pendiente (NOT NULL DEFAULT 0)
 *
 * El sync anota en cada pieza su bloqueo de hoy (getRoomBlocks) y, al terminar, marca que su próxima
 * limpieza es completa, con cambio de sábanas. Ver docs/cloudbeds.md §4.5. Al correr esta migración
 * ninguna pieza queda bloqueada: el primer sync las anota.
 *
 * El tipo de alerta nuevo ('habitacion_salio_bloqueo') NO lo agrega este script: correr también
 * scripts/migrate-add-inventario-alerta.php, que amplía el CHECK de alertas_activas.tipo a la lista
 * del código.
 *
 * En installs frescos estas columnas las crea init-db.php desde los schemas; este script las
 * agrega a BDs ya creadas. Portable (SQLite dev + MariaDB prod) e idempotente.
 */

// Solo consola: nunca debe poder ejecutarse abriendo su URL.
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

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

echo "Habitaciones en mantención o cerradas en Cloudbeds:\n";
$tipos = "CHECK (cb_bloqueo_tipo IN ('mantencion', 'cerrada', 'bloqueada'))";
$agregar('habitaciones', 'cb_bloqueo_tipo', "TEXT {$tipos}", "VARCHAR(20) NULL {$tipos}");
$agregar('habitaciones', 'cb_bloqueo_motivo', 'TEXT', 'VARCHAR(255) NULL');
$agregar('habitaciones', 'cb_bloqueo_desde', 'TEXT', 'VARCHAR(10) NULL');
$agregar('habitaciones', 'cb_bloqueo_hasta', 'TEXT', 'VARCHAR(10) NULL');
$agregar(
    'habitaciones',
    'limpieza_completa_pendiente',
    'INTEGER NOT NULL DEFAULT 0 CHECK (limpieza_completa_pendiente IN (0, 1))',
    'TINYINT NOT NULL DEFAULT 0 CHECK (limpieza_completa_pendiente IN (0, 1))'
);

echo "Migración completa. Falta el tipo de alerta: php scripts/migrate-add-inventario-alerta.php\n";
