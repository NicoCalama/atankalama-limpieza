<?php

declare(strict_types=1);

/**
 * Script de sincronización con Cloudbeds.
 *
 * Uso:
 *   php scripts/sync-cloudbeds.php              # sincroniza todos los hoteles
 *   php scripts/sync-cloudbeds.php --hotel=1_sur
 *
 * Pensado para cron (07:00 y 15:00 hora Chile por default — configurable en cloudbeds_config).
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\CloudbedsSyncService;
use Atankalama\Limpieza\Services\HotelService;

Config::load(dirname(__DIR__));

$opts = getopt('', ['hotel::']);
$hotelCodigo = $opts['hotel'] ?? null;

$hotelId = null;
if (is_string($hotelCodigo) && $hotelCodigo !== '') {
    $hotel = (new HotelService())->buscarPorCodigo($hotelCodigo);
    if ($hotel === null) {
        fwrite(STDERR, "Hotel no encontrado: {$hotelCodigo}\n");
        exit(2);
    }
    $hotelId = $hotel->id;
    echo "Sincronizando hotel '{$hotelCodigo}' (id={$hotelId})...\n";
} else {
    echo "Sincronizando todos los hoteles activos...\n";
}

$sync = new CloudbedsSyncService(new CloudbedsClient());
$syncId = $sync->sincronizar($hotelId, 'auto_cron', null);

echo "Sync completada. sync_historial.id = {$syncId}\n";
