<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\FestivoException;
use Atankalama\Limpieza\Services\FestivoService;

final class FestivosController
{
    public function __construct(
        private readonly FestivoService $svc = new FestivoService(),
    ) {
    }

    public function listar(Request $request): Response
    {
        $desde = is_string($request->query['desde'] ?? null) ? (string) $request->query['desde'] : '';
        $hasta = is_string($request->query['hasta'] ?? null) ? (string) $request->query['hasta'] : '';
        if ($desde === '' || $hasta === '') {
            return Response::error('PARAMETROS_INVALIDOS', 'desde y hasta son requeridos.', 400);
        }
        try {
            $festivos = $this->svc->listarRango($desde, $hasta);
        } catch (FestivoException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['festivos' => $festivos]);
    }

    public function crear(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $fecha = $request->inputString('fecha');
        $nombre = $request->inputString('nombre');
        if ($fecha === '' || $nombre === '') {
            return Response::error('PARAMETROS_INVALIDOS', 'fecha y nombre son requeridos.', 400);
        }
        try {
            $id = $this->svc->crear($fecha, $nombre, $request->usuario->id);
        } catch (FestivoException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['id' => $id], 201);
    }

    public function eliminar(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'festivo_id inválido.', 400);
        }
        try {
            $this->svc->eliminar($id, $request->usuario->id);
        } catch (FestivoException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['ok' => true]);
    }
}
