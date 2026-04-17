<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\Copilot\CopilotService;

final class CopilotController
{
    public function __construct(
        private readonly CopilotService $svc = new CopilotService(),
    ) {
    }

    public function mensaje(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $texto = $request->inputString('mensaje');
        if ($texto === '') {
            return Response::error('MENSAJE_VACIO', 'El mensaje no puede estar vacío.', 400);
        }
        $conversacionId = $request->inputInt('conversacion_id');

        $resultado = $this->svc->enviarMensaje($texto, $request->usuario, $conversacionId);

        return Response::ok([
            'conversacion_id' => $resultado['conversacion_id'],
            'respuesta' => $resultado['respuesta'],
        ], $resultado['error'] !== null ? 200 : 200);
    }

    public function listarConversaciones(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $conversaciones = $this->svc->listarConversaciones($request->usuario->id);
        return Response::ok(['conversaciones' => $conversaciones]);
    }

    public function obtenerConversacion(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'conversacion_id inválido.', 400);
        }
        $esAdmin = $request->usuario->tienePermiso('copilot.ver_historial_todos');
        $data = $this->svc->obtenerConversacion($id, $request->usuario->id, $esAdmin);
        if ($data === null) {
            return Response::error('CONVERSACION_NO_ENCONTRADA', 'Conversación no encontrada.', 404);
        }
        return Response::ok($data);
    }

    public function listarTodasConversaciones(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $conversaciones = $this->svc->listarTodasConversaciones();
        return Response::ok(['conversaciones' => $conversaciones]);
    }

    public function borrarConversacion(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'conversacion_id inválido.', 400);
        }
        $ok = $this->svc->borrarConversacion($id, $request->usuario->id);
        if (!$ok) {
            return Response::error('CONVERSACION_NO_ENCONTRADA', 'Conversación no encontrada.', 404);
        }
        return Response::ok(['ok' => true]);
    }
}
