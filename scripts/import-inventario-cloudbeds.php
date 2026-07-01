<?php

declare(strict_types=1);

/**
 * Importa el inventario REAL de habitaciones desde Cloudbeds a la app.
 *
 * Trae las piezas reales de cada propiedad (getRooms, paginado), parsea el numero,
 * mapea el tipo de limpieza por maxGuests y hace un upsert idempotente por
 * cloudbeds_room_id. Las piezas que ya no vienen de Cloudbeds se desactivan (activa=0,
 * nunca se borran). Ver docs/cloudbeds-import-inventario.md e InventarioImportService.
 *
 * Uso:
 *   php scripts/import-inventario-cloudbeds.php --dry-run          # calcula el plan, NO escribe
 *   php scripts/import-inventario-cloudbeds.php                    # aplica los cambios
 *   php scripts/import-inventario-cloudbeds.php --hotel=inn        # una sola propiedad
 *   php scripts/import-inventario-cloudbeds.php --hotel=1_sur --dry-run
 *
 * Exit code: 0 si todo OK; 1 si alguna propiedad falló o hubo colisiones de numero.
 * Requiere que el catálogo esté sembrado (php scripts/seed.php) antes de correrlo.
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\InventarioImportService;

Config::load(dirname(__DIR__));

$opts = getopt('', ['dry-run', 'hotel::']);
$dryRun = array_key_exists('dry-run', $opts);
$hotelCodigo = is_string($opts['hotel'] ?? null) && trim($opts['hotel']) !== '' ? trim($opts['hotel']) : null;

$servicio = new InventarioImportService(CloudbedsClient::desdeConfig());

echo 'Import de inventario Cloudbeds' . ($dryRun ? '  [DRY-RUN — no escribe nada]' : '  [APLICANDO CAMBIOS]') . "\n";
if ($hotelCodigo !== null) {
    echo "Filtro de hotel: {$hotelCodigo}\n";
}
echo str_repeat('=', 64) . "\n";

$resultado = $servicio->importar($hotelCodigo, $dryRun);

if ($resultado['hoteles'] === []) {
    fwrite(STDERR, "No se procesó ninguna propiedad (¿código de hotel inexistente o sin cloudbeds_property_id?).\n");
    exit(1);
}

$conError = false;
$conColision = false;

foreach ($resultado['hoteles'] as $h) {
    echo "\n## {$h['nombre']} ({$h['codigo']})  propertyID=" . ($h['property_id'] ?? '-') . "\n";

    if ($h['error'] !== null) {
        $conError = true;
        echo "  ERROR: {$h['error']}\n";
        continue;
    }

    echo "  Cloudbeds: {$h['total_cloudbeds']} piezas\n";
    printf(
        "  creadas: %d | actualizadas: %d | sin cambio: %d | bloqueadas (activa=0): %d | desactivadas: %d\n",
        $h['creadas'],
        $h['actualizadas'],
        $h['sin_cambio'],
        $h['bloqueadas'],
        $h['desactivadas']
    );

    if ($h['colisiones'] !== []) {
        $conColision = true;
        echo '  ⚠ COLISIONES de numero (' . count($h['colisiones']) . " — se saltaron, revisar nomenclatura):\n";
        foreach ($h['colisiones'] as $c) {
            echo "     numero {$c['numero']} <- room_ids " . implode(', ', $c['room_ids']) . "\n";
        }
    }

    // En dry-run, detalle de los cambios planificados (crear/vincular/actualizar/desactivar).
    if ($dryRun) {
        $accionables = array_filter($h['cambios'], static fn(array $c) => $c['accion'] !== 'colision_saltada');
        if ($accionables === []) {
            echo "  (sin cambios: la app ya está sincronizada con Cloudbeds)\n";
        } else {
            echo '  Plan de cambios (' . count($accionables) . "):\n";
            foreach ($accionables as $c) {
                $extra = isset($c['tipo']) ? "  tipo={$c['tipo']} activa={$c['activa']}" : '';
                echo "     [{$c['accion']}] numero={$c['numero']}  room_id={$c['room_id']}{$extra}\n";
            }
        }
    }
}

$t = $resultado['totales'];
echo "\n" . str_repeat('=', 64) . "\n";
printf(
    "TOTALES — creadas: %d | actualizadas: %d | sin cambio: %d | bloqueadas: %d | desactivadas: %d | colisiones: %d\n",
    $t['creadas'],
    $t['actualizadas'],
    $t['sin_cambio'],
    $t['bloqueadas'],
    $t['desactivadas'],
    $t['colisiones']
);
if ($dryRun) {
    echo "DRY-RUN: no se escribió nada. Quitá --dry-run para aplicar.\n";
}

exit($conError || $conColision ? 1 : 0);
