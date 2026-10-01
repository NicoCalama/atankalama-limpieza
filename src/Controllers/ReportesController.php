<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\BonoAseoService;
use Atankalama\Limpieza\Services\ReportesService;

final class ReportesController
{
    public function __construct(
        private readonly ReportesService $service = new ReportesService(),
        private readonly BonoAseoService $bono = new BonoAseoService(),
    ) {
    }

    /** GET /api/reportes/kpis */
    public function kpis(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null) {
            return Response::error('NO_AUTENTICADO', 'Sesión requerida.', 401);
        }
        if (!$usuario->tienePermiso('reportes.ver')) {
            return Response::error('SIN_PERMISO', 'No tienes permiso para ver reportes.', 403);
        }

        [$desde, $hasta, $hotel, $usuarioId] = $this->parsearFiltros($request);

        // Un solo cálculo de la ficha para las tarjetas, el detalle y el selector (ReportesService::reporteKpis).
        $reporte = $this->service->reporteKpis($desde, $hasta, $hotel, $usuarioId);

        return Response::ok([
            'kpis'           => $reporte['kpis'],
            'por_trabajadora' => $reporte['por_trabajadora'],
            'trabajadoras'   => $reporte['trabajadoras'],
            'filtros'        => [
                'desde'      => $desde,
                'hasta'      => $hasta,
                'hotel'      => $hotel,
                'usuario_id' => $usuarioId,
            ],
        ]);
    }

    /**
     * GET /api/reportes/ficha?desde&hasta&hotel — la ficha completa de KPIs
     * (docs/kpis-sueldos.md): Trabajador N1-N3 y Supervisora N1-N3 sobre un rango libre.
     */
    public function ficha(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null) {
            return Response::error('NO_AUTENTICADO', 'Sesión requerida.', 401);
        }
        if (!$usuario->tienePermiso('reportes.ver')) {
            return Response::error('SIN_PERMISO', 'No tienes permiso para ver reportes.', 403);
        }

        [$desde, $hasta, $hotel] = $this->parsearFiltros($request);

        // Privacidad jerárquica de tiempos: la sección de las supervisoras (con su tiempo por
        // auditación) solo se calcula y se envía a quien tiene reportes.ver_supervisoras.
        $ficha = $this->service->fichaKpis($desde, $hasta, $hotel, $usuario->tienePermiso('reportes.ver_supervisoras'));
        $ficha['filtros'] = ['desde' => $desde, 'hasta' => $hasta, 'hotel' => $hotel];

        return Response::ok($ficha);
    }

    /** GET /api/reportes/resumen-mensual?anio=2026&mes=4&hotel=ambos */
    public function resumenMensual(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null) {
            return Response::error('NO_AUTENTICADO', 'Sesión requerida.', 401);
        }
        if (!$usuario->tienePermiso('reportes.ver')) {
            return Response::error('SIN_PERMISO', 'No tienes permiso para ver reportes.', 403);
        }

        [$anio, $mes, $hotel] = $this->parsearMes($request);
        if ($anio === null) {
            return Response::error('PARAMETROS_INVALIDOS', 'anio o mes fuera de rango.', 400);
        }

        $corte = $this->bono->corte($anio, $mes);
        $filas = $this->service->resumenMensual($anio, $mes, $hotel, $corte['valor']);

        return Response::ok([
            'anio'  => $anio,
            'mes'   => $mes,
            'hotel' => $hotel,
            'corte' => $corte,
            'trabajadores' => $filas,
        ]);
    }

    /**
     * PUT /api/reportes/corte-hab-dia  { anio, mes, valor }
     * Fija el corte de habitaciones diarias (jornada completa) del bono de aseo para ese mes.
     * Permiso reportes.editar_corte (lo exige también la ruta).
     */
    public function guardarCorte(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null) {
            return Response::error('NO_AUTENTICADO', 'Sesión requerida.', 401);
        }
        if (!$usuario->tienePermiso('reportes.editar_corte')) {
            return Response::error('SIN_PERMISO', 'No tienes permiso para editar el corte de habitaciones.', 403);
        }
        $anio  = $request->input('anio');
        $mes   = $request->input('mes');
        $valor = $request->input('valor');
        if (!is_numeric($anio) || !is_numeric($mes) || !is_numeric($valor)) {
            return Response::error('PARAMETROS_INVALIDOS', 'Indica el mes y un número de habitaciones por día.', 400);
        }
        try {
            $corte = $this->bono->guardarCorte((int) $anio, (int) $mes, (float) $valor, $usuario->id);
        } catch (\InvalidArgumentException $e) {
            return Response::error('CORTE_INVALIDO', $e->getMessage(), 400);
        }
        return Response::ok(['corte' => $corte]);
    }

    /** GET /api/reportes/resumen-mensual-auditores?anio=2026&mes=4&hotel=ambos */
    public function resumenMensualAuditores(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null) {
            return Response::error('NO_AUTENTICADO', 'Sesión requerida.', 401);
        }
        if (!$usuario->tienePermiso('reportes.ver')) {
            return Response::error('SIN_PERMISO', 'No tienes permiso para ver reportes.', 403);
        }

        [$anio, $mes, $hotel] = $this->parsearMes($request);
        if ($anio === null) {
            return Response::error('PARAMETROS_INVALIDOS', 'anio o mes fuera de rango.', 400);
        }

        $filas = $this->service->resumenMensualAuditores($anio, $mes, $hotel);

        return Response::ok([
            'anio'  => $anio,
            'mes'   => $mes,
            'hotel' => $hotel,
            'auditores' => $filas,
        ]);
    }

    /** GET /api/reportes/exportar-mensual-auditores?anio=2026&mes=4&hotel=ambos */
    public function exportarMensualAuditores(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null || !$usuario->tienePermiso('reportes.ver')) {
            return Response::error('SIN_PERMISO', 'Sin permiso.', 403);
        }

        [$anio, $mes, $hotel] = $this->parsearMes($request);
        if ($anio === null) {
            return Response::error('PARAMETROS_INVALIDOS', 'anio o mes fuera de rango.', 400);
        }

        $csv = $this->service->exportarCsvMensualAuditores($anio, $mes, $hotel);
        $filename = sprintf('reporte_auditorias_%04d-%02d.csv', $anio, $mes);

        return (new Response(200, $csv, 'text/csv; charset=utf-8'))
            ->conHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->conHeader('Cache-Control', 'no-store');
    }

    /** GET /api/reportes/auditorias-pendientes?fecha=2026-09-10&hotel=ambos */
    public function auditoriasPendientes(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null) {
            return Response::error('NO_AUTENTICADO', 'Sesión requerida.', 401);
        }
        if (!$usuario->tienePermiso('reportes.ver')) {
            return Response::error('SIN_PERMISO', 'No tienes permiso para ver reportes.', 403);
        }

        [$fecha, $hotel] = $this->parsearFechaHotel($request);

        return Response::ok($this->service->auditoriasPendientes($fecha, $hotel));
    }

    /** GET /api/reportes/exportar-auditorias-pendientes?fecha=2026-09-10&hotel=ambos */
    public function exportarAuditoriasPendientes(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null || !$usuario->tienePermiso('reportes.ver')) {
            return Response::error('SIN_PERMISO', 'Sin permiso.', 403);
        }

        [$fecha, $hotel] = $this->parsearFechaHotel($request);

        $csv = $this->service->exportarCsvAuditoriasPendientes($fecha, $hotel);
        $filename = "reporte_auditorias_pendientes_{$fecha}.csv";

        return (new Response(200, $csv, 'text/csv; charset=utf-8'))
            ->conHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->conHeader('Cache-Control', 'no-store');
    }

    /** @return array{0: string, 1: string} */
    private function parsearFechaHotel(Request $request): array
    {
        $fecha = $this->param($request, 'fecha');
        if (!$this->esFecha($fecha)) {
            $fecha = date('Y-m-d');
        }
        $hotel = $this->param($request, 'hotel', 'ambos');
        if (!in_array($hotel, ['ambos', '1_sur', 'inn'], true)) {
            $hotel = 'ambos';
        }
        return [$fecha, $hotel];
    }

    /** @return array{0: int|null, 1: int, 2: string} */
    private function parsearMes(Request $request): array
    {
        $anio = $this->paramInt($request, 'anio') ?? (int) date('Y');
        $mes  = $this->paramInt($request, 'mes') ?? (int) date('n');
        $hotel = $this->param($request, 'hotel', 'ambos');

        if ($anio < 2020 || $anio > 2100 || $mes < 1 || $mes > 12) {
            return [null, 0, 'ambos'];
        }
        if (!in_array($hotel, ['ambos', '1_sur', 'inn'], true)) {
            $hotel = 'ambos';
        }
        return [$anio, $mes, $hotel];
    }

    /** GET /api/reportes/exportar-mensual?anio=2026&mes=4&hotel=ambos */
    public function exportarMensual(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null || !$usuario->tienePermiso('reportes.ver')) {
            return Response::error('SIN_PERMISO', 'Sin permiso.', 403);
        }

        [$anio, $mes, $hotel] = $this->parsearMes($request);
        if ($anio === null) {
            return Response::error('PARAMETROS_INVALIDOS', 'anio o mes fuera de rango.', 400);
        }

        $csv = $this->service->exportarCsvMensual($anio, $mes, $hotel);
        $filename = sprintf('reporte_mensual_%04d-%02d.csv', $anio, $mes);

        return (new Response(200, $csv, 'text/csv; charset=utf-8'))
            ->conHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->conHeader('Cache-Control', 'no-store');
    }

    /** GET /api/reportes/exportar */
    public function exportar(Request $request): Response
    {
        $usuario = $request->usuario;
        if ($usuario === null || !$usuario->tienePermiso('reportes.ver')) {
            return Response::error('SIN_PERMISO', 'Sin permiso.', 403);
        }

        [$desde, $hasta, $hotel, $usuarioId] = $this->parsearFiltros($request);

        $csv      = $this->service->exportarCsv($desde, $hasta, $hotel, $usuarioId);
        $filename = "reporte_kpis_{$desde}_{$hasta}.csv";

        return (new Response(200, $csv, 'text/csv; charset=utf-8'))
            ->conHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->conHeader('Cache-Control', 'no-store');
    }

    /** @return array{0: string, 1: string, 2: string, 3: int|null} */
    private function parsearFiltros(Request $request): array
    {
        $hoy   = date('Y-m-d');
        $desde = $this->param($request, 'desde');
        $hasta = $this->param($request, 'hasta');
        $hotel = $this->param($request, 'hotel', 'ambos');

        if (!$this->esFecha($desde)) {
            $desde = $hoy;
        }
        if (!$this->esFecha($hasta)) {
            $hasta = $hoy;
        }
        if ($desde > $hasta) {
            $desde = $hasta;
        }
        // Máximo 1 año de rango
        if ((strtotime($hasta) - strtotime($desde)) > 365 * 86400) {
            $desde = date('Y-m-d', strtotime($hasta . ' -365 days'));
        }
        if (!in_array($hotel, ['ambos', '1_sur', 'inn'], true)) {
            $hotel = 'ambos';
        }

        $usuarioId = $this->paramInt($request, 'usuario_id');

        return [$desde, $hasta, $hotel, $usuarioId];
    }

    /**
     * Los filtros de Reportes viajan en la URL de un GET, así que se leen de la query.
     * Request::input() solo lee el cuerpo (POST/PUT/JSON): con él los filtros se ignoraban
     * desde que existe el módulo y todo caía en hoy / mes en curso / ambos hoteles, aunque
     * la pantalla mostrara el rango elegido (corregido el 30/09/2026).
     */
    private function param(Request $request, string $clave, string $default = ''): string
    {
        $valor = $request->query[$clave] ?? null;
        return is_string($valor) && $valor !== '' ? $valor : $default;
    }

    private function paramInt(Request $request, string $clave): ?int
    {
        $valor = $this->param($request, $clave);
        return is_numeric($valor) ? (int) $valor : null;
    }

    /** YYYY-MM-DD de un día que existe: una fecha imposible (2026-13-45) haría caer Fechas::rangoUtc. */
    private function esFecha(string $valor): bool
    {
        $fecha = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        return $fecha !== false && $fecha->format('Y-m-d') === $valor;
    }
}
