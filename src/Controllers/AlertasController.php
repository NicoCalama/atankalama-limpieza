<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Models\AlertaActiva;
use Atankalama\Limpieza\Models\BitacoraAlerta;
use Atankalama\Limpieza\Services\AlertasException;
use Atankalama\Limpieza\Services\AlertasPredictivasService;
use Atankalama\Limpieza\Services\AlertasService;

final class AlertasController
{
    public function __construct(
        private readonly AlertasService $svc = new AlertasService(),
        private readonly AlertasPredictivasService $predictivas = new AlertasPredictivasService(),
    ) {
    }

    public function activas(Request $request): Response
    {
        $hotel = is_string($request->query['hotel'] ?? null) ? (string) $request->query['hotel'] : null;
        $bandeja = $this->svc->bandejaTop($hotel, 5);
        return Response::ok($bandeja);
    }

    public function listar(Request $request): Response
    {
        $hotel = is_string($request->query['hotel'] ?? null) ? (string) $request->query['hotel'] : null;
        $alertas = $this->svc->listarActivas($hotel);
        return Response::ok([
            'alertas' => array_map(static fn(AlertaActiva $a) => $a->toArray(), $alertas),
            'total' => count($alertas),
        ]);
    }

    public function ejecutarAccion(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'Usuario no autenticado.', 401);
        }
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'alerta_id inválido.', 400);
        }
        $accion = $request->inputString('accion', '');
        if ($accion === '') {
            return Response::error('ACCION_REQUERIDA', 'accion es requerida.', 400);
        }

        try {
            $this->svc->resolver(
                $id,
                BitacoraAlerta::RESOLUCION_ACCION_USUARIO,
                $request->usuario->id,
                $accion
            );
        } catch (AlertasException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['resuelta' => true]);
    }

    public function bitacora(Request $request): Response
    {
        $tipo = is_string($request->query['tipo'] ?? null) ? (string) $request->query['tipo'] : null;
        $limit = (int) ($request->query['limit'] ?? 100);
        if ($limit < 1 || $limit > 500) {
            $limit = 100;
        }
        $registros = $this->svc->listarBitacora($tipo, $limit);
        return Response::ok(['registros' => $registros, 'total' => count($registros)]);
    }

    public function listarConfig(Request $request): Response
    {
        return Response::ok(['config' => $this->svc->listarConfig()]);
    }

    public function actualizarConfig(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'Usuario no autenticado.', 401);
        }
        $payload = $request->input('config');
        if (!is_array($payload) || $payload === []) {
            return Response::error('PAYLOAD_INVALIDO', 'config debe ser objeto no vacío.', 400);
        }
        Database::transaction(function () use ($payload, $request): void {
            foreach ($payload as $clave => $valor) {
                $this->svc->actualizarConfig((string) $clave, (string) $valor, $request->usuario->id);
            }
        });
        return Response::ok(['config' => $this->svc->listarConfig()]);
    }

    public function recalcular(Request $request): Response
    {
        $stats = $this->predictivas->recalcularTodos();
        return Response::ok($stats);
    }
}
