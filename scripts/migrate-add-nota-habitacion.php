<?php

declare(strict_types=1);

/**
 * Migración: agrega la nota de Recepción para la mucama a una BD existente.
 *   - habitaciones.nota_recepcion           (texto libre, NULL = sin nota activa)
 *   - habitaciones.nota_recepcion_autor_id  (quién la dejó)
 *   - habitaciones.nota_recepcion_at        (cuándo, ISO UTC)
 *
 * Pedido por Recepción (2026-09-11): instrucción puntual para la próxima limpieza de una
 * pieza (ej. "cliente pidió cama extra"). Se autolimpia al completar la ejecución
 * (ver ChecklistService) — no es un historial, una nota activa por habitación.
 *
 * En installs frescos estas columnas las crea init-db.php desde los schemas; este script
 * las agrega a BDs ya creadas. Portable (SQLite dev + MariaDB prod) e idempotente: seguro
 * de correr múltiples veces. No necesita backfill (NULL = sin nota describe bien las
 * habitaciones existentes).
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$driver  = Database::driver();
$esMaria = $driver === 'mysql' || $driver === 'mariadb';
$pdo     = Database::pdo();
$tabla   = Database::tabla('habitaciones');

/**
 * ¿Existe la columna en la tabla? (chequeo portable SQLite/MariaDB)
 */
$columnaExiste = static function (string $columna) use ($pdo, $esMaria, $tabla): bool {
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

// --- habitaciones.nota_recepcion ---
if ($columnaExiste('nota_recepcion')) {
    echo "La columna {$tabla}.nota_recepcion ya existe — omito ALTER.\n";
} else {
    $pdo->exec("ALTER TABLE {$tabla} ADD COLUMN nota_recepcion TEXT NULL");
    echo "Columna {$tabla}.nota_recepcion agregada.\n";
}

// --- habitaciones.nota_recepcion_autor_id ---
if ($columnaExiste('nota_recepcion_autor_id')) {
    echo "La columna {$tabla}.nota_recepcion_autor_id ya existe — omito ALTER.\n";
} else {
    // La FK a usuarios(id) va en los schemas (installs frescos). Acá agregamos solo la
    // columna nullable, mismo criterio que migrate-add-marcado-por.php.
    $tipo = $esMaria ? 'INT NULL' : 'INTEGER';
    $pdo->exec("ALTER TABLE {$tabla} ADD COLUMN nota_recepcion_autor_id {$tipo}");
    echo "Columna {$tabla}.nota_recepcion_autor_id agregada.\n";
}

// --- habitaciones.nota_recepcion_at ---
if ($columnaExiste('nota_recepcion_at')) {
    echo "La columna {$tabla}.nota_recepcion_at ya existe — omito ALTER.\n";
} else {
    $tipo = $esMaria ? 'VARCHAR(30) NULL' : 'TEXT';
    $pdo->exec("ALTER TABLE {$tabla} ADD COLUMN nota_recepcion_at {$tipo}");
    echo "Columna {$tabla}.nota_recepcion_at agregada.\n";
}

echo "Migración completa.\n";
