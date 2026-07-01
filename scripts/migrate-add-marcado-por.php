<?php

declare(strict_types=1);

/**
 * Migración: agrega ejecuciones_items.marcado_por (quién marcó cada ítem) a una BD existente.
 *
 * Necesario para el rework de créditos (docs/creditos-rework.md): repartir los créditos por
 * persona cuando una pieza pasa por varias manos (rechazo → re-limpieza). En installs frescos
 * la columna la crea init-db.php desde los schemas; este script la agrega a BDs ya creadas.
 *
 * Portable (SQLite dev + MariaDB prod) e idempotente: seguro de correr múltiples veces.
 * Backfill: para los ítems ya marcados sin atribución, marcado_por = usuario dueño de la ejecución.
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$driver  = Database::driver();
$esMaria = $driver === 'mysql' || $driver === 'mariadb';
$tabla   = Database::tabla('ejecuciones_items');
$pdo     = Database::pdo();

// ¿Ya existe la columna? (chequeo portable)
if ($esMaria) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$tabla, 'marcado_por']);
    $existe = (int) $stmt->fetchColumn() > 0;
} else {
    $cols = $pdo->query('PRAGMA table_info(' . $tabla . ')')->fetchAll(\PDO::FETCH_ASSOC);
    $existe = in_array('marcado_por', array_column($cols, 'name'), true);
}

if ($existe) {
    echo "La columna {$tabla}.marcado_por ya existe — omito ALTER.\n";
} else {
    // La FK a usuarios(id) va en los schemas (installs frescos). Acá agregamos solo la columna
    // nullable (agregar la FK sobre datos existentes es innecesario y más frágil).
    $tipo = $esMaria ? 'INT NULL' : 'INTEGER';
    $pdo->exec("ALTER TABLE {$tabla} ADD COLUMN marcado_por {$tipo}");
    echo "Columna {$tabla}.marcado_por agregada.\n";
}

// Backfill: ítems ya marcados sin atribución → el dueño de la ejecución. Idempotente
// (solo toca marcado=1 AND marcado_por IS NULL). Subconsulta correlacionada portable.
$afectados = Database::execute(
    'UPDATE #__ejecuciones_items
        SET marcado_por = (
            SELECT ec.usuario_id FROM #__ejecuciones_checklist ec
             WHERE ec.id = #__ejecuciones_items.ejecucion_id
        )
      WHERE marcado = 1 AND marcado_por IS NULL'
);
echo "Backfill: {$afectados} ítems marcados atribuidos a su ejecución.\n";
echo "Migración completa.\n";
