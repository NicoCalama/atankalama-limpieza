<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\UiConfigException;
use Atankalama\Limpieza\Services\UiConfigService;

/**
 * Apariencia configurable (Ajustes → Colores). Permiso: apariencia.editar.
 */
final class UiConfigController
{
    public function __construct(
        private readonly UiConfigService $uiConfig = new UiConfigService(),
    ) {
    }

    /** GET /api/ui-config/colores — colores efectivos + defaults + etiquetas para el editor. */
    public function obtenerColores(Request $request): Response
    {
        return Response::ok([
            'colores' => $this->uiConfig->colores(),
            'defaults' => UiConfigService::DEFAULTS,
            'etiquetas' => UiConfigService::ETIQUETAS,
        ]);
    }

    /** PUT /api/ui-config/colores — { colores: { clave: '#rrggbb', ... } }. */
    public function guardarColores(Request $request): Response
    {
        $colores = $request->input('colores');
        if (!is_array($colores)) {
            return Response::error('PARAMETROS_INVALIDOS', 'Falta el mapa de colores.', 400);
        }

        try {
            $this->uiConfig->guardarColores($colores, $request->usuario?->id);
        } catch (UiConfigException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        return Response::ok(['colores' => $this->uiConfig->colores()]);
    }
}
