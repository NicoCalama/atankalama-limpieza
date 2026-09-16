<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Helpers\ExcelExport;
use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\ChecklistException;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\CloudbedsSyncService;
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
        // (llegar acá con !$puedeVerTodas implica $puedeVerPropias, por el guard de arriba)
        if (!$puedeVerTodas) {
            $hoy = date('Y-m-d');
            if (!$this->asignaciones->esHabitacionAsignadaA($id, $usuario->id, $hoy)) {
                return Response::error('SIN_PERMISO', 'No tienes esta habitación asignada.', 403);
            }
        }

        return Response::ok(['habitacion' => $detalle]);
    }

    public function actualizarEstructura(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ID de habitación inválido.', 400);
        }

        $edificioId = $request->inputInt('edificio_id');
        $edificio = $request->input('edificio');
        $edificio = is_string($edificio) && trim($edificio) !== '' ? trim($edificio) : null;
        $piso = $request->inputInt('piso');

        try {
            $this->habitaciones->actualizarEstructura($id, $edificioId, $edificio, $piso, $request->usuario?->id);
        } catch (HabitacionException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        return Response::ok(['mensaje' => 'Estructura actualizada']);
    }

    public function listarHoteles(Request $request): Response
    {
        $hoteles = array_map(fn($h) => $h->toArray(), $this->hoteles->listar(false));
        return Response::ok(['hoteles' => $hoteles]);
    }

    /**
     * GET /api/habitaciones/{id}/historial
     * Historial de limpiezas: ejecuciones (quién, cuándo) + veredicto de auditoría.
     * Gateado por permiso habitaciones.ver_historial (el trabajador NO lo tiene:
     * la respuesta incluye timestamps, que le son invisibles por regla de diseño).
     */
    public function historial(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ID de habitación inválido.', 400);
        }

        $habitacion = $this->habitaciones->obtenerDetalle($id);
        if ($habitacion === null) {
            return Response::error('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada.', 404);
        }

        $historial = $this->checklist->historialDeHabitacion($id);

        return Response::ok([
            'habitacion_id' => $id,
            'historial' => $historial,
            'total' => count($historial),
        ]);
    }

    /**
     * GET /api/habitaciones/{id}/historial/exportar
     * Excel con el historial de limpiezas completo (sin el tope de 20 de la pantalla).
     * Mismo permiso que /historial: habitaciones.ver_historial.
     */
    public function historialExportar(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ID de habitación inválido.', 400);
        }

        $habitacion = $this->habitaciones->obtenerDetalle($id);
        if ($habitacion === null) {
            return Response::error('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada.', 404);
        }

        $filas = [['Inicio', 'Fin', 'Trabajador', 'Estado', 'Veredicto', 'Inspector', 'Comentario inspección']];
        foreach ($this->checklist->historialCompletoDeHabitacion($id) as $h) {
            $filas[] = [
                (string) ($h['timestamp_inicio'] ?? ''),
                (string) ($h['timestamp_fin'] ?? ''),
                (string) ($h['trabajador_nombre'] ?? ''),
                (string) ($h['estado'] ?? ''),
                (string) ($h['veredicto'] ?? ''),
                (string) ($h['auditor_nombre'] ?? ''),
                (string) ($h['auditoria_comentario'] ?? ''),
            ];
        }

        return ExcelExport::responder($filas, "historial-limpiezas-hab-{$habitacion['numero']}.xlsx");
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

        $estadosConEjecucion = ['completada_pendiente_auditoria', 'aprobada', 'aprobada_con_observacion', 'aprobada_automatica', 'rechazada'];
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
                // La auditoría debe corresponder a ESTA ejecución, no a la última de la
                // habitación: una pieza rechazada y re-limpiada tiene una ejecución nueva
                // sin auditar, y usar la auditoría vieja ocultaría los botones de veredicto.
                $aud = $this->auditorias->obtenerDeEjecucion($ultima->id);
                if ($aud !== null) {
                    $auditoria = $aud->toArray();
                }
            }
        }

        return Response::ok([
            'habitacion' => $habitacion,
            'ejecucion' => $ejecucion,
            'items' => $items,
            'auditoria' => $auditoria,
        ]);
    }

    /**
     * POST /api/habitaciones/{id}/marcar-limpia
     * Atajo administrativo (Admin/Supervisora): marca la habitación como limpia sin
     * checklist de trabajador. Queda pendiente de auditoría. Gateado por el permiso
     * habitaciones.marcar_limpia_manual (ver PermissionCheck en Kernel).
     */
    public function marcarLimpiaManual(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null || $request->usuario === null) {
            return Response::error('ID_INVALIDO', 'ID de habitación inválido.', 400);
        }

        try {
            $this->checklist->marcarLimpiaManual($id, $request->usuario->id);
        } catch (ChecklistException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        $habitacion = $this->habitaciones->obtenerDetalle($id);
        return Response::ok(['habitacion' => $habitacion]);
    }

    /**
     * POST /api/habitaciones/{id}/marcar-sucia
     * Atajo administrativo (Admin/Supervisora): fuerza la habitación de vuelta a 'sucia'.
     * Es una transición ya válida en EstadoHabitacionService (aprobada, aprobada_con_observacion,
     * aprobada_automatica, rechazada o en_progreso → sucia), no hace falta forzar. No crea ni toca ejecuciones_checklist: no hay créditos
     * de por medio para nadie. Gateado por habitaciones.marcar_limpia_manual (mismo permiso
     * que "marcar limpia" — ver PermissionCheck en Kernel).
     */
    public function marcarSuciaManual(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null || $request->usuario === null) {
            return Response::error('ID_INVALIDO', 'ID de habitación inválido.', 400);
        }

        $habitacion = $this->habitaciones->obtener($id);
        if ($habitacion === null) {
            return Response::error('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada.', 404);
        }

        $estadosValidos = [
            Habitacion::ESTADO_EN_PROGRESO,
            Habitacion::ESTADO_APROBADA,
            Habitacion::ESTADO_APROBADA_CON_OBSERVACION,
            Habitacion::ESTADO_RECHAZADA,
        ];
        if (!in_array($habitacion->estado, $estadosValidos, true)) {
            return Response::error(
                'ESTADO_INVALIDO_PARA_MARCAR_SUCIA',
                'La habitación no está en un estado que permita marcarla como sucia.',
                409
            );
        }

        try {
            $this->habitaciones->cambiarEstado($id, Habitacion::ESTADO_SUCIA, $request->usuario->id, 'ui');
        } catch (HabitacionException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        // Si Cloudbeds todavía la ve 'clean' (huésped sin checkout real: stayover/nochero), el
        // próximo sync entrante la fuerza de vuelta a 'aprobada' y deshace este "marcar sucia" —
        // el bug reportado con la habitación 414. Best-effort: no debe tumbar la respuesta si
        // Cloudbeds falla. Ver mismo aviso en AsignacionService::avisarCloudbedsSucia().
        if ($habitacion->cloudbedsRoomId !== null) {
            try {
                $actualizada = $this->habitaciones->obtener($id);
                if ($actualizada !== null) {
                    (new CloudbedsSyncService(CloudbedsClient::desdeConfig()))->escribirEstadoDirty($actualizada);
                }
            } catch (\Throwable $e) {
                // No crítico: escribirEstadoDirty ya loguea y crea alerta P0 si la escritura falla.
            }
        }

        $detalle = $this->habitaciones->obtenerDetalle($id);
        return Response::ok(['habitacion' => $detalle]);
    }

    /**
     * POST /api/habitaciones/{id}/sin-aseo-cliente
     * Atajo administrativo (Admin/Supervisora): el huésped no quiere aseo hoy. Fuerza la
     * habitación directo a 'aprobada' (forzar:true — salta checklist Y auditoría, mismo
     * mecanismo que el auto-cierre de Cloudbeds en CloudbedsSyncService). No crea ejecución
     * ni toca ejecuciones_checklist: no hay créditos de por medio para nadie. Gateado por
     * habitaciones.marcar_limpia_manual (mismo permiso que "marcar limpia").
     */
    public function marcarSinAseoCliente(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null || $request->usuario === null) {
            return Response::error('ID_INVALIDO', 'ID de habitación inválido.', 400);
        }

        $habitacion = $this->habitaciones->obtener($id);
        if ($habitacion === null) {
            return Response::error('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada.', 404);
        }

        $estadosValidos = [
            Habitacion::ESTADO_SUCIA,
            Habitacion::ESTADO_EN_PROGRESO,
            Habitacion::ESTADO_RECHAZADA,
        ];
        if (!in_array($habitacion->estado, $estadosValidos, true)) {
            return Response::error(
                'ESTADO_INVALIDO_PARA_SIN_ASEO',
                'La habitación no está en un estado que permita marcar "sin aseo".',
                409
            );
        }

        try {
            $this->habitaciones->cambiarEstado($id, Habitacion::ESTADO_APROBADA, $request->usuario->id, 'ui', forzar: true);
        } catch (HabitacionException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        $detalle = $this->habitaciones->obtenerDetalle($id);
        return Response::ok(['habitacion' => $detalle]);
    }

    /**
     * GET /api/habitaciones/{id}/movimientos
     * Historial de movimientos de estado (manuales y automáticos), leído de audit_log.
     * Gateado por habitaciones.ver_historial, igual que /historial (ejecuciones de limpieza).
     */
    public function movimientos(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ID de habitación inválido.', 400);
        }

        $habitacion = $this->habitaciones->obtenerDetalle($id);
        if ($habitacion === null) {
            return Response::error('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada.', 404);
        }

        $movimientos = $this->habitaciones->obtenerMovimientos($id);

        return Response::ok([
            'habitacion_id' => $id,
            'movimientos' => $movimientos,
            'total' => count($movimientos),
        ]);
    }

    /**
     * GET /api/habitaciones/{id}/movimientos/exportar
     * Excel con el historial de movimientos de estado completo (sin el tope de 20 de la
     * pantalla). Mismo permiso que /movimientos: habitaciones.ver_historial.
     */
    public function movimientosExportar(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ID de habitación inválido.', 400);
        }

        $habitacion = $this->habitaciones->obtenerDetalle($id);
        if ($habitacion === null) {
            return Response::error('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada.', 404);
        }

        $filas = [['Fecha', 'Desde', 'Hasta', 'Mensaje', 'Usuario', 'Origen']];
        foreach ($this->habitaciones->obtenerMovimientosCompleto($id) as $m) {
            $filas[] = [
                (string) ($m['created_at'] ?? ''),
                (string) ($m['desde'] ?? ''),
                (string) ($m['hasta'] ?? ''),
                (string) ($m['mensaje'] ?? ''),
                (string) ($m['usuario_nombre'] ?? 'Automático (sync Cloudbeds / cron)'),
                (string) ($m['origen'] ?? ''),
            ];
        }

        return ExcelExport::responder($filas, "historial-movimientos-hab-{$habitacion['numero']}.xlsx");
    }

    /**
     * PUT /api/habitaciones/{id}/nochero
     * Marca (o actualiza la vigencia de) una habitación como "nochero". Body: {hasta: 'YYYY-MM-DD'}.
     * Gateado por habitaciones.marcar_nochero (ver PermissionCheck en Kernel).
     */
    public function marcarNochero(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ID de habitación inválido.', 400);
        }

        $hasta = $request->input('hasta');
        if (!is_string($hasta) || $hasta === '') {
            return Response::error('PARAMETROS_INVALIDOS', 'El campo hasta (YYYY-MM-DD) es requerido.', 400);
        }

        try {
            $habitacion = $this->habitaciones->marcarNochero($id, $hasta, $request->usuario?->id);
        } catch (HabitacionException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        return Response::ok(['habitacion' => $habitacion->toArray()]);
    }

    /**
     * DELETE /api/habitaciones/{id}/nochero
     * Quita la marca de nochero. Gateado por habitaciones.marcar_nochero.
     */
    public function desmarcarNochero(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ID de habitación inválido.', 400);
        }

        try {
            $habitacion = $this->habitaciones->desmarcarNochero($id, $request->usuario?->id);
        } catch (HabitacionException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        return Response::ok(['habitacion' => $habitacion->toArray()]);
    }

    /**
     * POST /api/habitaciones/{id}/nota
     * Deja (o reemplaza) la nota de Recepción para la mucama. Body: {nota: string}.
     * Gateado por habitaciones.agregar_nota (ver PermissionCheck en Kernel).
     */
    public function agregarNota(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null || $request->usuario === null) {
            return Response::error('ID_INVALIDO', 'ID de habitación inválido.', 400);
        }

        $nota = $request->inputString('nota');
        if ($nota === '') {
            return Response::error('PARAMETROS_INVALIDOS', 'El campo nota es requerido.', 400);
        }

        try {
            $habitacion = $this->habitaciones->agregarNota($id, $nota, $request->usuario->id);
        } catch (HabitacionException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        return Response::ok(['habitacion' => $habitacion->toArray()]);
    }

    /**
     * DELETE /api/habitaciones/{id}/nota
     * Quita la nota sin esperar a que se complete la limpieza. Gateado por
     * habitaciones.agregar_nota (mismo permiso que dejarla).
     */
    public function quitarNota(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ID de habitación inválido.', 400);
        }

        try {
            $habitacion = $this->habitaciones->quitarNota($id, $request->usuario?->id);
        } catch (HabitacionException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        return Response::ok(['habitacion' => $habitacion->toArray()]);
    }
}
