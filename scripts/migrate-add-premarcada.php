<?php

declare(strict_types=1);

/**
 * Migración v7.24: checklist premarcado (reunión del 08/11/2026; plan aprobado el 09/11).
 *
 * Agrega `ejecuciones_checklist.premarcada` (0/1, por defecto 0): marca las limpiezas que llegaron con el checklist ya
 * marcado (pieza Noche o Día/Noche empezada desde la hora de la tarde). Las de antes quedan en 0.
 *
 * Portable (SQLite dev + MariaDB prod) e idempotente. En producción el mismo SQL va para phpMyAdmin en
 * docs/deploy-cpanel.md §11.57.
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

$tabla = Database::tabla('ejecuciones_checklist');
if ($esMaria) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$tabla, 'premarcada']);
    $existe = (int) $stmt->fetchColumn() > 0;
} else {
    $cols = $pdo->query('PRAGMA table_info(' . $tabla . ')')->fetchAll(\PDO::FETCH_ASSOC);
    $existe = in_array('premarcada', array_column($cols, 'name'), true);
}
if ($existe) {
    echo "La columna {$tabla}.premarcada ya existe — omito ALTER.\n";
} else {
    $tipo = $esMaria ? 'TINYINT' : 'INTEGER';
    $pdo->exec("ALTER TABLE {$tabla} ADD COLUMN premarcada {$tipo} NOT NULL DEFAULT 0 CHECK (premarcada IN (0, 1))");
    echo "Columna {$tabla}.premarcada agregada.\n";
}

echo "Migración completa. Verifica con: php scripts/verificar-esquema.php\n";
