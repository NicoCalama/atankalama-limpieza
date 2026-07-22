<?php

declare(strict_types=1);

/**
 * Chequeo manual de inventario: detecta altas/bajas de piezas en Cloudbeds y, si hay,
 * levanta la alerta `inventario_cambios_pendientes` para la supervisora.
 *
 * Normalmente esto lo dispara solo el cron de sync (scripts/sync-cloudbeds.php), que lo
 * throttlea a 1 vez por día. Este script sirve para forzarlo a mano (dev, pruebas, o si
 * se quiere adelantar el chequeo). Solo LEE de Cloudbeds (dry-run); no escribe piezas —
 * eso pasa cuando la supervisora aprieta "Aceptar" en la alerta.
 *
 * Uso:
 *   php scripts/check-inventario-cloudbeds.php            # respeta el throttle diario
 *   php scripts/check-inventario-cloudbeds.php --force    # ignora el throttle y chequea ya
 *
 * Ver docs/cloudbeds-import-inventario.md.
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\InventarioCheckService;
use Atankalama\Limpieza\Services\InventarioImportService;

Config::load(dirname(__DIR__));

$opts = getopt('', ['force']);
$force = array_key_exists('force', $opts);

$check = new InventarioCheckService(new InventarioImportService(CloudbedsClient::desdeConfig()));
$rev = $check->revisar($force);

if ($rev['omitido'] ?? false) {
    echo "Chequeo omitido (throttle diario). Usa --force para saltarlo.\n";
    exit(0);
}

echo match ($rev['accion'] ?? '') {
    'sin_cambios' => "Sin cambios: el inventario de la app está sincronizado con Cloudbeds.\n",
    'alerta_levantada' => "Se detectaron {$rev['cambios']} cambios → alerta levantada para la supervisora.\n",
    'rechazado_previamente' => "Hay {$rev['cambios']} cambios, pero fueron rechazados antes (no se re-alerta hasta que cambien).\n",
    default => "Chequeo completado.\n",
};
