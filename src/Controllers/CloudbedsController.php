<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\CloudbedsSyncService;
use Atankalama\Limpieza\Services\HotelService;

final class CloudbedsController
{
    public function __construct(
        private readonly ?CloudbedsSyncService $sync = null,
        private readonly HotelService $hoteles = new HotelService(),
    ) {
    }

    private function servicio(): CloudbedsSyncService
    {
        return $this->sync ?? new CloudbedsSyncService(new CloudbedsClient());
    }

    public function estado(Request $request): Response
    {
        $actual = $this->servicio()->estadoActual();
        return Response::ok(['ultima' => $actual]);
    }

    public function historial(Request $request): Response
    {
        $limite = (int) ($request->query['limite'] ?? 50);
        return Response::ok(['historial' => $this->servicio()->historial($limite)]);
    }

    public function sincronizar(Request $request): Response
    {
        $hotelCodigo = $request->input('hotel');
        $hotelId = null;
        if (is_string($hotelCodigo) && $hotelCodigo !== '' && $hotelCodigo !== 'ambos') {
            $hotel = $this->hoteles->buscarPorCodigo($hotelCodigo);
            if ($hotel === null) {
                return Response::error('HOTEL_NO_ENCONTRADO', 'Hotel no encontrado.', 404);
            }
            $hotelId = $hotel->id;
        }

        $syncId = $this->servicio()->sincronizar($hotelId, 'manual', $request->usuario?->id);
        $fila = Database::fetchOne('SELECT * FROM cloudbeds_sync_historial WHERE id = ?', [$syncId]);
        return Response::ok(['sync_id' => $syncId, 'sync' => $fila], 202);
    }

    public function listarConfig(Request $request): Response
    {
        $config = Database::fetchAll('SELECT clave, valor, descripcion, updated_at FROM cloudbeds_config ORDER BY clave');
        return Response::ok(['config' => $config]);
    }

    public function actualizarConfig(Request $request): Response
    {
        $cambios = $request->input('cambios');
        if (!is_array($cambios) || $cambios === []) {
            return Response::error('CAMPOS_REQUERIDOS', 'cambios es obligatorio (array clave=>valor).', 400);
        }

        Database::transaction(function () use ($cambios, $request): void {
            foreach ($cambios as $clave => $valor) {
                Database::execute(
                    "UPDATE cloudbeds_config SET valor = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), updated_by = ? WHERE clave = ?",
                    [(string) $valor, $request->usuario?->id, (string) $clave]
                );
            }
        });

        return Response::ok(['mensaje' => 'Configuración actualizada.']);
    }
}
