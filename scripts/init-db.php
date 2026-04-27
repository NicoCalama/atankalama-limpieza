<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$schemaFile = dirname(__DIR__) . '/docs/database-schema.sql';
if (!is_file($schemaFile)) {
    fwrite(STDERR, "No se encontró el schema: {$schemaFile}\n");
    exit(1);
}

$dbPath = Config::basePath() . DIRECTORY_SEPARATOR . Config::get('DB_PATH', 'database/atankalama.db');

$recrear = in_array('--fresh', $argv, true);
if ($recrear && is_file($dbPath)) {
    Database::reset();
    unlink($dbPath);
    echo "Base de datos anterior eliminada: {$dbPath}\n";
}

$pdo = Database::pdo();
$sql = file_get_contents($schemaFile);
if ($sql === false) {
    fwrite(STDERR, "No se pudo leer el schema\n");
    exit(1);
}

$schemaYaAplicado = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='permisos'")->fetchColumn();
if ($schemaYaAplicado) {
    echo "Schema ya aplicado previamente — omito CREATE TABLE.\n";
    echo "(usa --fresh para borrar y recrear desde cero)\n";
} else {
    try {
        $pdo->exec($sql);
        echo "Schema aplicado correctamente.\n";
    } catch (\PDOException $e) {
        fwrite(STDERR, "Error aplicando schema: " . $e->getMessage() . "\n");
        exit(1);
    }
}

$tablas = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
echo "Tablas creadas (" . count($tablas) . "):\n";
foreach ($tablas as $tabla) {
    echo "  - {$tabla}\n";
}

// Sincronización RBAC idempotente: agrega permisos nuevos del catálogo y
// reasegura __ALL__ para Admin. Se ejecuta en cada deploy para que cambios
// en database/seeds/permisos.php se apliquen sin requerir un re-seed manual.
echo "\nSincronizando catálogo RBAC...\n";
$seedDir = dirname(__DIR__) . '/database/seeds';
$catalogoPermisos = require $seedDir . '/permisos.php';
$stmtIns = $pdo->prepare('INSERT OR IGNORE INTO permisos (codigo, descripcion, categoria, scope) VALUES (?, ?, ?, ?)');
$nuevos = 0;
foreach ($catalogoPermisos as [$codigo, $descripcion, $categoria, $scope]) {
    $stmtIns->execute([$codigo, $descripcion, $categoria, $scope]);
    if ($stmtIns->rowCount() > 0) {
        $nuevos++;
    }
}
echo "  permisos: " . count($catalogoPermisos) . " en catálogo, {$nuevos} nuevos insertados\n";

// Re-asegurar permisos por rol según database/seeds/roles.php
$catalogoRoles = require $seedDir . '/roles.php';
$codigosTodos = $pdo->query('SELECT codigo FROM permisos')->fetchAll(\PDO::FETCH_COLUMN);
foreach ($catalogoRoles as $rol) {
    $rolId = (int) $pdo->query("SELECT id FROM roles WHERE nombre = " . $pdo->quote($rol['nombre']))->fetchColumn();
    if ($rolId === 0) {
        continue; // El rol aún no existe (el seed inicial no se corrió). seed.php se encargará.
    }
    $permisosRol = $rol['permisos'] === '__ALL__' ? $codigosTodos : $rol['permisos'];
    $stmtRol = $pdo->prepare('INSERT OR IGNORE INTO rol_permisos (rol_id, permiso_codigo) VALUES (?, ?)');
    foreach ($permisosRol as $cod) {
        $stmtRol->execute([$rolId, $cod]);
    }
}
echo "  roles: " . count($catalogoRoles) . " sincronizados\n";

echo "\nSi es la primera vez, ejecuta también `php scripts/seed.php` para cargar datos iniciales.\n";
