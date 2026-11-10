<?php

declare(strict_types=1);

/**
 * Migración v7.22: supervisores de cada persona y aviso a mantención (reunión del 08/11/2026;
 * decisiones de Nicolás, 10/11/2026).
 *
 * 1. Crea la tabla `usuarios_supervisores` (persona → supervisor, uno o varios).
 * 2. Agrega el permiso `tickets.aviso_mantencion` y se lo da al rol «Mantenimiento» si existe (en
 *    producción, las 7 personas de mantención), y `usuarios.ser_supervisor` a Admin y Supervisora (las
 *    jefaturas de hoy; Nicolás, 10/11/2026). Si un rol no existe, se da desde Ajustes → Roles y Permisos.
 * 3. Solo si la tabla está vacía (la primera vez): cada persona activa con rol Aseo queda supervisada
 *    por cada persona activa con rol Supervisora (pedido de Nicolás). Re-correrla no vuelve a agregar
 *    a nadie que se haya quitado a mano.
 *
 * Asignar por nombre de rol es solo para estos datos iniciales: el código nunca mira nombres de rol.
 * Portable (SQLite dev + MariaDB prod) e idempotente. En producción el mismo SQL va para phpMyAdmin en
 * docs/deploy-cpanel.md §11.55.
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

// ── 1. Tabla usuarios_supervisores ───────────────────────────────────────────
$tSup      = Database::tabla('usuarios_supervisores');
$tUsuarios = Database::tabla('usuarios');
if ($esMaria) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$tSup}` (
        usuario_id      INT NOT NULL,
        supervisor_id   INT NOT NULL,
        created_at      VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
        PRIMARY KEY (usuario_id, supervisor_id),
        KEY idx_usuarios_supervisores_supervisor (supervisor_id),
        FOREIGN KEY (usuario_id) REFERENCES `{$tUsuarios}`(id) ON DELETE CASCADE,
        FOREIGN KEY (supervisor_id) REFERENCES `{$tUsuarios}`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$tSup} (
        usuario_id      INTEGER NOT NULL,
        supervisor_id   INTEGER NOT NULL,
        created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
        PRIMARY KEY (usuario_id, supervisor_id),
        FOREIGN KEY (usuario_id) REFERENCES {$tUsuarios}(id) ON DELETE CASCADE,
        FOREIGN KEY (supervisor_id) REFERENCES {$tUsuarios}(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_usuarios_supervisores_supervisor ON {$tSup}(supervisor_id)");
}
echo "Tabla {$tSup} lista.\n";

// ── 2. Permisos: aviso a mantención → Mantenimiento; ser supervisor → Admin y Supervisora ──
$permisosNuevos = [
    'tickets.aviso_mantencion' => ['Mantenimiento'],
    'usuarios.ser_supervisor'  => ['Admin', 'Supervisora'],
];
foreach (require __DIR__ . '/../database/seeds/permisos.php' as [$codigo, $descripcion, $categoria, $scope]) {
    if (isset($permisosNuevos[$codigo])) {
        Database::execute(
            'INSERT OR IGNORE INTO #__permisos (codigo, descripcion, categoria, scope) VALUES (?, ?, ?, ?)',
            [$codigo, $descripcion, $categoria, $scope]
        );
    }
}
foreach ($permisosNuevos as $permiso => $nombresRol) {
    foreach ($nombresRol as $nombreRol) {
        $rol = Database::fetchOne('SELECT id FROM #__roles WHERE nombre = ?', [$nombreRol]);
        if ($rol === null) {
            echo "No existe el rol {$nombreRol}: da {$permiso} desde Ajustes → Roles y Permisos a quien corresponda.\n";
        } elseif (Database::fetchOne('SELECT 1 FROM #__rol_permisos WHERE rol_id = ? AND permiso_codigo = ?', [(int) $rol['id'], $permiso]) === null) {
            Database::execute('INSERT INTO #__rol_permisos (rol_id, permiso_codigo) VALUES (?, ?)', [(int) $rol['id'], $permiso]);
            echo "{$permiso} concedido al rol {$nombreRol}.\n";
        } else {
            echo "El rol {$nombreRol} ya tenía {$permiso}.\n";
        }
    }
}

// ── 3. Supervisoras de Aseo (solo la primera vez) ───────────────────────────
if ((int) Database::fetchColumn('SELECT COUNT(*) FROM #__usuarios_supervisores') > 0) {
    echo "La tabla de supervisores ya tenía datos: no se precarga nada.\n";
} else {
    $pares = Database::fetchAll(
        "SELECT DISTINCT a.id AS usuario_id, s.id AS supervisor_id
           FROM #__usuarios a
           JOIN #__usuarios_roles ura ON ura.usuario_id = a.id
           JOIN #__roles ra ON ra.id = ura.rol_id AND ra.nombre = 'Aseo'
           JOIN #__usuarios s ON s.activo = 1 AND s.id <> a.id
           JOIN #__usuarios_roles urs ON urs.usuario_id = s.id
           JOIN #__roles rs ON rs.id = urs.rol_id AND rs.nombre = 'Supervisora'
          WHERE a.activo = 1
          ORDER BY a.id, s.id"
    );
    foreach ($pares as $p) {
        Database::execute(
            'INSERT INTO #__usuarios_supervisores (usuario_id, supervisor_id) VALUES (?, ?)',
            [(int) $p['usuario_id'], (int) $p['supervisor_id']]
        );
    }
    $personas = count(array_unique(array_column($pares, 'usuario_id')));
    echo "Supervisoras precargadas: {$personas} persona(s) de Aseo, " . count($pares) . " par(es).\n";
}

echo "Migración completa. Verifica con: php scripts/verificar-esquema.php\n";
