<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\EdificioException;
use Atankalama\Limpieza\Services\EdificioService;

class EdificiosController
{
    private EdificioService $edificios;

    public function __construct()
    {
        $this->edificios = new EdificioService();
    }

    public function listar(Request $request): Response
    {
        $lista = $this->edificios->listar();
        return Response::ok(['edificios' => array_map(fn($e) => $e->toArray(), $lista)]);
    }

    public function crear(Request $request): Response
    {
        $hotelId = $request->inputInt('hotel_id');
        if (!$hotelId) {
            return Response::error('HOTEL_INVALIDO', 'Debes seleccionar un hotel.', 400);
        }

        $nombre = $request->input('nombre');
        $pisos = $request->inputInt('pisos', 1);

        try {
            $edificio = $this->edificios->crear($hotelId, $nombre ?? '', $pisos, $request->usuario?->id);
            return Response::ok(['edificio' => $edificio->toArray()], 201);
        } catch (EdificioException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
    }

    public function actualizar(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if (!$id) return Response::error('ID_INVALIDO', 'ID inválido', 400);

        $hotelId = $request->inputInt('hotel_id');
        $nombre = $request->input('nombre');
        $pisos = $request->inputInt('pisos');

        if (!$hotelId || $pisos === null) {
            return Response::error('DATOS_FALTANTES', 'Faltan datos obligatorios.', 400);
        }

        try {
            $edificio = $this->edificios->actualizar($id, $hotelId, $nombre ?? '', $pisos, $request->usuario?->id);
            return Response::ok(['edificio' => $edificio->toArray()]);
        } catch (EdificioException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
    }

    public function eliminar(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if (!$id) return Response::error('ID_INVALIDO', 'ID inválido', 400);

        try {
            $this->edificios->eliminar($id, $request->usuario?->id);
            return Response::ok(['mensaje' => 'Edificio eliminado correctamente.']);
        } catch (EdificioException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
    }
}
