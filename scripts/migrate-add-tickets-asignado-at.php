<?php

declare(strict_types=1);

/**
 * Migración: agrega tickets.asignado_at a una BD existente.
 *
 * Necesario para poder calcular "cuánto se demoró en resolver" la tarea de la persona
 * ASIGNADA (asignado_at → resuelto_at), separado del tiempo total del problema
 * (created_at → resuelto_at) — un ticket puede quedar días sin asignar antes de que
 * alguien lo tome, y ese tiempo no debe contar como demora de quien lo resolvió.
 * Ver conversación de soporte — feature "ver mis tickets asignados" + reportes futuros.
 *
 * No se guarda la duración calculada (evita que quede desincronizada si se edita algo
 * después) — se resta en SQL al armar el reporte, cuando exista.
 *
 * En installs frescos la columna la crea init-db.php desde los schemas; este script la
 * agrega a BDs ya creadas. Portable (SQLite dev + MariaDB prod) e idempotente: seguro de
 * correr varias veces.
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
$tabla   = Database::tabla('tickets');

// ¿Ya existe la columna? (chequeo portable)
if ($esMaria) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$tabla, 'asignado_at']);
    $existe = (int) $stmt->fetchColumn() > 0;
} else {
    $cols = $pdo->query('PRAGMA table_info(' . $tabla . ')')->fetchAll(\PDO::FETCH_ASSOC);
    $existe = in_array('asignado_at', array_column($cols, 'name'), true);
}

if ($existe) {
    echo "La columna {$tabla}.asignado_at ya existe — omito ALTER.\n";
} else {
    $tipo = $esMaria ? 'VARCHAR(30) NULL' : 'TEXT';
    $pdo->exec("ALTER TABLE {$tabla} ADD COLUMN asignado_at {$tipo}");
    echo "Columna {$tabla}.asignado_at agregada.\n";
}

// Backfill: tickets que YA tienen asignado_a (de antes de este cambio) no tienen forma de
// saber cuándo se asignaron de verdad — se usa updated_at como aproximación razonable
// (asignar() siempre lo actualiza), mejor que dejarlos NULL para los reportes.
$afectados = Database::execute(
    'UPDATE #__tickets SET asignado_at = updated_at WHERE asignado_a IS NOT NULL AND asignado_at IS NULL'
);
echo "Backfill: {$afectados} ticket(s) ya asignados, asignado_at aproximado con updated_at.\n";
echo "Migración completa.\n";
