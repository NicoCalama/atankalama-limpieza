<?php

declare(strict_types=1);

/**
 * Migración: versionado de checklists (plan.md §8.6 — "versión activa + histórico de versiones").
 *
 * Agrega a checklists_template:
 *   - version     (INT, default 1)   número de versión dentro de la raíz
 *   - raiz_id     (INT, NULL)        agrupa todas las versiones de un mismo checklist
 *   - creado_por  (INT, NULL)        usuario que creó la versión
 *
 * Backfill: cada template existente queda como version = 1 y raiz_id = su propio id, es decir
 * "cada checklist actual es la v1 de su propia raíz". No mueve un solo dato de ejecuciones ni de
 * ítems, así que los reportes históricos dan EXACTAMENTE lo mismo que antes de correrla.
 *
 * A partir de acá ChecklistService::editarTemplate hace copy-on-write: en vez de mutar los ítems
 * in-place (lo que reescribía los KPIs de meses ya cerrados, porque ReportesService suma
 * items_checklist.creditos con un JOIN en vivo), inserta una versión nueva y da de baja la anterior.
 *
 * En installs frescos las columnas las crea init-db.php desde los schemas; este script las agrega a
 * BDs ya creadas. Portable (SQLite dev + MariaDB prod) e idempotente: seguro de correr varias veces.
 *
 * Las FK de creado_por y el índice de raiz_id van solo en los schemas frescos / vía CREATE INDEX;
 * SQLite no agrega FK por ALTER y no hace falta para la lógica.
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$driver  = Database::driver();
$esMaria = $driver === 'mysql' || $driver === 'mariadb';
$pdo     = Database::pdo();
$tabla   = Database::tabla('checklists_template');

/** @return list<string> nombres de columnas de la tabla */
$columnas = static function () use ($pdo, $esMaria, $tabla): array {
    if ($esMaria) {
        $stmt = $pdo->prepare(
            'SELECT column_name FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$tabla]);
        return array_map(static fn($f) => (string) $f, $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
    $cols = $pdo->query('PRAGMA table_info(' . $tabla . ')')->fetchAll(\PDO::FETCH_ASSOC);
    return array_column($cols, 'name');
};

$existentes = $columnas();

$aAgregar = [
    'version'    => $esMaria ? 'INT NOT NULL DEFAULT 1' : 'INTEGER NOT NULL DEFAULT 1',
    'raiz_id'    => $esMaria ? 'INT NULL' : 'INTEGER',
    'creado_por' => $esMaria ? 'INT NULL' : 'INTEGER',
];

foreach ($aAgregar as $col => $tipo) {
    if (in_array($col, $existentes, true)) {
        echo "La columna {$tabla}.{$col} ya existe — omito ALTER.\n";
        continue;
    }
    $pdo->exec("ALTER TABLE {$tabla} ADD COLUMN {$col} {$tipo}");
    echo "Columna {$tabla}.{$col} agregada.\n";
}

// Backfill: los templates previos al versionado son la v1 de su propia raíz. Idempotente: solo
// toca las filas que todavía no tienen raiz_id.
$filas = Database::execute("UPDATE {$tabla} SET raiz_id = id, version = 1 WHERE raiz_id IS NULL");
echo $filas > 0
    ? "Backfill: {$filas} template(s) marcados como v1 de su propia raíz.\n"
    : "Backfill: nada que hacer (todos los templates ya tienen raiz_id).\n";

// UNIQUE: dos guardados simultáneos del mismo checklist no pueden insertar la misma version
// (el segundo revienta y su transacción se deshace, en vez de dejar dos versiones vigentes).
$indice = 'idx_checklists_template_raiz_version';
try {
    $pdo->exec("CREATE UNIQUE INDEX {$indice} ON {$tabla}(raiz_id, version)");
    echo "Índice {$indice} creado.\n";
} catch (\PDOException $e) {
    $msg = $e->getMessage();
    // "ya existe" es el caso idempotente esperado; cualquier otra cosa es un problema real.
    if (stripos($msg, 'already exists') !== false || stripos($msg, 'Duplicate key name') !== false) {
        echo "El índice {$indice} ya existe — omito CREATE.\n";
    } else {
        echo "  ¡ATENCIÓN! No se pudo crear el índice {$indice}: {$msg}\n";
        echo "  Creálo a mano antes de usar el editor de checklists.\n";
    }
}

echo "Migración completa.\n";
