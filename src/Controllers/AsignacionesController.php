<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\AsignacionException;
use Atankalama\Limpieza\Services\AsignacionService;

final class AsignacionesController
{
    public function __construct(
        private readonly AsignacionService $svc = new AsignacionService(),
    ) {
    }

    public function crear(Request $request): Response
    {
        $habitacionIds = $request->input('habitacion_ids');
        $usuarioId = $request->inputInt('usuario_id');
        $fecha = $request->inputString('fecha');

        if (!is_array($habitacionIds) || $habitacionIds === [] || $usuarioId === null || $fecha === '') {
            return Response::error('PARAMETROS_INVALIDOS', 'habitacion_ids, usuario_id y fecha son requeridos.', 400);
        }
        $ids = array_values(array_map('intval', $habitacionIds));

        try {
            $creadas = $this->svc->asignarMultiple($ids, $usuarioId, $fecha, $request->usuario?->id);
        } catch (AsignacionException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        return Response::ok([
            'asignaciones' => array_map(fn($a) => $a->toArray(), $creadas),
            'total' => count($creadas),
        ], 201);
    }

    public function auto(Request $request): Response
    {
        $hotel = $request->inputString('hotel', 'ambos');
        $fecha = $request->inputString('fecha');
        if ($fecha === '') {
            return Response::error('PARAMETROS_INVALIDOS', 'fecha es requerida (YYYY-MM-DD).', 400);
        }

        try {
            $resultado = $this->svc->autoAsignar($hotel, $fecha);
        } catch (AsignacionException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        return Response::ok([
            'asignaciones' => array_map(fn($a) => $a->toArray(), $resultado['asignaciones']),
            'habitaciones' => $resultado['habitaciones'],
            'trabajadores' => $resultado['trabajadores'],
        ], 201);
    }

    public function reasignar(Request $request): Response
    {
        $habitacionId = $request->inputInt('habitacion_id');
        $usuarioId = $request->inputInt('usuario_id');
        $fecha = $request->inputString('fecha');
        $motivo = $request->inputString('motivo', 'reasignacion_manual');

        if ($habitacionId === null || $usuarioId === null || $fecha === '') {
            return Response::error('PARAMETROS_INVALIDOS', 'habitacion_id, usuario_id y fecha son requeridos.', 400);
        }

        try {
            $a = $this->svc->reasignar($habitacionId, $usuarioId, $fecha, $motivo, $request->usuario?->id);
        } catch (AsignacionException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['asignacion' => $a->toArray()], 201);
    }

    public function reordenar(Request $request): Response
    {
        $usuarioId = $request->inputInt('usuario_id');
        $fecha = $request->inputString('fecha');
        $orden = $request->input('orden');

        if ($usuarioId === null || $fecha === '' || !is_array($orden)) {
            return Response::error('PARAMETROS_INVALIDOS', 'usuario_id, fecha y orden son requeridos.', 400);
        }

        try {
            $this->svc->reordenarCola($usuarioId, $fecha, array_values(array_map('intval', $orden)), $request->usuario?->id);
        } catch (AsignacionException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['ok' => true]);
    }

    public function colaTrabajador(Request $request): Response
    {
        $usuarioId = $request->rutaInt('id');
        $fecha = $request->query['fecha'] ?? date('Y-m-d');
        if ($usuarioId === null) {
            return Response::error('ID_INVALIDO', 'usuario_id inválido.', 400);
        }
        $cola = $this->svc->colaDelTrabajador($usuarioId, is_string($fecha) ? $fecha : date('Y-m-d'));
        return Response::ok(['cola' => $cola, 'total' => count($cola)]);
    }
}
