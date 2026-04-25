<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\NotificacionesService;

final class NotificacionesController
{
    public function __construct(
        private readonly NotificacionesService $service = new NotificacionesService(),
    ) {
    }

    /**
     * GET /api/notificaciones
     * Devuelve la lista y marca todas como leídas (Opción A).
     */
    public function listar(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null) {
            return Response::error('NO_AUTENTICADO', 'Sesión requerida.', 401);
        }

        $notificaciones = $this->service->listar($usuario->id);
        $this->service->marcarTodasLeidas($usuario->id);

        return Response::ok([
            'notificaciones' => $notificaciones,
            'total'          => count($notificaciones),
        ]);
    }

    /**
     * GET /api/notificaciones/sin-leer
     * Solo devuelve el conteo (para el badge, llamada en cada page load).
     */
    public function sinLeer(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null) {
            return Response::error('NO_AUTENTICADO', 'Sesión requerida.', 401);
        }

        return Response::ok(['sin_leer' => $this->service->sinLeer($usuario->id)]);
    }
}
