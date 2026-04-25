<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\PushService;

final class PushController
{
    public function __construct(
        private readonly PushService $push = new PushService(),
    ) {
    }

    /** GET /api/push/vapid-public-key — clave pública para el frontend */
    public function vapidPublicKey(Request $request): Response
    {
        return Response::ok(['publicKey' => Config::require('VAPID_PUBLIC_KEY')]);
    }

    /** POST /api/push/suscribir */
    public function suscribir(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null) {
            return Response::error('NO_AUTENTICADO', 'Sesión requerida.', 401);
        }

        $endpoint = $request->input('endpoint');
        $p256dh   = $request->input('p256dh');
        $auth     = $request->input('auth');

        if (!is_string($endpoint) || !is_string($p256dh) || !is_string($auth)) {
            return Response::error('DATOS_INVALIDOS', 'Faltan campos de la suscripción.', 400);
        }

        $this->push->suscribir($usuario->id, $endpoint, $p256dh, $auth);
        return Response::ok(['suscrito' => true]);
    }

    /** DELETE /api/push/suscribir */
    public function desuscribir(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null) {
            return Response::error('NO_AUTENTICADO', 'Sesión requerida.', 401);
        }

        $endpoint = $request->input('endpoint');
        if (is_string($endpoint)) {
            $this->push->desuscribir($usuario->id, $endpoint);
        } else {
            $this->push->desuscribirTodo($usuario->id);
        }

        return Response::ok(['desuscrito' => true]);
    }
}
