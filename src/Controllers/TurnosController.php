<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\TurnoException;
use Atankalama\Limpieza\Services\TurnoService;

final class TurnosController
{
    public function __construct(
        private readonly TurnoService $svc = new TurnoService(),
    ) {
    }

    public function listar(Request $request): Response
    {
        $soloActivos = !isset($request->query['todos']) || ((string) $request->query['todos']) !== '1';
        return Response::ok(['turnos' => $this->svc->listar($soloActivos)]);
    }

    public function crear(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $nombre = $request->inputString('nombre');
        $hi = $request->inputString('hora_inicio');
        $hf = $request->inputString('hora_fin');
        if ($nombre === '' || $hi === '' || $hf === '') {
            return Response::error('PARAMETROS_INVALIDOS', 'nombre, hora_inicio y hora_fin son requeridos.', 400);
        }
        try {
            $id = $this->svc->crear($nombre, $hi, $hf, $request->usuario->id);
        } catch (TurnoException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['id' => $id], 201);
    }

    public function actualizar(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'turno_id inválido.', 400);
        }
        $datos = [];
        foreach (['nombre', 'hora_inicio', 'hora_fin'] as $k) {
            $v = $request->input($k);
            if ($v !== null && is_string($v)) {
                $datos[$k] = $v;
            }
        }
        $activoIn = $request->input('activo');
        if ($activoIn !== null) {
            $datos['activo'] = (bool) $activoIn;
        }
        try {
            $this->svc->actualizar($id, $datos, $request->usuario->id);
        } catch (TurnoException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['ok' => true]);
    }

    public function asignarAUsuario(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $usuarioId = $request->rutaInt('id');
        $turnoId = $request->inputInt('turno_id');
        $fecha = $request->inputString('fecha');
        if ($usuarioId === null || $turnoId === null || $fecha === '') {
            return Response::error('PARAMETROS_INVALIDOS', 'usuario_id, turno_id y fecha son requeridos.', 400);
        }
        try {
            $id = $this->svc->asignarAUsuario($usuarioId, $turnoId, $fecha, $request->usuario->id);
        } catch (TurnoException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['id' => $id], 201);
    }

    public function asignarRangoAUsuario(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $usuarioId = $request->rutaInt('id');
        $turnoId = $request->inputInt('turno_id');
        $desde = $request->inputString('desde');
        $hasta = $request->inputString('hasta');
        $diasExcluidos = $request->input('dias_excluidos', []);
        if (!is_array($diasExcluidos)) {
            return Response::error('PARAMETROS_INVALIDOS', 'dias_excluidos debe ser una lista.', 400);
        }
        $diasExcluidos = array_map('intval', $diasExcluidos);
        if ($usuarioId === null || $turnoId === null || $desde === '' || $hasta === '') {
            return Response::error('PARAMETROS_INVALIDOS', 'usuario_id, turno_id, desde y hasta son requeridos.', 400);
        }
        try {
            $resultado = $this->svc->asignarRango($usuarioId, $turnoId, $desde, $hasta, $diasExcluidos, $request->usuario->id);
        } catch (TurnoException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok($resultado, 201);
    }

    public function quitarDeUsuario(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $usuarioId = $request->rutaInt('id');
        $fecha = is_string($request->query['fecha'] ?? null) ? (string) $request->query['fecha'] : '';
        if ($usuarioId === null || $fecha === '') {
            return Response::error('PARAMETROS_INVALIDOS', 'usuario_id y fecha son requeridos.', 400);
        }
        $this->svc->quitarDeUsuario($usuarioId, $fecha, $request->usuario->id);
        return Response::ok(['ok' => true]);
    }

    public function turnosDelDia(Request $request): Response
    {
        $fecha = is_string($request->query['fecha'] ?? null) && $request->query['fecha'] !== ''
            ? (string) $request->query['fecha']
            : date('Y-m-d');
        return Response::ok(['fecha' => $fecha, 'turnos' => $this->svc->turnosDelDia($fecha)]);
    }
}
