<?php

declare(strict_types=1);

/**
 * Migración (v7.7): checklist adaptativo al cambio de sábanas.
 *
 *   ejecuciones_checklist: con_cambio_sabanas (NOT NULL DEFAULT 1)
 *
 * Al empezar una limpieza la app decide si lleva cambio de sábanas (huésped que sigue cada N noches,
 * huésped que se va hoy, o turnover) y lo anota acá. Si no lleva, los ítems marcados «Sábanas» en el
 * checklist no se muestran, no se exigen para terminar ni dan créditos. Las limpiezas que ya existen
 * quedan en 1 (con todos sus ítems, como se hicieron), así que el historial y los KPIs pasados no
 * cambian. Ver docs/checklist.md y docs/ocupacion-y-sabanas.md.
 *
 * En installs frescos esta columna la crea init-db.php desde los schemas; este script la
 * agrega a BDs ya creadas. Portable (SQLite dev + MariaDB prod) e idempotente.
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

echo "Checklist adaptativo al cambio de sábanas:\n";
$agregar(
    'ejecuciones_checklist',
    'con_cambio_sabanas',
    'INTEGER NOT NULL DEFAULT 1 CHECK (con_cambio_sabanas IN (0, 1))',
    'TINYINT NOT NULL DEFAULT 1 CHECK (con_cambio_sabanas IN (0, 1))'
);

echo "Migración completa.\n";
