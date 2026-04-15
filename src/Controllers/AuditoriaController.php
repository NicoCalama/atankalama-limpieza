<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Services\AuditoriaException;
use Atankalama\Limpieza\Services\AuditoriaService;

final class AuditoriaController
{
    public function __construct(
        private readonly AuditoriaService $svc = new AuditoriaService(),
    ) {
    }

    public function bandeja(Request $request): Response
    {
        $hotel = $request->query['hotel'] ?? 'ambos';
        $hotel = is_string($hotel) ? $hotel : 'ambos';
        $pendientes = $this->svc->bandejaPendientes($hotel);
        return Response::ok(['pendientes' => $pendientes, 'total' => count($pendientes)]);
    }

    public function emitirVeredicto(Request $request): Response
    {
        $habitacionId = $request->rutaInt('id');
        $veredicto = $request->inputString('veredicto');
        $comentario = $request->inputString('comentario', '');
        $items = $request->input('items_desmarcados', []);

        if ($habitacionId === null || $veredicto === '' || $request->usuario === null) {
            return Response::error('PARAMETROS_INVALIDOS', 'habitacion_id, veredicto y usuario son requeridos.', 400);
        }

        $permisoRequerido = match ($veredicto) {
            Auditoria::VEREDICTO_APROBADO => 'auditoria.aprobar',
            Auditoria::VEREDICTO_APROBADO_CON_OBSERVACION => 'auditoria.aprobar_con_observacion',
            Auditoria::VEREDICTO_RECHAZADO => 'auditoria.rechazar',
            default => null,
        };
        if ($permisoRequerido === null) {
            return Response::error('VEREDICTO_INVALIDO', "Veredicto inválido: {$veredicto}.", 400);
        }
        if (!$request->usuario->tienePermiso($permisoRequerido)) {
            return Response::error('PERMISO_INSUFICIENTE', "Falta permiso {$permisoRequerido}.", 403);
        }

        $itemsIds = is_array($items) ? array_values(array_map('intval', $items)) : [];

        try {
            $auditoria = $this->svc->emitirVeredicto(
                $habitacionId,
                $request->usuario->id,
                $veredicto,
                $comentario === '' ? null : $comentario,
                $itemsIds
            );
        } catch (AuditoriaException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        return Response::ok(['auditoria' => $auditoria->toArray()], 201);
    }

    public function historial(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'auditoria_id inválido.', 400);
        }
        $auditoria = $this->svc->obtener($id);
        if ($auditoria === null) {
            return Response::error('AUDITORIA_NO_ENCONTRADA', 'Auditoría no encontrada.', 404);
        }
        return Response::ok(['auditoria' => $auditoria->toArray()]);
    }
}
