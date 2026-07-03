<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$driver  = Database::driver();
$esMaria = $driver === 'mysql' || $driver === 'mariadb';

// Schema según motor: el de MariaDB trae tokens #__ (Database::applyPrefix los expande a
// DB_PREFIX, p.ej. 'limpieza_'); el de SQLite usa nombres planos (DB_PREFIX vacío en local).
$schemaFile = $esMaria
    ? dirname(__DIR__) . '/docs/database-schema.mariadb.sql'
    : dirname(__DIR__) . '/docs/database-schema.sql';

if (!is_file($schemaFile)) {
    fwrite(STDERR, "No se encontró el schema: {$schemaFile}\n");
    exit(1);
}

$recrear = in_array('--fresh', $argv, true);
if ($recrear) {
    if ($esMaria) {
        // En la BD compartida de cPanel NO auto-borramos tablas: demasiado peligroso
        // (conviven con maisterchef_*). Si hace falta, borra las limpieza_* a mano.
        fwrite(STDERR, "--fresh no está soportado en MariaDB (BD compartida). Borra las tablas limpieza_* manualmente si lo necesitas.\n");
        exit(1);
    }
    $dbPath = Config::basePath() . DIRECTORY_SEPARATOR . Config::get('DB_PATH', 'database/atankalama.db');
    if (is_file($dbPath)) {
        Database::reset();
        unlink($dbPath);
        echo "Base de datos anterior eliminada: {$dbPath}\n";
    }
}

$pdo = Database::pdo();

// ¿Ya está aplicado el schema? Se chequea la tabla permisos (ya prefijada según el motor).
$tablaPermisos = Database::tabla('permisos');
if ($esMaria) {
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
} else {
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
}
$stmt->execute([$tablaPermisos]);
$schemaYaAplicado = (bool) $stmt->fetchColumn();

if ($schemaYaAplicado) {
    echo "Schema ya aplicado previamente — omito CREATE TABLE.\n";
    if (!$esMaria) {
        echo "(usa --fresh para borrar y recrear desde cero)\n";
    }
} else {
    $sqlRaw = file_get_contents($schemaFile);
    if ($sqlRaw === false) {
        fwrite(STDERR, "No se pudo leer el schema\n");
        exit(1);
    }
    // Expande el token de prefijo (#__ -> DB_PREFIX) antes de aplicar el schema.
    $sqlRaw = Database::applyPrefix($sqlRaw);
    try {
        if ($esMaria) {
            // MariaDB: statement por statement (mejores errores; el multi-statement de MySQL
            // puede enmascarar fallos). El schema MariaDB es DDL limpio, sin PRAGMA.
            foreach (dividirEnStatements($sqlRaw) as $statement) {
                $pdo->exec($statement);
            }
        } else {
            // SQLite: ejecuta el archivo completo de una vez — maneja multi-statement, PRAGMA y
            // comentarios nativamente. Es el comportamiento probado del init original.
            $pdo->exec($sqlRaw);
        }
        echo "Schema aplicado correctamente.\n";
    } catch (\PDOException $e) {
        fwrite(STDERR, "Error aplicando schema: " . $e->getMessage() . "\n");
        exit(1);
    }
}

// Listado de tablas de esta app (en MariaDB se filtra por prefijo para no listar las de
// otras apps que comparten la base, p.ej. maisterchef_*).
if ($esMaria) {
    $todas = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name')
        ->fetchAll(\PDO::FETCH_COLUMN);
    $prefijo = Database::prefix();
    $tablas = array_values(array_filter($todas, static fn ($t): bool => str_starts_with((string) $t, $prefijo)));
} else {
    $tablas = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
}
echo "Tablas (" . count($tablas) . "):\n";
foreach ($tablas as $tabla) {
    echo "  - {$tabla}\n";
}

// Sincronización RBAC idempotente: agrega permisos nuevos del catálogo y reasegura los
// permisos por rol. Se ejecuta en cada deploy para aplicar cambios en database/seeds/
// sin requerir un re-seed manual. Pasa por Database (token #__ + dialecto portable).
echo "\nSincronizando catálogo RBAC...\n";
$seedDir = dirname(__DIR__) . '/database/seeds';
$catalogoPermisos = require $seedDir . '/permisos.php';
$nuevos = 0;
foreach ($catalogoPermisos as [$codigo, $descripcion, $categoria, $scope]) {
    $stmt = Database::query(
        'INSERT OR IGNORE INTO #__permisos (codigo, descripcion, categoria, scope) VALUES (?, ?, ?, ?)',
        [$codigo, $descripcion, $categoria, $scope]
    );
    if ($stmt->rowCount() > 0) {
        $nuevos++;
    }
}
echo "  permisos: " . count($catalogoPermisos) . " en catálogo, {$nuevos} nuevos insertados\n";

// Re-asegurar permisos por rol según database/seeds/roles.php
$catalogoRoles = require $seedDir . '/roles.php';
$codigosTodos = array_column(Database::fetchAll('SELECT codigo FROM #__permisos'), 'codigo');
foreach ($catalogoRoles as $rol) {
    $rolId = (int) Database::fetchColumn('SELECT id FROM #__roles WHERE nombre = ?', [$rol['nombre']]);
    if ($rolId === 0) {
        continue; // El rol aún no existe (el seed inicial no se corrió). seed.php se encargará.
    }
    $permisosRol = $rol['permisos'] === '__ALL__' ? $codigosTodos : $rol['permisos'];
    foreach ($permisosRol as $cod) {
        Database::query(
            'INSERT OR IGNORE INTO #__rol_permisos (rol_id, permiso_codigo) VALUES (?, ?)',
            [$rolId, $cod]
        );
    }
}
echo "  roles: " . count($catalogoRoles) . " sincronizados\n";

echo "\nSi es la primera vez, ejecuta también `php scripts/seed.php` para cargar datos iniciales.\n";

/**
 * Divide un script SQL (DDL de MariaDB) en statements ejecutables por separado. Quita TODO
 * comentario `-- ...` (de línea completa E inline) ANTES de partir en ';': un ';' dentro de
 * un comentario inline cortaría el statement al medio (bug real: los comentarios de las
 * columnas es_espacio_comun/franja/habitacion_id traían ';' y el CREATE llegaba truncado a
 * MariaDB). Asume que fuera de comentarios el ';' solo aparece como terminador y que ningún
 * literal del schema contiene ' -- ' (cierto hoy; los CHECK usan comas).
 *
 * @return list<string>
 */
function dividirEnStatements(string $sql): array
{
    // Comentario de línea completa o inline: '--' seguido de espacio (sintaxis MySQL).
    $sql = (string) preg_replace('/(^|\s)--\s.*$/m', '$1', $sql);
    $statements = [];
    foreach (explode(';', $sql) as $chunk) {
        $stmt = trim($chunk);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }
    }
    return $statements;
}
