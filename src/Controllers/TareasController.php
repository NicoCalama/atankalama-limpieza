<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\EspacioException;
use Atankalama\Limpieza\Services\TareasCatalogoService;

/**
 * Catálogo de tareas de las áreas comunes beta (v7.27). Ver docs/areas-comunes.md §9.
 */
final class TareasController
{
    public function __construct(
        private readonly TareasCatalogoService $catalogo = new TareasCatalogoService(),
    ) {
    }

    /** GET /api/tareas?q= — búsqueda mientras se escribe; disponible para todos los roles. */
    public function buscar(Request $request): Response
    {
        $q = $request->query['q'] ?? '';
        return Response::ok(['tareas' => $this->catalogo->buscar(is_string($q) ? $q : '')]);
    }

    /** GET /api/tareas/catalogo — todo el catálogo (Ajustes → Checklists y listas → Tareas). */
    public function catalogo(Request $request): Response
    {
        return Response::ok(['tareas' => $this->catalogo->listar()]);
    }

    /** POST /api/tareas — { nombre, creditos_m2 } */
    public function crear(Request $request): Response
    {
        $valor = $request->input('creditos_m2');
        try {
            $id = $this->catalogo->crear(
                $request->inputString('nombre'),
                is_numeric($valor) ? (float) $valor : 0.0,
                $request->usuario?->id,
            );
        } catch (EspacioException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['id' => $id], 201);
    }

    /** PUT /api/tareas/{id} — { nombre, creditos_m2 } */
    public function editar(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ID de tarea inválido.', 400);
        }
        $valor = $request->input('creditos_m2');
        try {
            $areas = $this->catalogo->editar(
                $id,
                $request->inputString('nombre'),
                is_numeric($valor) ? (float) $valor : 0.0,
                $request->usuario?->id,
            );
        } catch (EspacioException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['id' => $id, 'areas_actualizadas' => $areas]);
    }
}
