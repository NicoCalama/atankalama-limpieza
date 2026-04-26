<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\AsignacionException;
use Atankalama\Limpieza\Services\AsignacionService;

final class AsignacionesController
{
    public function __construct(
        private readonly AsignacionService $svc = new AsignacionService(),
    ) {
    }

    public function crear(Request $request): Response
    {
        $habitacionIds = $request->input('habitacion_ids');
        $usuarioId = $request->inputInt('usuario_id');
        $fecha = $request->inputString('fecha');

        if (!is_array($habitacionIds) || $habitacionIds === [] || $usuarioId === null || $fecha === '') {
            return Response::error('PARAMETROS_INVALIDOS', 'habitacion_ids, usuario_id y fecha son requeridos.', 400);
        }
        $ids = array_values(array_map('intval', $habitacionIds));

        try {
            $creadas = $this->svc->asignarMultiple($ids, $usuarioId, $fecha, $request->usuario?->id);
        } catch (AsignacionException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        return Response::ok([
            'asignaciones' => array_map(fn($a) => $a->toArray(), $creadas),
            'total' => count($creadas),
        ], 201);
    }

    public function auto(Request $request): Response
    {
        $hotel = $request->inputString('hotel', 'ambos');
        $fecha = $request->inputString('fecha');
        if ($fecha === '') {
            return Response::error('PARAMETROS_INVALIDOS', 'fecha es requerida (YYYY-MM-DD).', 400);
        }

        try {
            $resultado = $this->svc->autoAsignar($hotel, $fecha);
        } catch (AsignacionException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        return Response::ok([
            'asignaciones' => array_map(fn($a) => $a->toArray(), $resultado['asignaciones']),
            'habitaciones' => $resultado['habitaciones'],
            'trabajadores' => $resultado['trabajadores'],
        ], 201);
    }

    public function reasignar(Request $request): Response
    {
        $habitacionId = $request->inputInt('habitacion_id');
        $usuarioId = $request->inputInt('usuario_id');
        $fecha = $request->inputString('fecha');
        $motivo = $request->inputString('motivo', 'reasignacion_manual');

        if ($habitacionId === null || $usuarioId === null || $fecha === '') {
            return Response::error('PARAMETROS_INVALIDOS', 'habitacion_id, usuario_id y fecha son requeridos.', 400);
        }

        try {
            $a = $this->svc->reasignar($habitacionId, $usuarioId, $fecha, $motivo, $request->usuario?->id);
        } catch (AsignacionException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['asignacion' => $a->toArray()], 201);
    }

    public function reordenar(Request $request): Response
    {
        $usuarioId = $request->inputInt('usuario_id');
        $fecha = $request->inputString('fecha');
        $orden = $request->input('orden');

        if ($usuarioId === null || $fecha === '' || !is_array($orden)) {
            return Response::error('PARAMETROS_INVALIDOS', 'usuario_id, fecha y orden son requeridos.', 400);
        }

        try {
            $this->svc->reordenarCola($usuarioId, $fecha, array_values(array_map('intval', $orden)), $request->usuario?->id);
        } catch (AsignacionException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['ok' => true]);
    }

    public function colaTrabajador(Request $request): Response
    {
        $usuarioId = $request->rutaInt('id');
        $fecha = $request->query['fecha'] ?? date('Y-m-d');
        if ($usuarioId === null) {
            return Response::error('ID_INVALIDO', 'usuario_id inválido.', 400);
        }
        $solicitante = $request->usuario;
        if ($solicitante === null) {
            return Response::error('NO_AUTENTICADO', 'Sesión requerida.', 401);
        }

        $esPropia = $usuarioId === $solicitante->id;
        $puedeVerTodas = $solicitante->tienePermiso('asignaciones.asignar_manual');
        if (!$esPropia && !$puedeVerTodas) {
            return Response::error('SIN_PERMISO', 'No puedes ver la cola de otro trabajador.', 403);
        }

        $cola = $this->svc->colaDelTrabajador($usuarioId, is_string($fecha) ? $fecha : date('Y-m-d'));
        return Response::ok(['cola' => $cola, 'total' => count($cola)]);
    }

    /**
     * Vista consolidada para la página de Asignaciones:
     *   - habitaciones "sucia" sin asignar hoy (agrupadas por hotel)
     *   - trabajadores con turno hoy, con su cola (habitaciones + estados)
     */
    public function vista(Request $request): Response
    {
        $hotel = $request->query['hotel'] ?? 'ambos';
        $fecha = $request->query['fecha'] ?? date('Y-m-d');
        $hotel = is_string($hotel) ? $hotel : 'ambos';
        $fecha = is_string($fecha) ? $fecha : date('Y-m-d');

        $filtroHotel = ($hotel === 'ambos') ? null : $hotel;

        // Habitaciones sucias SIN asignación activa hoy
        $sqlSin = 'SELECT h.id, h.numero, h.estado, ho.codigo AS hotel_codigo, ho.nombre AS hotel_nombre, th.nombre AS tipo_nombre
                     FROM habitaciones h
                     JOIN hoteles ho ON ho.id = h.hotel_id
                     JOIN tipos_habitacion th ON th.id = h.tipo_habitacion_id
                LEFT JOIN asignaciones a ON a.habitacion_id = h.id AND a.fecha = ? AND a.activa = 1
                    WHERE h.activa = 1
                      AND h.estado = \'sucia\'
                      AND a.id IS NULL';
        $paramsSin = [$fecha];
        if ($filtroHotel !== null) {
            $sqlSin .= ' AND ho.codigo = ?';
            $paramsSin[] = $filtroHotel;
        }
        $sqlSin .= ' ORDER BY ho.codigo, h.numero';
        $sinAsignar = \Atankalama\Limpieza\Core\Database::fetchAll($sqlSin, $paramsSin);

        // Trabajadores con turno hoy (filtrados por hotel si aplica)
        $sqlTr = 'SELECT u.id, u.nombre, u.rut, u.hotel_default
                    FROM usuarios u
                    JOIN usuarios_turnos ut ON ut.usuario_id = u.id
                   WHERE ut.fecha = ?
                     AND u.activo = 1';
        $paramsTr = [$fecha];
        if ($filtroHotel !== null) {
            $sqlTr .= " AND (u.hotel_default = ? OR u.hotel_default = 'ambos')";
            $paramsTr[] = $filtroHotel;
        }
        $sqlTr .= ' ORDER BY u.nombre';
        $trabajadores = \Atankalama\Limpieza\Core\Database::fetchAll($sqlTr, $paramsTr);

        $trabajadoresVista = [];
        foreach ($trabajadores as $tr) {
            $cola = $this->svc->colaDelTrabajador((int) $tr['id'], $fecha);
            if ($filtroHotel !== null) {
                $cola = array_values(array_filter(
                    $cola,
                    static fn(array $h) => ($h['hotel_codigo'] ?? null) === $filtroHotel
                ));
            }
            $pendientes = 0;
            $enProgreso = 0;
            $completadas = 0;
            $rechazadas = 0;
            foreach ($cola as $h) {
                $estado = $h['estado'] ?? '';
                if ($estado === 'sucia') $pendientes++;
                elseif ($estado === 'en_progreso') $enProgreso++;
                elseif (in_array($estado, ['completada_pendiente_auditoria', 'aprobada', 'aprobada_con_observacion'], true)) $completadas++;
                elseif ($estado === 'rechazada') $rechazadas++;
            }
            $trabajadoresVista[] = [
                'usuario' => [
                    'id' => (int) $tr['id'],
                    'nombre' => $tr['nombre'],
                    'rut' => $tr['rut'],
                    'hotel_default' => $tr['hotel_default'],
                ],
                'cola' => $cola,
                'progreso' => [
                    'pendientes' => $pendientes,
                    'en_progreso' => $enProgreso,
                    'completadas' => $completadas,
                    'rechazadas' => $rechazadas,
                    'total' => count($cola),
                ],
            ];
        }

        return Response::ok([
            'fecha' => $fecha,
            'hotel' => $hotel,
            'sin_asignar' => $sinAsignar,
            'trabajadores' => $trabajadoresVista,
        ]);
    }
}
