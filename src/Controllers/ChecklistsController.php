<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\ChecklistException;
use Atankalama\Limpieza\Services\ChecklistService;

final class ChecklistsController
{
    public function __construct(
        private readonly ChecklistService $svc = new ChecklistService(),
    ) {
    }

    public function listarTemplates(Request $request): Response
    {
        return Response::ok(['templates' => $this->svc->listarTemplates()]);
    }

    public function itemsDelTemplate(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'template_id inválido.', 400);
        }
        return Response::ok(['items' => $this->svc->itemsDelTemplate($id)]);
    }

    public function iniciar(Request $request): Response
    {
        $habitacionId = $request->rutaInt('id');
        $fecha = $request->inputString('fecha', date('Y-m-d'));
        if ($habitacionId === null || $request->usuario === null) {
            return Response::error('PARAMETROS_INVALIDOS', 'habitacion_id y usuario son requeridos.', 400);
        }

        try {
            $ejec = $this->svc->iniciarEjecucion($habitacionId, $request->usuario->id, $fecha);
        } catch (ChecklistException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['ejecucion' => $ejec->toArrayPublico()], 201);
    }

    public function estadoEjecucion(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ejecucion_id inválido.', 400);
        }
        $usuario = $request->usuario;
        if ($usuario === null) {
            return Response::error('NO_AUTENTICADO', 'Sesión requerida.', 401);
        }
        try {
            $estado = $this->svc->estadoEjecucion($id);
        } catch (ChecklistException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        $esPropia = (int) ($estado['ejecucion']['usuario_id'] ?? 0) === $usuario->id;
        $puedeVerTodas = $usuario->tienePermiso('habitaciones.ver_todas');
        if (!$esPropia && !$puedeVerTodas) {
            return Response::error('SIN_PERMISO', 'No puedes ver esta ejecución.', 403);
        }

        return Response::ok($estado);
    }

    public function marcarItem(Request $request): Response
    {
        $ejecId = $request->rutaInt('id');
        $itemId = $request->rutaInt('itemId');
        $marcado = (bool) $request->input('marcado', false);

        if ($ejecId === null || $itemId === null || $request->usuario === null) {
            return Response::error('PARAMETROS_INVALIDOS', 'ejecucion_id, item_id y usuario son requeridos.', 400);
        }

        try {
            $progreso = $this->svc->marcarItem($ejecId, $itemId, $marcado, $request->usuario->id);
        } catch (ChecklistException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['progreso' => $progreso]);
    }

    public function completar(Request $request): Response
    {
        $habitacionId = $request->rutaInt('id');
        if ($habitacionId === null || $request->usuario === null) {
            return Response::error('PARAMETROS_INVALIDOS', 'habitacion_id y usuario son requeridos.', 400);
        }

        $ejecId = $this->svc->obtenerEjecucionEnProgreso($habitacionId, $request->usuario->id);
        if ($ejecId === null) {
            return Response::error('EJECUCION_NO_ENCONTRADA', 'No hay ejecución en progreso para esta habitación.', 404);
        }

        try {
            $this->svc->completar($ejecId, $request->usuario->id);
        } catch (ChecklistException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['completada' => true]);
    }
}
