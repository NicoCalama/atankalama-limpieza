<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\ChecklistException;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\HabitacionException;
use Atankalama\Limpieza\Services\HabitacionService;
use Atankalama\Limpieza\Services\HotelService;
use Atankalama\Limpieza\Core\Database;

final class HabitacionesController
{
    public function __construct(
        private readonly HabitacionService $habitaciones = new HabitacionService(),
        private readonly HotelService $hoteles = new HotelService(),
        private readonly AuditoriaService $auditorias = new AuditoriaService(),
        private readonly ChecklistService $checklist = new ChecklistService(),
    ) {
    }

    public function listar(Request $request): Response
    {
        $hotel = $request->query['hotel'] ?? 'ambos';
        $estado = $request->query['estado'] ?? null;

        try {
            $filas = $this->habitaciones->listar(is_string($hotel) ? $hotel : 'ambos', is_string($estado) ? $estado : null);
        } catch (HabitacionException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        return Response::ok(['habitaciones' => $filas, 'total' => count($filas)]);
    }

    public function obtener(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ID de habitación inválido.', 400);
        }
        $detalle = $this->habitaciones->obtenerDetalle($id);
        if ($detalle === null) {
            return Response::error('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada.', 404);
        }
        return Response::ok(['habitacion' => $detalle]);
    }

    public function listarHoteles(Request $request): Response
    {
        $hoteles = array_map(fn($h) => $h->toArray(), $this->hoteles->listar(false));
        return Response::ok(['hoteles' => $hoteles]);
    }

    /**
     * GET /api/habitaciones/{id}/auditoria
     * Devuelve la última auditoría de la habitación (si existe) + ejecución + items.
     * Usado por la pantalla de auditoría (pendiente o histórica).
     */
    public function auditoriaActual(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ID de habitación inválido.', 400);
        }

        $habitacion = $this->habitaciones->obtenerDetalle($id);
        if ($habitacion === null) {
            return Response::error('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada.', 404);
        }

        $estadosConEjecucion = ['completada_pendiente_auditoria', 'aprobada', 'aprobada_con_observacion', 'rechazada'];
        $ejecucion = null;
        $items = [];
        $auditoria = null;

        if (in_array($habitacion['estado'], $estadosConEjecucion, true)) {
            $ejecFila = Database::fetchOne(
                "SELECT * FROM ejecuciones_checklist
                  WHERE habitacion_id = ?
                  ORDER BY id DESC LIMIT 1",
                [$id]
            );
            if ($ejecFila !== null) {
                try {
                    $estado = $this->checklist->estadoEjecucion((int) $ejecFila['id']);
                    $ejecucion = $estado['ejecucion'];
                    $items = $estado['items'];
                } catch (ChecklistException $e) {
                    // Silencioso: si falta data, devolvemos sólo habitación.
                }
            }
            $aud = $this->auditorias->obtenerDeHabitacion($id);
            if ($aud !== null) {
                $auditoria = $aud->toArray();
            }
        }

        return Response::ok([
            'habitacion' => $habitacion,
            'ejecucion' => $ejecucion,
            'items' => $items,
            'auditoria' => $auditoria,
        ]);
    }
}
