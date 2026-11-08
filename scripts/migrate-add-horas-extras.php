<?php

declare(strict_types=1);

/**
 * Migración v7.17: «Horas extras» (pedido de gerencia, 06/11/2026; decisiones de Nicolás, 08/11/2026).
 *
 * 1. Crea la tabla `horas_extras` (total de horas extras del mes de una persona de Aseo).
 * 2. Agrega el permiso `horas_extras.registrar` y se lo da a todo rol que hoy edita el corte del bono
 *    (`reportes.editar_corte`: el Admin).
 *
 * Portable (SQLite dev + MariaDB prod) e idempotente: re-correrlo no cambia nada.
 * En producción el mismo SQL va para phpMyAdmin en docs/deploy-cpanel.md §11.49.
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

// ── 1. Tabla horas_extras ────────────────────────────────────────────────────
$tExtras   = Database::tabla('horas_extras');
$tUsuarios = Database::tabla('usuarios');
if ($esMaria) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$tExtras}` (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id      INT NOT NULL,
        mes             VARCHAR(7) NOT NULL,
        minutos         INT NOT NULL CHECK (minutos > 0 AND minutos <= 6000 AND minutos % 30 = 0),
        registrado_por  INT NULL,
        created_at      VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
        updated_at      VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
        UNIQUE KEY uq_horas_extras (usuario_id, mes),
        KEY idx_horas_extras_mes (mes),
        FOREIGN KEY (usuario_id) REFERENCES `{$tUsuarios}`(id) ON DELETE CASCADE,
        FOREIGN KEY (registrado_por) REFERENCES `{$tUsuarios}`(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$tExtras} (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario_id      INTEGER NOT NULL,
        mes             TEXT NOT NULL,
        minutos         INTEGER NOT NULL CHECK (minutos > 0 AND minutos <= 6000 AND minutos % 30 = 0),
        registrado_por  INTEGER,
        created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
        updated_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
        UNIQUE (usuario_id, mes),
        FOREIGN KEY (usuario_id) REFERENCES {$tUsuarios}(id) ON DELETE CASCADE,
        FOREIGN KEY (registrado_por) REFERENCES {$tUsuarios}(id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_horas_extras_mes ON {$tExtras}(mes)");
}
echo "Tabla {$tExtras} lista.\n";

// ── 2. Permiso nuevo para quien edita el corte del bono ──────────────────────
$permiso = 'horas_extras.registrar';
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
    ['reportes.editar_corte']
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
