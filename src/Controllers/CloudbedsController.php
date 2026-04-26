<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

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
        $fila = $this->servicio()->obtenerHistorial($syncId);
        return Response::ok(['sync_id' => $syncId, 'sync' => $fila], 202);
    }

    public function listarConfig(Request $request): Response
    {
        return Response::ok(['config' => $this->servicio()->listarConfig()]);
    }

    public function actualizarConfig(Request $request): Response
    {
        $cambios = $request->input('cambios');
        if (!is_array($cambios) || $cambios === []) {
            return Response::error('CAMPOS_REQUERIDOS', 'cambios es obligatorio (array clave=>valor).', 400);
        }

        $this->servicio()->actualizarConfig($cambios, $request->usuario?->id);

        return Response::ok(['mensaje' => 'Configuración actualizada.']);
    }
}
