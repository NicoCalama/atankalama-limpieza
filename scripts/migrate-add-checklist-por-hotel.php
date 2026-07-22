<?php

declare(strict_types=1);

/**
 * Migración: soporte de checklists por hotel (override opcional del checklist de un tipo).
 *   - checklists_template.hotel_id  (NULL = checklist compartido del tipo; != NULL = override para ese hotel)
 *   - cloudbeds_config['tipos_checklist_por_hotel'] = '0'  (flag del toggle Ajustes → Checklists)
 *
 * Ver docs/checklist.md. En installs frescos la columna la crea init-db.php desde los schemas;
 * este script la agrega a BDs ya creadas.
 *
 * Portable (SQLite dev + MariaDB prod) e idempotente: seguro de correr múltiples veces. No necesita
 * backfill: el default (hotel_id NULL) ya describe las filas existentes (todos los checklists de
 * tipo son compartidos hasta que la supervisora cree un override).
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

// --- checklists_template.hotel_id ---
$tablaTpl = Database::tabla('checklists_template');
if ($columnaExiste($tablaTpl, 'hotel_id')) {
    echo "La columna {$tablaTpl}.hotel_id ya existe — omito ALTER.\n";
} else {
    // Nullable; la FK a hoteles(id) va en los schemas (installs frescos). Agregar la FK sobre datos
    // existentes es innecesario y más frágil, así que acá solo agregamos la columna + índice.
    $tipo = $esMaria ? 'INT NULL' : 'INTEGER';
    $pdo->exec("ALTER TABLE {$tablaTpl} ADD COLUMN hotel_id {$tipo}");
    try {
        $pdo->exec("CREATE INDEX idx_checklists_template_tipo_hotel ON {$tablaTpl}(tipo_habitacion_id, hotel_id)");
    } catch (\PDOException $e) {
        echo "  (índice idx_checklists_template_tipo_hotel no creado: " . $e->getMessage() . ")\n";
    }
    echo "Columna {$tablaTpl}.hotel_id agregada.\n";
}

// --- flag del toggle en cloudbeds_config (bolsa de config operativa) ---
$flag = 'tipos_checklist_por_hotel';
$existe = Database::fetchOne('SELECT clave FROM #__cloudbeds_config WHERE clave = ?', [$flag]);
if ($existe !== null) {
    echo "El flag {$flag} ya existe en cloudbeds_config — no lo toco.\n";
} else {
    Database::execute(
        'INSERT INTO #__cloudbeds_config (clave, valor, descripcion) VALUES (?, ?, ?)',
        [$flag, '0', 'Separar los checklists de tipo por hotel (override por propiedad)']
    );
    echo "Flag {$flag} = 0 agregado a cloudbeds_config.\n";
}

echo "Migración completa.\n";
