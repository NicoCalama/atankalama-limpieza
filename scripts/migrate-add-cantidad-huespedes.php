<?php

declare(strict_types=1);

/**
 * Migración: agrega la cantidad de huéspedes de Cloudbeds (getReservations) a la ficha de
 * habitación — lo que mostraba Flexkeeping («Guests: 2») en la pieza. Ver docs/ocupacion-y-sabanas.md.
 *
 *   habitaciones: cb_huespedes (nullable) — adultos+niños en la pieza hoy (o los que salieron hoy)
 *                 cb_huespedes_llegan (nullable) — adultos+niños que llegan hoy
 *
 * OJO al desplegar: el sync escribe estas columnas en cada corrida, así que tienen que existir
 * ANTES de subir el código (si no, el UPDATE falla y se corta el sync de limpieza entero).
 *
 * En installs frescos las crea init-db.php desde los schemas; este script las agrega a BDs ya
 * creadas. Portable (SQLite dev + MariaDB prod) e idempotente. Sin backfill: las llena el
 * próximo sync de Cloudbeds.
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

echo "Cantidad de huéspedes (Cloudbeds):\n";
$agregar('habitaciones', 'cb_huespedes', 'INTEGER', 'INT NULL');
$agregar('habitaciones', 'cb_huespedes_llegan', 'INTEGER', 'INT NULL');

echo "Migración completa.\n";
