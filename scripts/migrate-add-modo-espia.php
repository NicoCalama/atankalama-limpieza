<?php

declare(strict_types=1);

/**
 * Migración: agrega sesiones.espia_objetivo_id (modo espía) a una BD existente.
 *
 * Permite que un admin vea la app como la vería otro usuario, en solo lectura
 * (docs/contexto — feature "modo espía" en /usuarios). En installs frescos la
 * columna la crea init-db.php desde los schemas; este script la agrega a BDs
 * ya creadas.
 *
 * Portable (SQLite dev + MariaDB prod) e idempotente: seguro de correr múltiples veces.
 * Sin backfill: la columna nace en NULL para toda sesión existente (ninguna estaba
 * en modo espía antes de que la feature existiera).
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
$tabla   = Database::tabla('sesiones');
$pdo     = Database::pdo();

// ¿Ya existe la columna? (chequeo portable)
if ($esMaria) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$tabla, 'espia_objetivo_id']);
    $existe = (int) $stmt->fetchColumn() > 0;
} else {
    $cols = $pdo->query('PRAGMA table_info(' . $tabla . ')')->fetchAll(\PDO::FETCH_ASSOC);
    $existe = in_array('espia_objetivo_id', array_column($cols, 'name'), true);
}

if ($existe) {
    echo "La columna {$tabla}.espia_objetivo_id ya existe — omito ALTER.\n";
} else {
    // La FK a usuarios(id) va en los schemas (installs frescos). Acá agregamos solo la
    // columna nullable, igual que otras migraciones de este mismo tipo en este proyecto.
    $tipo = $esMaria ? 'INT NULL' : 'INTEGER';
    $pdo->exec("ALTER TABLE {$tabla} ADD COLUMN espia_objetivo_id {$tipo}");
    echo "Columna {$tabla}.espia_objetivo_id agregada.\n";
}

echo "Migración completa.\n";
