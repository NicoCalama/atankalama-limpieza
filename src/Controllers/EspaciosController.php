<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\AsignacionException;
use Atankalama\Limpieza\Services\EspacioException;
use Atankalama\Limpieza\Services\EspacioService;

/**
 * Áreas comunes (espacios): piscina, pasillos, patio, bodega, etc. Ver docs/areas-comunes.md
 */
final class EspaciosController
{
    public function __construct(
        private readonly EspacioService $espacios = new EspacioService(),
    ) {
    }

    /** GET /api/espacios?hotel= — lista de espacios + trabajadores con turno hoy (para pedir limpieza). */
    public function listar(Request $request): Response
    {
        $hotel = $request->query['hotel'] ?? 'ambos';
        $hotel = is_string($hotel) ? $hotel : 'ambos';
        $hoy = date('Y-m-d');

        return Response::ok([
            'espacios' => $this->espacios->listar($hotel),
            'trabajadores' => $this->espacios->trabajadoresConTurno($hoy, $hotel),
            'fecha' => $hoy,
        ]);
    }

    /** GET /api/espacios/{id} — detalle + checklist (para editar). */
    public function obtener(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ID de área común inválido.', 400);
        }
        try {
            return Response::ok($this->espacios->obtenerDetalle($id));
        } catch (EspacioException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
    }

    /** POST /api/espacios — crear { nombre, hotel, items: [] }. */
    public function crear(Request $request): Response
    {
        $nombre = $request->inputString('nombre');
        $hotel = $request->inputString('hotel');
        $items = $this->itemsDelRequest($request);
        $actorId = $request->usuario?->id;

        try {
            $id = $this->espacios->crear($nombre, $hotel, $items, $actorId);
        } catch (EspacioException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        return Response::ok(['id' => $id], 201);
    }

    /** PUT /api/espacios/{id} — editar { nombre, items: [] }. */
    public function editar(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ID de área común inválido.', 400);
        }
        $nombre = $request->inputString('nombre');
        $items = $this->itemsDelRequest($request);
        $actorId = $request->usuario?->id;

        try {
            $this->espacios->editar($id, $nombre, $items, $actorId);
        } catch (EspacioException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        return Response::ok(['id' => $id]);
    }

    /** DELETE /api/espacios/{id} — archivar. */
    public function archivar(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ID de área común inválido.', 400);
        }
        try {
            $this->espacios->archivar($id, $request->usuario?->id);
        } catch (EspacioException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['id' => $id]);
    }

    /** POST /api/espacios/{id}/pedir-limpieza — { usuario_id, fecha? }. */
    public function pedirLimpieza(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ID de área común inválido.', 400);
        }
        $usuarioId = $request->inputInt('usuario_id');
        if ($usuarioId === null) {
            return Response::error('USUARIO_REQUERIDO', 'Falta el trabajador al que asignar la limpieza.', 400);
        }
        $fecha = $request->inputString('fecha', date('Y-m-d'));

        try {
            $asignacion = $this->espacios->pedirLimpieza($id, $usuarioId, $fecha, $request->usuario?->id);
        } catch (EspacioException | AsignacionException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        return Response::ok(['asignacion_id' => $asignacion->id]);
    }

    /**
     * Normaliza el campo items del cuerpo a una lista de strings.
     *
     * @return list<string>
     */
    private function itemsDelRequest(Request $request): array
    {
        $items = $request->input('items', []);
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (is_string($item) || is_numeric($item)) {
                $out[] = (string) $item;
            }
        }
        return $out;
    }
}
