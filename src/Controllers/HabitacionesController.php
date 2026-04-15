<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\HabitacionException;
use Atankalama\Limpieza\Services\HabitacionService;
use Atankalama\Limpieza\Services\HotelService;

final class HabitacionesController
{
    public function __construct(
        private readonly HabitacionService $habitaciones = new HabitacionService(),
        private readonly HotelService $hoteles = new HotelService(),
    ) {
    }

    public function listar(Request $request): Response
    {
        $hotel = $request->query['hotel'] ?? 'ambos';
        $estado = $request->query['estado'] ?? null;

        try {
            $filas = $this->habitaciones->listar(is_string($hotel) ? $hotel : 'ambos', is_string($estado) ? $estado : null);
        } catch (HabitacionException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        return Response::ok(['habitaciones' => $filas, 'total' => count($filas)]);
    }

    public function obtener(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ID de habitación inválido.', 400);
        }
        $detalle = $this->habitaciones->obtenerDetalle($id);
        if ($detalle === null) {
            return Response::error('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada.', 404);
        }
        return Response::ok(['habitacion' => $detalle]);
    }

    public function listarHoteles(Request $request): Response
    {
        $hoteles = array_map(fn($h) => $h->toArray(), $this->hoteles->listar(false));
        return Response::ok(['hoteles' => $hoteles]);
    }
}
