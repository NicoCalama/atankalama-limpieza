<?php

declare(strict_types=1);

/**
 * Diagnóstico de SOLO LECTURA (v7.8): qué bloqueos de habitaciones (mantención, cerradas, reservas
 * provisorias) tiene hoy Cloudbeds en cada hotel, y cómo los traduciría la app. Sirve para verificar
 * antes del deploy que la API key tiene el permiso `read:roomblock` y que lo que el hotel marca como
 * «mantención» llega como `out_of_service` y «cerrado» como `blocked_dates`. Ver docs/cloudbeds.md §4.5.
 *
 * No escribe nada: ni en Cloudbeds ni en la base. No muestra claves.
 *
 * Uso (en el PC, con el .env que tiene las claves):
 *   php -d curl.cainfo="C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt" scripts/diagnostico-bloqueos-cloudbeds.php
 *   php scripts/diagnostico-bloqueos-cloudbeds.php 2026-10-20     # otro día
 */

// Solo consola: nunca debe poder ejecutarse abriendo su URL.
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Models\Hotel;
use Atankalama\Limpieza\Services\BloqueosService;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\HotelService;

Config::load(dirname(__DIR__));
date_default_timezone_set((string) Config::get('APP_TIMEZONE', 'America/Santiago'));

$fecha = $argv[1] ?? date('Y-m-d');
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) !== 1) {
    fwrite(STDERR, "Fecha inválida: {$fecha} (formato YYYY-MM-DD)\n");
    exit(1);
}

$client = CloudbedsClient::desdeConfig();
echo "Bloqueos en Cloudbeds para el {$fecha}\n";

foreach ((new HotelService())->listar(true) as $hotel) {
    /** @var Hotel $hotel */
    echo "\n== {$hotel->codigo} ==\n";
    if ($hotel->cloudbedsPropertyId === null || $hotel->cloudbedsPropertyId === '') {
        echo "  sin cloudbeds_property_id\n";
        continue;
    }

    try {
        $respuesta = $client->obtenerBloqueosHabitaciones($hotel->cloudbedsPropertyId, $fecha);
    } catch (\Throwable $e) {
        echo '  getRoomBlocks falló: ' . $e->getMessage() . "\n";
        continue;
    }
    if (($respuesta['success'] ?? null) !== true) {
        $mensaje = is_string($respuesta['message'] ?? null) ? $respuesta['message'] : '(sin mensaje)';
        echo "  getRoomBlocks respondió success=false: {$mensaje}\n";
        echo "  → ¿la API key tiene el permiso read:roomblock? Sin él, la app solo sabría «bloqueada», sin tipo.\n";
        continue;
    }

    $nombres = [];
    $bloqueadas = 0;
    try {
        $estados = $client->obtenerEstadosHabitaciones($hotel->cloudbedsPropertyId);
        foreach (is_array($estados['data'] ?? null) ? $estados['data'] : [] as $r) {
            if (!is_array($r)) {
                continue;
            }
            $nombres[(string) ($r['roomID'] ?? '')] = (string) ($r['roomName'] ?? '');
            $bloqueadas += !empty($r['roomBlocked']) ? 1 : 0;
        }
    } catch (\Throwable $e) {
        echo '  (getHousekeepingStatus falló: ' . $e->getMessage() . ")\n";
    }

    $bloqueos = $respuesta['data'];
    echo '  ' . count($bloqueos) . " bloqueo(s); getHousekeepingStatus da {$bloqueadas} pieza(s) con roomBlocked=true\n";
    foreach ($bloqueos as $b) {
        $tipoCb = (string) ($b['roomBlockType'] ?? '?');
        $tipoApp = BloqueosService::tipoDesdeCloudbeds($tipoCb) ?? '(no es bloqueo)';
        $piezas = [];
        foreach (is_array($b['rooms'] ?? null) ? $b['rooms'] : [] as $room) {
            $id = is_array($room) ? (string) ($room['roomID'] ?? '') : '';
            $piezas[] = $nombres[$id] ?? $id;
        }
        printf(
            "  - %s → app: %s | motivo: %s | %s a %s | piezas: %s\n",
            $tipoCb,
            $tipoApp,
            (string) ($b['roomBlockReason'] ?? '—'),
            (string) ($b['startDate'] ?? '?'),
            (string) ($b['endDate'] ?? '?'),
            implode(', ', $piezas)
        );
    }
}
echo "\nNo se escribió nada.\n";
