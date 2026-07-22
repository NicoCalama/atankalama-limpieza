<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Models\BitacoraAlerta;
use Atankalama\Limpieza\Services\AlertasService;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\InventarioCheckService;
use Atankalama\Limpieza\Services\InventarioImportService;

/**
 * Aplicar/rechazar los cambios de inventario detectados en Cloudbeds.
 *
 * La detección (dry-run + alerta) vive en InventarioCheckService, disparada por el cron.
 * Estos endpoints resuelven la alerta que ve la supervisora: Aceptar aplica el import,
 * Rechazar guarda la huella del set para no volver a molestar hasta que cambie.
 * Ver docs/cloudbeds-import-inventario.md.
 *
 * El cliente Cloudbeds se resuelve de forma perezosa (no en el constructor): el Kernel
 * instancia este controller al construir el router en cada request, y CloudbedsClient::desdeConfig()
 * no debe correr salvo que realmente se use un endpoint. Mismo patrón que CloudbedsController.
 */
final class InventarioController
{
    public function __construct(
        private readonly ?InventarioImportService $import = null,
        private readonly AlertasService $alertas = new AlertasService(),
        private readonly ?InventarioCheckService $check = null,
    ) {
    }

    private function importSvc(): InventarioImportService
    {
        return $this->import ?? new InventarioImportService(CloudbedsClient::desdeConfig());
    }

    private function checkSvc(): InventarioCheckService
    {
        return $this->check ?? new InventarioCheckService($this->importSvc(), $this->alertas);
    }

    /** POST /api/inventario/aplicar — aplica el import y resuelve la alerta { alerta_id? }. */
    public function aplicar(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $alertaId = $request->inputInt('alerta_id');

        try {
            $resultado = $this->importSvc()->importar(null, false);
        } catch (\Throwable $e) {
            return Response::error('IMPORT_FALLIDO', 'No pudimos actualizar el inventario. Intenta de nuevo en un momento.', 502);
        }

        if ($alertaId !== null) {
            $this->alertas->resolver($alertaId, BitacoraAlerta::RESOLUCION_ACCION_USUARIO, $request->usuario->id, 'aceptar');
        }
        // El estado cambió: olvidar cualquier rechazo previo.
        $this->checkSvc()->limpiarRechazo();

        return Response::ok(['totales' => $resultado['totales']]);
    }

    /** POST /api/inventario/rechazar — descarta los cambios sin aplicarlos { alerta_id }. */
    public function rechazar(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $alertaId = $request->inputInt('alerta_id');
        if ($alertaId === null) {
            return Response::error('ALERTA_REQUERIDA', 'Falta la alerta a rechazar.', 400);
        }

        // Guardar la huella del set rechazado ANTES de resolver (después la alerta ya no existe).
        $check = $this->checkSvc();
        $huella = $check->huellaDeAlerta($alertaId);
        if ($huella !== null) {
            $check->registrarRechazo($huella);
        }
        $this->alertas->resolver($alertaId, BitacoraAlerta::RESOLUCION_DESCARTADA, $request->usuario->id, 'rechazar');

        return Response::ok(['rechazada' => true]);
    }
}
