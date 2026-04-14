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

try {
    $pdo->exec($sql);
    echo "Schema aplicado correctamente.\n";
} catch (\PDOException $e) {
    fwrite(STDERR, "Error aplicando schema: " . $e->getMessage() . "\n");
    exit(1);
}

$tablas = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
echo "Tablas creadas (" . count($tablas) . "):\n";
foreach ($tablas as $tabla) {
    echo "  - {$tabla}\n";
}

echo "\nEjecuta `php scripts/seed.php` para cargar datos iniciales.\n";
