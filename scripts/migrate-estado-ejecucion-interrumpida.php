<?php

declare(strict_types=1);

/**
 * Migración: agrega el estado 'interrumpida' a ejecuciones_checklist.estado.
 *
 * Una limpieza queda 'interrumpida' cuando la pieza se aprueba por otra vía («cliente no desea
 * aseo», Cloudbeds) mientras la trabajadora la tenía a medias: conserva los ítems marcados para
 * los créditos, pero no cuenta como pieza hecha ni pasa por inspección. Ver docs/checklist.md
 * («Ejecución vencida»).
 *
 * Mismo patrón que scripts/migrate-estado-aprobada-automatica.php: en MariaDB busca el nombre
 * real del CHECK por introspección (information_schema) y lo recrea; si no encuentra exactamente
 * uno, imprime el SQL para correrlo a mano por phpMyAdmin. En SQLite (dev) los CHECK no se pueden
 * alterar sin reconstruir la tabla: recrea la BD con `php scripts/init-db.php --fresh`.
 *
 * Sin esta migración el código nuevo no se cae: cerrar la limpieza como 'interrumpida' falla, queda
 * un WARNING en el log y la ejecución sigue como antes (vencida, se borra al volver a empezar).
 * Igual hay que correrla ANTES de subir el código: la verificación de esquema (docs/deploy-cpanel.md
 * §7.1) detecta el CHECK viejo, /api/health responde 503 y «Salud del sistema» lo lista.
 *
 * Uso:
 *   php scripts/migrate-estado-ejecucion-interrumpida.php
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

if (!$esMaria) {
    echo "SQLite (dev): los CHECK constraints no se pueden alterar in-place.\n";
    echo "Recrea la BD de desarrollo con:  php scripts/init-db.php --fresh\n";
    exit(0);
}

$pdo     = Database::pdo();
$tabla   = Database::tabla('ejecuciones_checklist');
$valores = ['en_progreso', 'completada', 'auditada', 'interrumpida'];

$stmt = $pdo->prepare(
    'SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
       FROM information_schema.TABLE_CONSTRAINTS tc
       JOIN information_schema.CHECK_CONSTRAINTS cc
         ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
      WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = \'CHECK\''
);
$stmt->execute([$tabla]);
$filas = $stmt->fetchAll(\PDO::FETCH_ASSOC);

// El CHECK de estado es el único de la tabla que menciona 'auditada'.
$candidatos = array_values(array_filter(
    $filas,
    static fn(array $f) => str_contains((string) $f['CHECK_CLAUSE'], 'auditada')
));

$listaSql  = "'" . implode("', '", $valores) . "'";
$sqlManual = "ALTER TABLE {$tabla} DROP CONSTRAINT <NOMBRE>;\n"
    . "ALTER TABLE {$tabla} ADD CONSTRAINT <NOMBRE> CHECK (estado IN ({$listaSql}));";

if (count($candidatos) !== 1) {
    echo "{$tabla}.estado: no encontré exactamente un CHECK constraint (encontré " . count($candidatos) . ") — corre esto a mano por phpMyAdmin, reemplazando <NOMBRE> por el que corresponda:\n";
    foreach ($filas as $f) {
        echo "  - {$f['CONSTRAINT_NAME']}: {$f['CHECK_CLAUSE']}\n";
    }
    echo "{$sqlManual}\n";
    exit(1);
}

$nombre = (string) $candidatos[0]['CONSTRAINT_NAME'];
if (str_contains((string) $candidatos[0]['CHECK_CLAUSE'], 'interrumpida')) {
    echo "{$tabla}.estado: el CHECK '{$nombre}' ya acepta 'interrumpida' — omito.\n";
    exit(0);
}

try {
    $pdo->exec("ALTER TABLE {$tabla} DROP CONSTRAINT {$nombre}");
    $pdo->exec("ALTER TABLE {$tabla} ADD CONSTRAINT {$nombre} CHECK (estado IN ({$listaSql}))");
    echo "{$tabla}.estado: constraint '{$nombre}' actualizado.\n";
} catch (\Throwable $e) {
    echo "{$tabla}.estado: falló el ALTER automático ({$e->getMessage()}). Corre a mano:\n";
    echo str_replace('<NOMBRE>', $nombre, $sqlManual) . "\n";
    exit(1);
}

echo "Migración completa.\n";
