<?php

declare(strict_types=1);

/**
 * Migración v7.27: áreas comunes beta (propuesta de Nicolás, decisiones del 11/11/2026).
 *
 * 1. Agrega `habitaciones.area_beta` (toda área existente queda alfa, como hoy).
 * 2. Crea el catálogo `tareas_catalogo` (parte vacío), `espacio_tareas` y `extras_areas_comunes`.
 * 3. Agrega los permisos: `espacios.agregar_extra`, `tareas.crear` y `tareas.ver` a Admin y Supervisora,
 *    `tareas.editar` y `espacios.editar_m2` solo a Admin.
 *
 * Asignar por nombre de rol es solo para estos datos iniciales: el código nunca mira nombres de rol.
 * Portable (SQLite dev + MariaDB prod) e idempotente. En producción el mismo SQL va para phpMyAdmin en
 * docs/deploy-cpanel.md §11.60.
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

$tHab      = Database::tabla('habitaciones');
$tUsuarios = Database::tabla('usuarios');
$tEjec     = Database::tabla('ejecuciones_checklist');
$tTareas   = Database::tabla('tareas_catalogo');
$tEspTar   = Database::tabla('espacio_tareas');
$tExtras   = Database::tabla('extras_areas_comunes');

// ── 1. habitaciones.area_beta ────────────────────────────────────────────────
$existe = $esMaria
    ? Database::fetchOne(
        'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        [$tHab, 'area_beta']
    ) !== null
    : in_array('area_beta', array_column(Database::fetchAll("PRAGMA table_info({$tHab})"), 'name'), true);
if ($existe) {
    echo "habitaciones.area_beta ya existía.\n";
} else {
    $tipo = $esMaria ? 'TINYINT' : 'INTEGER';
    $pdo->exec("ALTER TABLE {$tHab} ADD COLUMN area_beta {$tipo} NOT NULL DEFAULT 0 CHECK (area_beta IN (0, 1))");
    echo "habitaciones.area_beta agregada.\n";
}

// ── 2. Tablas ────────────────────────────────────────────────────────────────
if ($esMaria) {
    $ahora = "(CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z'))";
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$tTareas}` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(60) NOT NULL,
        nombre_busqueda VARCHAR(60) NOT NULL UNIQUE,
        creditos_m2 DECIMAL(7,3) NOT NULL CHECK (creditos_m2 > 0),
        activa TINYINT NOT NULL DEFAULT 1 CHECK (activa IN (0, 1)),
        creado_por INT NULL,
        created_at VARCHAR(30) NOT NULL DEFAULT {$ahora},
        updated_at VARCHAR(30) NOT NULL DEFAULT {$ahora},
        FOREIGN KEY (creado_por) REFERENCES `{$tUsuarios}`(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$tEspTar}` (
        habitacion_id INT NOT NULL,
        tarea_id INT NOT NULL,
        m2 DECIMAL(8,1) NOT NULL CHECK (m2 > 0),
        tipo VARCHAR(5) NOT NULL DEFAULT 'base' CHECK (tipo IN ('base', 'extra')),
        orden INT NOT NULL DEFAULT 0,
        created_at VARCHAR(30) NOT NULL DEFAULT {$ahora},
        PRIMARY KEY (habitacion_id, tarea_id),
        KEY idx_espacio_tareas_tarea (tarea_id),
        FOREIGN KEY (habitacion_id) REFERENCES `{$tHab}`(id) ON DELETE CASCADE,
        FOREIGN KEY (tarea_id) REFERENCES `{$tTareas}`(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$tExtras}` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        habitacion_id INT NOT NULL,
        fecha VARCHAR(10) NOT NULL,
        ejecucion_id INT NULL,
        tarea_id INT NOT NULL,
        m2 DECIMAL(8,1) NOT NULL CHECK (m2 > 0),
        creditos INT NOT NULL CHECK (creditos >= 0),
        marcado TINYINT NOT NULL DEFAULT 0 CHECK (marcado IN (0, 1)),
        marcado_por INT NULL,
        desmarcado_por_auditor TINYINT NOT NULL DEFAULT 0 CHECK (desmarcado_por_auditor IN (0, 1)),
        agregado_por INT NULL,
        created_at VARCHAR(30) NOT NULL DEFAULT {$ahora},
        updated_at VARCHAR(30) NOT NULL DEFAULT {$ahora},
        KEY idx_extras_areas_area_fecha (habitacion_id, fecha),
        KEY idx_extras_areas_ejecucion (ejecucion_id),
        FOREIGN KEY (habitacion_id) REFERENCES `{$tHab}`(id) ON DELETE CASCADE,
        FOREIGN KEY (ejecucion_id) REFERENCES `{$tEjec}`(id) ON DELETE SET NULL,
        FOREIGN KEY (tarea_id) REFERENCES `{$tTareas}`(id) ON DELETE RESTRICT,
        FOREIGN KEY (marcado_por) REFERENCES `{$tUsuarios}`(id) ON DELETE SET NULL,
        FOREIGN KEY (agregado_por) REFERENCES `{$tUsuarios}`(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} else {
    $ahora = "(strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))";
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$tTareas} (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre TEXT NOT NULL,
        nombre_busqueda TEXT NOT NULL UNIQUE,
        creditos_m2 REAL NOT NULL CHECK (creditos_m2 > 0),
        activa INTEGER NOT NULL DEFAULT 1 CHECK (activa IN (0, 1)),
        creado_por INTEGER,
        created_at TEXT NOT NULL DEFAULT {$ahora},
        updated_at TEXT NOT NULL DEFAULT {$ahora},
        FOREIGN KEY (creado_por) REFERENCES {$tUsuarios}(id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$tEspTar} (
        habitacion_id INTEGER NOT NULL,
        tarea_id INTEGER NOT NULL,
        m2 REAL NOT NULL CHECK (m2 > 0),
        tipo TEXT NOT NULL DEFAULT 'base' CHECK (tipo IN ('base', 'extra')),
        orden INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT {$ahora},
        PRIMARY KEY (habitacion_id, tarea_id),
        FOREIGN KEY (habitacion_id) REFERENCES {$tHab}(id) ON DELETE CASCADE,
        FOREIGN KEY (tarea_id) REFERENCES {$tTareas}(id) ON DELETE RESTRICT
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_espacio_tareas_tarea ON {$tEspTar}(tarea_id)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$tExtras} (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        habitacion_id INTEGER NOT NULL,
        fecha TEXT NOT NULL,
        ejecucion_id INTEGER,
        tarea_id INTEGER NOT NULL,
        m2 REAL NOT NULL CHECK (m2 > 0),
        creditos INTEGER NOT NULL CHECK (creditos >= 0),
        marcado INTEGER NOT NULL DEFAULT 0 CHECK (marcado IN (0, 1)),
        marcado_por INTEGER,
        desmarcado_por_auditor INTEGER NOT NULL DEFAULT 0 CHECK (desmarcado_por_auditor IN (0, 1)),
        agregado_por INTEGER,
        created_at TEXT NOT NULL DEFAULT {$ahora},
        updated_at TEXT NOT NULL DEFAULT {$ahora},
        FOREIGN KEY (habitacion_id) REFERENCES {$tHab}(id) ON DELETE CASCADE,
        FOREIGN KEY (ejecucion_id) REFERENCES {$tEjec}(id) ON DELETE SET NULL,
        FOREIGN KEY (tarea_id) REFERENCES {$tTareas}(id) ON DELETE RESTRICT,
        FOREIGN KEY (marcado_por) REFERENCES {$tUsuarios}(id) ON DELETE SET NULL,
        FOREIGN KEY (agregado_por) REFERENCES {$tUsuarios}(id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_extras_areas_area_fecha ON {$tExtras}(habitacion_id, fecha)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_extras_areas_ejecucion ON {$tExtras}(ejecucion_id)");
}
echo "Tablas {$tTareas}, {$tEspTar} y {$tExtras} listas.\n";

// ── 3. Permisos ──────────────────────────────────────────────────────────────
$permisosNuevos = [
    'espacios.agregar_extra' => ['Admin', 'Supervisora'],
    'espacios.editar_m2'     => ['Admin'],
    'tareas.crear'           => ['Admin', 'Supervisora'],
    'tareas.ver'             => ['Admin', 'Supervisora'],
    'tareas.editar'          => ['Admin'],
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

echo "Migración completa. Verifica con: php scripts/verificar-esquema.php\n";
