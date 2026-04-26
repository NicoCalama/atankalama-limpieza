<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\ChecklistException;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\HabitacionException;
use Atankalama\Limpieza\Services\HabitacionService;
use Atankalama\Limpieza\Services\HotelService;

final class HabitacionesController
{
    public function __construct(
        private readonly HabitacionService $habitaciones = new HabitacionService(),
        private readonly HotelService $hoteles = new HotelService(),
        private readonly AuditoriaService $auditorias = new AuditoriaService(),
        private readonly ChecklistService $checklist = new ChecklistService(),
        private readonly AsignacionService $asignaciones = new AsignacionService(),
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

        $usuario = $request->usuario;
        $puedeVerTodas = $usuario !== null && $usuario->tienePermiso('habitaciones.ver_todas');
        $puedeVerPropias = $usuario !== null && $usuario->tienePermiso('habitaciones.ver_asignadas_propias');

        if (!$puedeVerTodas && !$puedeVerPropias) {
            return Response::error('SIN_PERMISO', 'No tienes permisos para esta acción.', 403);
        }

        $detalle = $this->habitaciones->obtenerDetalle($id);
        if ($detalle === null) {
            return Response::error('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada.', 404);
        }

        // Trabajadora: solo puede ver habitaciones que le están asignadas hoy
        if (!$puedeVerTodas && $puedeVerPropias) {
            $hoy = date('Y-m-d');
            if (!$this->asignaciones->esHabitacionAsignadaA($id, $usuario->id, $hoy)) {
                return Response::error('SIN_PERMISO', 'No tienes esta habitación asignada.', 403);
            }
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
            $ultima = $this->checklist->obtenerUltimaEjecucionDeHabitacion($id);
            if ($ultima !== null) {
                try {
                    $estado = $this->checklist->estadoEjecucion($ultima->id);
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
