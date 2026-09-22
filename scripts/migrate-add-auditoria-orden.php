<?php

declare(strict_types=1);

/**
 * Migración: agrega el orden manual de la bandeja de auditoría a una BD existente.
 *   - habitaciones.auditoria_orden (INT NULL) — posición explícita que la supervisora
 *     le da a una pieza al arrastrarla en /auditoria. NULL = todavía no la tocó nadie
 *     a mano, cae en la prioridad automática (nochero → se va hoy → hotel/número).
 *     Ver AuditoriaService::bandejaPendientes()/reordenarBandeja().
 *
 * En installs frescos esta columna la crea init-db.php desde los schemas; este script
 * la agrega a BDs ya creadas. Portable (SQLite dev + MariaDB prod) e idempotente: seguro
 * de correr múltiples veces. No necesita backfill: NULL en todas las filas existentes ya
 * describe "sin orden manual todavía".
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

// --- habitaciones.auditoria_orden ---
if ($columnaExiste($tablaHab, 'auditoria_orden')) {
    echo "La columna {$tablaHab}.auditoria_orden ya existe — omito ALTER.\n";
} else {
    $tipo = $esMaria ? 'INT NULL' : 'INTEGER';
    $pdo->exec("ALTER TABLE {$tablaHab} ADD COLUMN auditoria_orden {$tipo}");
    echo "Columna {$tablaHab}.auditoria_orden agregada.\n";
}

// --- índice ---
try {
    $pdo->exec("CREATE INDEX idx_habitaciones_auditoria_orden ON {$tablaHab}(auditoria_orden)");
    echo "Índice idx_habitaciones_auditoria_orden creado.\n";
} catch (\PDOException $e) {
    // El índice puede existir ya de una corrida previa; no es fatal.
    echo "  (índice idx_habitaciones_auditoria_orden no creado: " . $e->getMessage() . ")\n";
}

echo "Migración completa.\n";
