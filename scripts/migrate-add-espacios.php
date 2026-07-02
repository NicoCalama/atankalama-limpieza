<?php

declare(strict_types=1);

/**
 * Migración: agrega el soporte de áreas comunes (espacios) a una BD existente.
 *   - habitaciones.es_espacio_comun  (1 = área común: piscina, pasillo… sin Cloudbeds ni auditoría)
 *   - checklists_template.habitacion_id  (template propio de un espacio; NULL en piezas de huésped)
 *
 * Ver docs/areas-comunes.md. En installs frescos estas columnas las crea init-db.php desde los
 * schemas; este script las agrega a BDs ya creadas.
 *
 * Portable (SQLite dev + MariaDB prod) e idempotente: seguro de correr múltiples veces. No necesita
 * backfill: los valores por defecto (es_espacio_comun=0, habitacion_id NULL) ya describen las filas
 * existentes (todas son piezas de huésped).
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$driver  = Database::driver();
$esMaria = $driver === 'mysql' || $driver === 'mariadb';
$pdo     = Database::pdo();

/**
 * ¿Existe la columna en la tabla? (chequeo portable SQLite/MariaDB)
 */
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

// --- habitaciones.es_espacio_comun ---
$tablaHab = Database::tabla('habitaciones');
if ($columnaExiste($tablaHab, 'es_espacio_comun')) {
    echo "La columna {$tablaHab}.es_espacio_comun ya existe — omito ALTER.\n";
} else {
    $tipo = $esMaria
        ? 'TINYINT NOT NULL DEFAULT 0'
        : 'INTEGER NOT NULL DEFAULT 0';
    $pdo->exec("ALTER TABLE {$tablaHab} ADD COLUMN es_espacio_comun {$tipo}");
    echo "Columna {$tablaHab}.es_espacio_comun agregada.\n";
}

// --- checklists_template.habitacion_id ---
$tablaTpl = Database::tabla('checklists_template');
if ($columnaExiste($tablaTpl, 'habitacion_id')) {
    echo "La columna {$tablaTpl}.habitacion_id ya existe — omito ALTER.\n";
} else {
    // Nullable; la FK a habitaciones(id) va en los schemas (installs frescos). Agregar la FK sobre
    // datos existentes es innecesario y más frágil, así que acá solo agregamos la columna + índice.
    $tipo = $esMaria ? 'INT NULL' : 'INTEGER';
    $pdo->exec("ALTER TABLE {$tablaTpl} ADD COLUMN habitacion_id {$tipo}");
    try {
        $pdo->exec("CREATE INDEX idx_checklists_template_habitacion ON {$tablaTpl}(habitacion_id)");
    } catch (\PDOException $e) {
        // El índice puede existir ya de una corrida previa; no es fatal.
        echo "  (índice idx_checklists_template_habitacion no creado: " . $e->getMessage() . ")\n";
    }
    echo "Columna {$tablaTpl}.habitacion_id agregada.\n";
}

echo "Migración completa.\n";
