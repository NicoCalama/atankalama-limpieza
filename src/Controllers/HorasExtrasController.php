<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\HorasExtrasException;
use Atankalama\Limpieza\Services\HorasExtrasService;

/**
 * «Horas extras» (v7.17): el total de horas extras del mes de cada persona de Aseo, en una cuadrícula del
 * año (Reportes → Mensual). Las rutas llevan PermissionCheck('horas_extras.registrar') en Kernel; acá se
 * re-chequea como cinturón. Ver docs/horas-extras.md.
 */
final class HorasExtrasController
{
    private const PERMISO = 'horas_extras.registrar';

    public function __construct(
        private readonly HorasExtrasService $svc = new HorasExtrasService(),
    ) {
    }

    /** GET /api/reportes/horas-extras?anio=2026 — la cuadrícula del año (por defecto, el año en curso). */
    public function listar(Request $request): Response
    {
        if ($request->usuario === null || !$request->usuario->tienePermiso(self::PERMISO)) {
            return Response::error('SIN_PERMISO', 'No tienes permiso para ver las horas extras.', 403);
        }
        $anio = trim((string) ($request->query['anio'] ?? ''));
        if ($anio === '') {
            $anio = substr($this->svc->hoy(), 0, 4);
        }
        if (preg_match('/^\d{4}$/', $anio) !== 1) {
            return Response::error('ANIO_INVALIDO', 'El año no es válido.', 400);
        }
        try {
            return Response::ok($this->svc->cuadricula((int) $anio));
        } catch (HorasExtrasException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
    }

    /** PUT /api/reportes/horas-extras/{usuario_id} {mes: 'YYYY-MM', horas: número | null} — null o 0 lo borra. */
    public function guardar(Request $request): Response
    {
        if ($request->usuario === null || !$request->usuario->tienePermiso(self::PERMISO)) {
            return Response::error('SIN_PERMISO', 'No tienes permiso para cargar horas extras.', 403);
        }
        $usuarioId = $request->rutaInt('usuario_id');
        $mes = trim($request->inputString('mes'));
        $horas = $request->input('horas');
        if ($usuarioId === null || $mes === '' || is_array($horas) || is_bool($horas)) {
            return Response::error('PARAMETROS_INVALIDOS', 'Faltan datos para guardar las horas extras.', 400);
        }
        try {
            $guardadas = $this->svc->guardar($usuarioId, $mes, $horas, $request->usuario->id);
        } catch (HorasExtrasException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['usuario_id' => $usuarioId, 'mes' => $mes, 'horas' => $guardadas]);
    }
}
