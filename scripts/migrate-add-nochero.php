<?php

declare(strict_types=1);

/**
 * Migración: agrega el soporte de "nochero" a una BD existente.
 *   - habitaciones.es_nochero               (1 = pieza con huésped de turno día Y turno noche, hotel minero)
 *   - habitaciones.nochero_hasta            (último día vigente, 'YYYY-MM-DD')
 *   - habitaciones.nochero_ultima_reversion (último día en que el cron ya la revirtió a sucia)
 *
 * Ver docs/nocheros.md. En installs frescos estas columnas las crea init-db.php desde los
 * schemas; este script las agrega a BDs ya creadas.
 *
 * Portable (SQLite dev + MariaDB prod) e idempotente: seguro de correr múltiples veces. No necesita
 * backfill: el valor por defecto (es_nochero=0, nochero_hasta NULL) ya describe las filas existentes.
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

$tablaHab = Database::tabla('habitaciones');

// --- habitaciones.es_nochero ---
if ($columnaExiste($tablaHab, 'es_nochero')) {
    echo "La columna {$tablaHab}.es_nochero ya existe — omito ALTER.\n";
} else {
    $tipo = $esMaria
        ? 'TINYINT NOT NULL DEFAULT 0'
        : 'INTEGER NOT NULL DEFAULT 0';
    $pdo->exec("ALTER TABLE {$tablaHab} ADD COLUMN es_nochero {$tipo}");
    echo "Columna {$tablaHab}.es_nochero agregada.\n";
}

// --- habitaciones.nochero_hasta ---
if ($columnaExiste($tablaHab, 'nochero_hasta')) {
    echo "La columna {$tablaHab}.nochero_hasta ya existe — omito ALTER.\n";
} else {
    $tipo = $esMaria ? 'VARCHAR(10)' : 'TEXT';
    $pdo->exec("ALTER TABLE {$tablaHab} ADD COLUMN nochero_hasta {$tipo}");
    echo "Columna {$tablaHab}.nochero_hasta agregada.\n";
}

// --- habitaciones.nochero_ultima_reversion ---
if ($columnaExiste($tablaHab, 'nochero_ultima_reversion')) {
    echo "La columna {$tablaHab}.nochero_ultima_reversion ya existe — omito ALTER.\n";
} else {
    $tipo = $esMaria ? 'VARCHAR(10)' : 'TEXT';
    $pdo->exec("ALTER TABLE {$tablaHab} ADD COLUMN nochero_ultima_reversion {$tipo}");
    echo "Columna {$tablaHab}.nochero_ultima_reversion agregada.\n";
}

// --- índice ---
try {
    $pdo->exec("CREATE INDEX idx_habitaciones_nochero ON {$tablaHab}(es_nochero)");
    echo "Índice idx_habitaciones_nochero creado.\n";
} catch (\PDOException $e) {
    // El índice puede existir ya de una corrida previa; no es fatal.
    echo "  (índice idx_habitaciones_nochero no creado: " . $e->getMessage() . ")\n";
}

echo "Migración completa.\n";
