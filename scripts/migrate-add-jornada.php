<?php

declare(strict_types=1);

/**
 * Migración: agrega usuarios.jornada (tiempo completo / tiempo parcial) a una BD existente.
 * Se asigna en Usuarios y se muestra junto al nombre en las tablas de trabajadores de Reportes,
 * para leer los KPIs (y los días trabajados) con ese contexto. Ver docs/usuarios.md y
 * docs/kpis-sueldos.md.
 *
 * En installs frescos la columna la crea init-db.php desde los schemas; este script la agrega a BDs
 * ya creadas. Portable (SQLite dev + MariaDB prod) e idempotente: seguro de correr múltiples veces.
 * No necesita backfill: NULL = «sin definir» describe a los usuarios existentes hasta que alguien
 * les asigne la jornada (decisión de Nicolás, 30/09/2026).
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
$tabla   = Database::tabla('usuarios');

if ($esMaria) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$tabla, 'jornada']);
    $existe = (int) $stmt->fetchColumn() > 0;
} else {
    $cols = $pdo->query('PRAGMA table_info(' . $tabla . ')')->fetchAll(\PDO::FETCH_ASSOC);
    $existe = in_array('jornada', array_column($cols, 'name'), true);
}

if ($existe) {
    echo "La columna {$tabla}.jornada ya existe — omito ALTER.\n";
} else {
    // Nullable; el CHECK (jornada IN ('completa','parcial')) va en los schemas frescos. Sobre datos
    // existentes agregamos solo la columna nullable (la validación real la hace UsuarioService),
    // igual que migrate-add-franja.php.
    $tipo = $esMaria ? 'VARCHAR(10) NULL' : 'TEXT';
    $pdo->exec("ALTER TABLE {$tabla} ADD COLUMN jornada {$tipo}");
    echo "Columna {$tabla}.jornada agregada.\n";
}

echo "Migración completa.\n";
