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
use Atankalama\Limpieza\Services\InventarioCheckService;
use Atankalama\Limpieza\Services\InventarioImportService;

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

// Chequeo de inventario (altas/bajas de piezas en Cloudbeds). Se throttlea a 1 vez por día
// dentro del propio servicio, así viaja en este mismo cron sin agregar otra entrada de crontab.
// No es crítico: si falla, el sync ya está registrado; el próximo tick reintenta.
try {
    $check = new InventarioCheckService(new InventarioImportService(CloudbedsClient::desdeConfig()));
    $rev = $check->revisar($force);
    if ($rev['omitido'] ?? false) {
        echo "Chequeo de inventario omitido (throttle diario).\n";
    } else {
        echo "Chequeo de inventario: {$rev['accion']} ({$rev['cambios']} cambios detectados).\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "Chequeo de inventario falló (no crítico): {$e->getMessage()}\n");
}
