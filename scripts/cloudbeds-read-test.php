<?php

declare(strict_types=1);

/**
 * Prueba de salud SOLO-LECTURA de la integración Cloudbeds (capa 1 del plan
 * seguro — ver docs/cloudbeds-pruebas-seguras.md).
 *
 * Por cada propiedad activa con cloudbeds_property_id llama únicamente a los dos
 * GET de lectura (getRooms y getHousekeepingStatus). NO escribe nada en Cloudbeds ni en
 * la base de datos. Valida credenciales, propertyID, base URL, red/auth/parseo y
 * el mapeo de habitaciones sin poder modificar nada.
 *
 * Uso:
 *   php scripts/cloudbeds-read-test.php                 # todas las propiedades activas
 *   php scripts/cloudbeds-read-test.php --hotel=inn     # una sola (por código de hotel)
 *
 * Exit code: 0 si todas responden OK; 1 si alguna falla o no hay nada que probar
 * (apto para cron/CI o como smoke previo a deploy / rotación de keys).
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Models\Hotel;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\HotelService;

Config::load(dirname(__DIR__));

$opts = getopt('', ['hotel::']);
$hotelCodigo = is_string($opts['hotel'] ?? null) ? trim($opts['hotel']) : null;

$hoteles = (new HotelService())->listar(true);
if ($hotelCodigo !== null && $hotelCodigo !== '') {
    $hoteles = array_filter($hoteles, static fn(Hotel $h) => $h->codigo === $hotelCodigo);
    if ($hoteles === []) {
        fwrite(STDERR, "Hotel no encontrado o inactivo: {$hotelCodigo}\n");
        exit(2);
    }
}

$client = CloudbedsClient::desdeConfig();

echo "Prueba de lectura Cloudbeds (solo GET — no escribe nada)\n";
echo 'Base URL: ' . Config::get('CLOUDBEDS_BASE_URL', '(default del cliente)') . "\n";
echo str_repeat('=', 60) . "\n";

$probados = 0;
$fallos = 0;

foreach ($hoteles as $hotel) {
    if ($hotel->cloudbedsPropertyId === null || $hotel->cloudbedsPropertyId === '') {
        echo "\n## {$hotel->nombre} ({$hotel->codigo}) — sin cloudbeds_property_id, salteado.\n";
        continue;
    }

    $probados++;
    $propertyId = $hotel->cloudbedsPropertyId;
    echo "\n## {$hotel->nombre} ({$hotel->codigo})  propertyID={$propertyId}\n";

    try {
        // 1) Inventario de habitaciones.
        $json = $client->obtenerHabitaciones($propertyId);
        if (($json['success'] ?? false) !== true) {
            $fallos++;
            echo "  getRooms: la respuesta no trae success=true (revisar credencial o propertyID).\n";
            continue;
        }

        $rooms = [];
        foreach (($json['data'] ?? []) as $grupo) {
            foreach (($grupo['rooms'] ?? []) as $room) {
                $rooms[] = $room;
            }
        }
        // obtenerHabitaciones ahora pagina: count(rooms) debe igualar total.
        $total = (int) ($json['total'] ?? count($rooms));
        $incompleto = count($rooms) < $total ? '  ⚠ INCOMPLETO (revisar paginación)' : '';
        echo '  getRooms: OK — ' . count($rooms) . " de {$total} habitaciones.{$incompleto}\n";
        if ($incompleto !== '') {
            $fallos++;
        }
        foreach (array_slice($rooms, 0, 3) as $room) {
            echo '    - ' . ($room['roomID'] ?? '?') . '  ' . ($room['roomName'] ?? '?') . "\n";
        }

        // 2) Estados de limpieza (el GET que usa el cron de sincronización).
        $estados = $client->obtenerEstadosHabitaciones($propertyId);
        if (($estados['success'] ?? false) !== true) {
            $fallos++;
            echo "  getHousekeepingStatus: la respuesta no trae success=true (revisar endpoint o credencial).\n";
            continue;
        }
        $filas = $estados['data'] ?? $estados['rooms'] ?? [];
        echo '  getHousekeepingStatus: OK — ' . (is_array($filas) ? count($filas) : 0) . " registros.\n";
    } catch (\Throwable $e) {
        $fallos++;
        echo '  ERROR ' . $e->getMessage() . "\n";
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Propiedades probadas: {$probados}  |  con error: {$fallos}\n";

if ($probados === 0) {
    fwrite(STDERR, "No se probó ninguna propiedad (¿faltan cloudbeds_property_id en los hoteles?).\n");
    exit(1);
}

exit($fallos === 0 ? 0 : 1);
