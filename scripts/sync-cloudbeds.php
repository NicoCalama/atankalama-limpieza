<?php

declare(strict_types=1);

/**
 * Script de sincronización con Cloudbeds (auto-regulado).
 *
 * Uso:
 *   php scripts/sync-cloudbeds.php              # respeta el intervalo configurado (throttle)
 *   php scripts/sync-cloudbeds.php --force      # ignora el throttle y sincroniza ya
 *   php scripts/sync-cloudbeds.php --hotel=1_sur
 *
 * Pensado para un crontab de tick corto (p. ej. cada 10 min):
 *   *\/10 * * * * php /ruta/scripts/sync-cloudbeds.php
 * El script decide si le toca según cloudbeds_config.sync_intervalo_minutos (default 30,
 * editable vía PUT /api/cloudbeds/config) — así la cadencia se cambia desde la app sin
 * tocar el crontab. Ver docs/cloudbeds.md §4.1.
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\CloudbedsSyncService;
use Atankalama\Limpieza\Services\HotelService;

Config::load(dirname(__DIR__));

$opts = getopt('', ['hotel::', 'force']);
$hotelCodigo = $opts['hotel'] ?? null;
$force = array_key_exists('force', $opts);

$hotelId = null;
if (is_string($hotelCodigo) && $hotelCodigo !== '') {
    $hotel = (new HotelService())->buscarPorCodigo($hotelCodigo);
    if ($hotel === null) {
        fwrite(STDERR, "Hotel no encontrado: {$hotelCodigo}\n");
        exit(2);
    }
    $hotelId = $hotel->id;
}

$sync = new CloudbedsSyncService(CloudbedsClient::desdeConfig());

// Throttle: el cron tickea seguido; solo se sincroniza si pasó el intervalo configurado.
$intervalo = $sync->intervaloSyncMinutos();
if (!$force && !$sync->debeCorrerSyncAutomatica($intervalo)) {
    echo "Sync omitida: la última corrió hace menos de {$intervalo} min (usa --force para saltar el throttle).\n";
    exit(0);
}

echo $hotelId !== null
    ? "Sincronizando hotel '{$hotelCodigo}' (id={$hotelId})...\n"
    : "Sincronizando todos los hoteles activos...\n";

$syncId = $sync->sincronizar($hotelId, 'auto_cron', null);

echo "Sync completada. sync_historial.id = {$syncId}\n";
