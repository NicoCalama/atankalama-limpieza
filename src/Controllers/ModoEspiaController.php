<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\ModoEspiaService;
use Atankalama\Limpieza\Services\UsuarioException;

final class ModoEspiaController
{
    public function __construct(
        private readonly ModoEspiaService $svc = new ModoEspiaService(),
    ) {
    }

    /**
     * POST /api/usuarios/{id}/modo-espia/activar
     * Solo llega aquí sin modo espía activo (PermissionCheck exige usuarios.modo_espia,
     * y si el admin ya estuviera espiando a alguien, AuthCheck bloquearía este POST antes).
     */
    public function activar(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $objetivoId = $request->rutaInt('id');
        if ($objetivoId === null) {
            return Response::error('ID_INVALIDO', 'usuario_id inválido.', 400);
        }
        try {
            $this->svc->activar($request->usuario, (string) $request->sessionToken, $objetivoId, $request->ip);
        } catch (UsuarioException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['mensaje' => 'Modo espía activado.']);
    }

    /**
     * POST /api/modo-espia/salir
     * Única ruta mutante que AuthCheck deja pasar mientras el modo espía está activo.
     * Acá $request->usuario ya es el objetivo (sustituido por AuthCheck); el admin real
     * que sale viaja en $request->espiaAdminId.
     */
    public function salir(Request $request): Response
    {
        if ($request->espiaAdminId === null) {
            return Response::error('MODO_ESPIA_INACTIVO', 'No estás en modo espía.', 400);
        }
        $this->svc->desactivar(
            (string) $request->sessionToken,
            $request->espiaAdminId,
            $request->usuario?->id,
            $request->ip
        );
        return Response::ok(['mensaje' => 'Modo espía finalizado.']);
    }
}
