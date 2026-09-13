<?php

declare(strict_types=1);

/**
 * Script de sincronización con Cloudbeds (auto-regulado).
 *
 * Uso:
 *   php scripts/sync-cloudbeds.php              # respeta el intervalo configurado (throttle)
 *   php scripts/sync-cloudbeds.php --force      # ignora el throttle y sincroniza ya
 *   php scripts/sync-cloudbeds.php --hotel=1_sur
 *   php scripts/sync-cloudbeds.php --fecha=2026-09-08 --hora=18:05   # simula fecha/hora (nocheros)
 *
 * Pensado para un crontab de tick corto (p. ej. cada 10 min):
 *   *\/10 * * * * php /ruta/scripts/sync-cloudbeds.php
 * El script decide si le toca según cloudbeds_config.sync_intervalo_minutos (default 30,
 * editable vía PUT /api/cloudbeds/config) — así la cadencia se cambia desde la app sin
 * tocar el crontab. Ver docs/cloudbeds.md §4.1.
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\CloudbedsSyncService;
use Atankalama\Limpieza\Services\HabitacionService;
use Atankalama\Limpieza\Services\HotelService;
use Atankalama\Limpieza\Services\InventarioCheckService;
use Atankalama\Limpieza\Services\InventarioImportService;

Config::load(dirname(__DIR__));

$opts = getopt('', ['hotel::', 'force', 'fecha::', 'hora::']);
$hotelCodigo = $opts['hotel'] ?? null;
$force = array_key_exists('force', $opts);
// --fecha/--hora: solo para probar el barrido de nocheros sin esperar a las 16:00 real
// (mismo patrón que AlertasPredictivasService / recalcular-alertas.php).
$hoy = is_string($opts['fecha'] ?? null) && $opts['fecha'] !== '' ? $opts['fecha'] : date('Y-m-d');
$horaActual = is_string($opts['hora'] ?? null) && $opts['hora'] !== '' ? $opts['hora'] : date('H:i');

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

// Nocheros (hotel minero, aseo doble por turno): desde las 16:00 revierte a 'sucia' las piezas
// nochero que ya quedaron en un estado terminal, y avisa a Cloudbeds ('dirty') para que su propio
// sync entrante no las cierre de vuelta. Va ANTES del throttle del sync de Cloudbeds (más abajo)
// porque debe evaluarse en CADA tick del cron, no solo cuando toca sincronizar. No crítico: si
// falla, el próximo tick reintenta. Ver docs/nocheros.md
try {
    if ($horaActual >= '16:00') {
        $habSvc = new HabitacionService();
        $vencidos = $habSvc->desactivarNocherosVencidos($hoy);
        $revertidas = 0;
        foreach ($habSvc->listarNocherosVigentesEnEstadoTerminal($hoy) as $hab) {
            $habSvc->cambiarEstado($hab->id, Habitacion::ESTADO_SUCIA, null, 'cron');
            $habSvc->marcarBarridoNocheroHoy($hab->id, $hoy); // una reversión por día, no una por cada aprobación
            if ($hab->cloudbedsRoomId !== null) {
                $sync->escribirEstadoDirty($hab); // best-effort, ya crea alerta P0 si falla
            }
            $revertidas++;
        }
        echo "Nocheros: {$vencidos} vencidos desactivados, {$revertidas} revertidas a sucia.\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "Barrido de nocheros falló (no crítico): {$e->getMessage()}\n");
}

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
