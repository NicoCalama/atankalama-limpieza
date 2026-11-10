<?php

declare(strict_types=1);

/**
 * Migración v7.23: chapa Día / Noche / Día-Noche y hora de la tarde (reunión del 08/11/2026; solo Admin cambia la
 * hora, decisión de Nicolás del 10/11/2026).
 *
 * 1. Agrega `habitaciones.nochero_modo` ('noche' | 'dia_noche', por defecto 'dia_noche'): las marcas de nochero que ya
 *    existen quedan como Día/Noche, que es exactamente lo que hacían.
 * 2. Agrega el permiso `habitaciones.configurar_hora_tarde` y se lo da a todo rol que administra la matriz
 *    (`permisos.asignar_a_rol`: el Admin; el '__ALL__' del seed solo se expande al sembrar).
 * La hora no se siembra: sin fila en alertas_config es 16:00, como antes.
 *
 * Portable (SQLite dev + MariaDB prod) e idempotente. En producción el mismo SQL va para phpMyAdmin en
 * docs/deploy-cpanel.md §11.56.
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

// ── 1. habitaciones.nochero_modo ─────────────────────────────────────────────
$tablaHab = Database::tabla('habitaciones');
if ($esMaria) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$tablaHab, 'nochero_modo']);
    $existe = (int) $stmt->fetchColumn() > 0;
} else {
    $cols = $pdo->query('PRAGMA table_info(' . $tablaHab . ')')->fetchAll(\PDO::FETCH_ASSOC);
    $existe = in_array('nochero_modo', array_column($cols, 'name'), true);
}
if ($existe) {
    echo "La columna {$tablaHab}.nochero_modo ya existe — omito ALTER.\n";
} else {
    $tipo = $esMaria ? 'VARCHAR(10)' : 'TEXT';
    $pdo->exec("ALTER TABLE {$tablaHab} ADD COLUMN nochero_modo {$tipo} NOT NULL DEFAULT 'dia_noche'
                CHECK (nochero_modo IN ('noche', 'dia_noche'))");
    echo "Columna {$tablaHab}.nochero_modo agregada (las marcas que ya había quedan Día/Noche).\n";
}

// ── 2. Permiso para cambiar la hora de la tarde → quien administra la matriz ─
$permiso = 'habitaciones.configurar_hora_tarde';
foreach (require __DIR__ . '/../database/seeds/permisos.php' as [$codigo, $descripcion, $categoria, $scope]) {
    if ($codigo === $permiso) {
        Database::execute(
            'INSERT OR IGNORE INTO #__permisos (codigo, descripcion, categoria, scope) VALUES (?, ?, ?, ?)',
            [$codigo, $descripcion, $categoria, $scope]
        );
    }
}
$roles = Database::fetchAll(
    'SELECT DISTINCT r.id, r.nombre FROM #__roles r
       JOIN #__rol_permisos rp ON rp.rol_id = r.id
      WHERE rp.permiso_codigo = ?
      ORDER BY r.id',
    ['permisos.asignar_a_rol']
);
foreach ($roles as $rol) {
    $rolId = (int) $rol['id'];
    if (Database::fetchOne('SELECT 1 FROM #__rol_permisos WHERE rol_id = ? AND permiso_codigo = ?', [$rolId, $permiso]) === null) {
        Database::execute('INSERT INTO #__rol_permisos (rol_id, permiso_codigo) VALUES (?, ?)', [$rolId, $permiso]);
        echo "{$permiso} concedido al rol {$rol['nombre']}.\n";
    } else {
        echo "El rol {$rol['nombre']} ya tenía {$permiso}.\n";
    }
}

echo "Migración completa. Verifica con: php scripts/verificar-esquema.php\n";
