<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\ReportesService;

final class ReportesController
{
    public function __construct(
        private readonly ReportesService $service = new ReportesService(),
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

        $kpis           = $this->service->kpis($desde, $hasta, $hotel, $usuarioId);
        $porTrabajadora = $this->service->kpisPorTrabajadora($desde, $hasta, $hotel);
        $trabajadoras   = $this->service->trabajadoras($desde, $hasta, $hotel);

        return Response::ok([
            'kpis'           => $kpis,
            'por_trabajadora' => $porTrabajadora,
            'trabajadoras'   => $trabajadoras,
            'filtros'        => [
                'desde'      => $desde,
                'hasta'      => $hasta,
                'hotel'      => $hotel,
                'usuario_id' => $usuarioId,
            ],
        ]);
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
        $desde = $request->input('desde');
        $hasta = $request->input('hasta');
        $hotel = $request->input('hotel') ?? 'ambos';

        if (!is_string($desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
            $desde = $hoy;
        }
        if (!is_string($hasta) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
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

        $rawUid    = $request->input('usuario_id');
        $usuarioId = is_numeric($rawUid) ? (int) $rawUid : null;

        return [$desde, $hasta, $hotel, $usuarioId];
    }
}
