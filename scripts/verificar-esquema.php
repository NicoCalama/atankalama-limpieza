<?php

declare(strict_types=1);

/**
 * Verifica que la base de datos tenga todo lo que el código espera: tablas, columnas y
 * permisos del catálogo. Solo LEE — nunca modifica nada.
 *
 * Para qué: el 22/09/2026 se descubrió que el SQL de la v6.4 nunca se había corrido en
 * producción. El código subió igual y `POST /api/auditoria/{id}/iniciar` tiró 500 en cada
 * apertura de inspección durante días, en silencio. Este script convierte eso en una
 * respuesta de dos segundos.
 *
 * Correlo DESPUÉS de subir un delta que traiga SQL (ver docs/deploy-cpanel.md §11).
 *
 * Uso:
 *   php scripts/verificar-esquema.php
 *
 * En producción (cPanel):
 *   /opt/alt/php84/usr/bin/php /home4/cat6852/public_html/limpieza/app_core/scripts/verificar-esquema.php
 *
 * Sale con código 1 si falta algo, así sirve también como cron o en un pipeline.
 */

// Solo consola: nunca debe poder ejecutarse abriendo su URL.
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\EsquemaService;

Config::load(dirname(__DIR__));

echo "Verificación de esquema\n";
echo str_repeat('=', 64) . "\n";
echo 'Motor: ' . Database::driver() . "\n";
echo 'Schema de referencia: ' . basename(EsquemaService::archivoDeSchema(Database::driver())) . "\n\n";

$r = (new EsquemaService())->faltantes();

if ($r['ok']) {
    echo "OK: la base está al día con el código.\n";
    exit(0);
}

echo "FALTAN {$r['total']} elemento(s). La app puede estar fallando en silencio.\n\n";

if ($r['tablas'] !== []) {
    echo "Tablas que faltan (" . count($r['tablas']) . "):\n";
    foreach ($r['tablas'] as $t) {
        echo "  - {$t}\n";
    }
    echo "\n";
}

if ($r['columnas'] !== []) {
    echo "Columnas que faltan (" . count($r['columnas']) . "):\n";
    foreach ($r['columnas'] as $c) {
        echo "  - {$c}\n";
    }
    echo "\n";
}

if ($r['permisos'] !== []) {
    echo "Permisos del catálogo que faltan (" . count($r['permisos']) . "):\n";
    foreach ($r['permisos'] as $p) {
        echo "  - {$p}\n";
    }
    echo "\n";
}

echo "Qué hacer: corré el SQL del release en docs/deploy-cpanel.md §11, o el script\n";
echo "scripts/migrate-*.php que corresponda. Después volvé a correr esta verificación.\n";
echo "Ojo: el SQL va ANTES del código, no después.\n";

exit(1);
