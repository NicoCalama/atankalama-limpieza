<?php

declare(strict_types=1);

/**
 * Migración: apariencia configurable (Ajustes → Colores).
 *
 * 1. Crea la tabla #__ui_config (key-value, patrón alertas_config) si no existe.
 * 2. Siembra el permiso 'apariencia.editar' si falta (en installs frescos lo
 *    trae database/seeds/permisos.php).
 * 3. Se lo concede al rol Supervisora si aún no lo tiene (Admin ya tiene todo).
 *
 * Portable (SQLite dev + MariaDB prod) e idempotente: seguro de correr varias veces.
 * No inserta colores: sin filas, la app usa UiConfigService::DEFAULTS (la paleta
 * Tailwind actual), así que el deploy no cambia nada visual hasta que alguien edite.
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$driver  = Database::driver();
$esMaria = $driver === 'mysql' || $driver === 'mariadb';
$pdo     = Database::pdo();
$tabla   = Database::tabla('ui_config');
$tUsuarios = Database::tabla('usuarios');

// 1. Tabla ui_config
if ($esMaria) {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$tabla} (
            clave        VARCHAR(100) PRIMARY KEY,
            valor        TEXT NOT NULL,
            updated_at   VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
            updated_by   INT,
            FOREIGN KEY (updated_by) REFERENCES {$tUsuarios}(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} else {
    // FK con el MISMO prefijo que la tabla usuarios (en dev el prefijo es '', pero
    // no hardcodear 'usuarios' para no romper si algún día hay prefijo en SQLite).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$tabla} (
            clave        TEXT PRIMARY KEY,
            valor        TEXT NOT NULL,
            updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
            updated_by   INTEGER,
            FOREIGN KEY (updated_by) REFERENCES {$tUsuarios}(id) ON DELETE SET NULL
        )"
    );
}
echo "Tabla {$tabla} lista.\n";

// 2. Permiso apariencia.editar (permisos.codigo es la PK; columna de alcance = scope)
$permiso = Database::fetchOne('SELECT codigo FROM #__permisos WHERE codigo = ?', ['apariencia.editar']);
if ($permiso === null) {
    Database::execute(
        'INSERT INTO #__permisos (codigo, descripcion, categoria, scope) VALUES (?, ?, ?, ?)',
        ['apariencia.editar', 'Editar los colores de las tarjetas de la aplicación', 'Apariencia', 'global']
    );
    echo "Permiso apariencia.editar creado.\n";
} else {
    echo "Permiso apariencia.editar ya existía.\n";
}

// 3. Concederlo a Supervisora y Admin. OJO: el '__ALL__' de Admin se expande al
//    momento del seed (init-db/seed.php), así que un permiso agregado DESPUÉS no
//    le llega solo — hay que concedérselo explícitamente acá.
foreach (['Supervisora', 'Admin'] as $nombreRol) {
    $rol = Database::fetchOne('SELECT id FROM #__roles WHERE nombre = ?', [$nombreRol]);
    if ($rol === null) {
        echo "Rol {$nombreRol} no encontrado — concede el permiso desde Ajustes → Roles.\n";
        continue;
    }
    $ya = Database::fetchOne(
        'SELECT 1 FROM #__rol_permisos WHERE rol_id = ? AND permiso_codigo = ?',
        [(int) $rol['id'], 'apariencia.editar']
    );
    if ($ya === null) {
        Database::execute(
            'INSERT INTO #__rol_permisos (rol_id, permiso_codigo) VALUES (?, ?)',
            [(int) $rol['id'], 'apariencia.editar']
        );
        echo "Permiso concedido al rol {$nombreRol}.\n";
    } else {
        echo "El rol {$nombreRol} ya tenía el permiso.\n";
    }
}

echo "Migración completa.\n";
