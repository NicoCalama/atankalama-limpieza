<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\CambioTareaException;
use Atankalama\Limpieza\Services\CambioTareaService;
use Atankalama\Limpieza\Services\ListasOpcionesService;
use Atankalama\Limpieza\Services\TurnoService;

/**
 * «Cambio Tarea» (v7.16): horas que alguien de Aseo estuvo hoy en otra área. Las rutas llevan
 * PermissionCheck('cambio_tarea.registrar') en Kernel; acá se re-chequea como cinturón.
 * Ver docs/cambio-tarea.md.
 */
final class CambioTareaController
{
    private const PERMISO = 'cambio_tarea.registrar';

    public function __construct(
        private readonly CambioTareaService $svc = new CambioTareaService(),
        private readonly ListasOpcionesService $listas = new ListasOpcionesService(),
        private readonly TurnoService $turnos = new TurnoService(),
    ) {
    }

    /** ¿Se puede ver otro día que no sea hoy? Programado pero apagado (decisión de Nicolás, 07/11/2026). */
    public static function navegarDiasHabilitado(): bool
    {
        return Config::getBool('CAMBIO_TAREA_NAVEGAR_DIAS', false);
    }

    /**
     * GET /api/cambio-tarea?hotel=&fecha= — el personal de Aseo del día, las áreas para elegir y, si quien
     * mira puede cargar turnos, los turnos para el que trabaja sin turno. Sin el interruptor de navegar
     * días, solo hoy; con él, también días pasados (solo lectura), nunca futuros.
     */
    public function listar(Request $request): Response
    {
        if ($request->usuario === null || !$request->usuario->tienePermiso(self::PERMISO)) {
            return Response::error('SIN_PERMISO', 'No tienes permiso para ver Cambio Tarea.', 403);
        }
        $hoy = CambioTareaService::hoy();
        $fecha = trim((string) ($request->query['fecha'] ?? ''));
        if ($fecha === '') {
            $fecha = $hoy;
        }
        if ($fecha !== $hoy && (!self::navegarDiasHabilitado() || $fecha > $hoy)) {
            return Response::error('SOLO_HOY', 'Cambio Tarea muestra solo el día de hoy.', 400);
        }
        $hotel = trim((string) ($request->query['hotel'] ?? ''));
        try {
            $personas = $this->svc->listar($fecha, $hotel === '' ? null : $hotel);
        } catch (CambioTareaException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        $areas = array_map(
            static fn(array $o): array => ['id' => $o['id'], 'nombre' => $o['nombre']],
            $this->listas->listar(ListasOpcionesService::AREA_APOYO, true)
        );
        $puedeCargarTurno = $request->usuario->tienePermiso('turnos.asignar_a_usuario');
        $turnos = $puedeCargarTurno
            ? array_map(static fn(array $t): array => [
                'id' => (int) $t['id'],
                'nombre' => (string) $t['nombre'],
                'hora_inicio' => (string) $t['hora_inicio'],
                'hora_fin' => (string) $t['hora_fin'],
            ], $this->turnos->listar(true))
            : [];

        return Response::ok([
            'fecha' => $fecha,
            'hoy' => $hoy,
            'editable' => $fecha === $hoy,
            'navegar_dias' => self::navegarDiasHabilitado(),
            'puede_cargar_turno' => $puedeCargarTurno,
            'personas' => $personas,
            'areas' => $areas,
            'turnos' => $turnos,
            'minutos_paso' => CambioTareaService::MINUTOS_PASO,
            'minutos_max_area' => CambioTareaService::MINUTOS_MAX_AREA,
            'max_areas' => CambioTareaService::MAX_AREAS,
        ]);
    }

    /** PUT /api/cambio-tarea/{usuario_id} {fecha, areas: [{area_id, minutos}]} — el juego completo de ese día. */
    public function guardar(Request $request): Response
    {
        if ($request->usuario === null || !$request->usuario->tienePermiso(self::PERMISO)) {
            return Response::error('SIN_PERMISO', 'No tienes permiso para anotar en Cambio Tarea.', 403);
        }
        $usuarioId = $request->rutaInt('usuario_id');
        $fecha = trim($request->inputString('fecha'));
        $areas = $request->input('areas', []);
        if ($usuarioId === null || $fecha === '' || !is_array($areas) || !array_is_list($areas)) {
            return Response::error('PARAMETROS_INVALIDOS', 'Faltan datos para guardar las horas.', 400);
        }
        try {
            $guardadas = $this->svc->guardar($usuarioId, $fecha, $areas, $request->usuario->id);
        } catch (CambioTareaException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['otras_areas' => $guardadas]);
    }
}
