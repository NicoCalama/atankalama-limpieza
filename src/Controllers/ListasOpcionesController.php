<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\ListasOpcionesException;
use Atankalama\Limpieza\Services\ListasOpcionesService;

/**
 * Listas de selección (v7.3). Leer las opciones activas de una lista lo puede cualquiera con sesión (las
 * ventanas de rechazar, saltar y reasignar las piden); editarlas exige listas.editar. Las rutas llevan
 * PermissionCheck en Kernel; acá se re-chequea como cinturón. Ver docs/ajustes.md §2.3.
 */
final class ListasOpcionesController
{
    private const PERMISO_EDITAR = 'listas.editar';

    public function __construct(
        private readonly ListasOpcionesService $svc = new ListasOpcionesService(),
    ) {
    }

    /** GET /api/listas — cuántas opciones tiene cada lista (tarjetas de Ajustes). */
    public function resumen(Request $request): Response
    {
        if (($error = $this->exigir($request, true)) !== null) {
            return $error;
        }
        return Response::ok(['listas' => $this->svc->resumen()]);
    }

    /** GET /api/listas/{lista}[?todos=1] — activas para elegir; con todos=1 (Ajustes) también las desactivadas. */
    public function listar(Request $request): Response
    {
        $todos = ($request->query['todos'] ?? '') === '1';
        if (($error = $this->exigir($request, $todos)) !== null) {
            return $error;
        }
        $lista = (string) ($request->ruta['lista'] ?? '');
        if (!$this->svc->existe($lista)) {
            return Response::error('LISTA_INVALIDA', 'Esa lista no existe.', 404);
        }
        return Response::ok(['opciones' => $this->svc->listar($lista, !$todos)]);
    }

    /** POST /api/listas/{lista} {nombre} */
    public function crear(Request $request): Response
    {
        if (($error = $this->exigir($request, true)) !== null) {
            return $error;
        }
        $nombre = trim($request->inputString('nombre'));
        if ($nombre === '') {
            return Response::error('PARAMETROS_INVALIDOS', 'Escribe el nombre de la opción.', 400);
        }
        try {
            $id = $this->svc->crear((string) ($request->ruta['lista'] ?? ''), $nombre, $request->usuario->id);
        } catch (ListasOpcionesException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['id' => $id], 201);
    }

    /** PUT /api/listas/{lista}/{id} {nombre?, activo?} */
    public function actualizar(Request $request): Response
    {
        if (($error = $this->exigir($request, true)) !== null) {
            return $error;
        }
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'Opción inválida.', 400);
        }
        $datos = [];
        if (array_key_exists('nombre', $request->cuerpo)) {
            $datos['nombre'] = $request->inputString('nombre');
        }
        if (array_key_exists('activo', $request->cuerpo)) {
            $activo = $request->input('activo');
            if (!is_bool($activo)) {
                return Response::error('PARAMETROS_INVALIDOS', 'Indica si la opción queda activa o no.', 400);
            }
            $datos['activo'] = $activo;
        }
        try {
            $this->svc->actualizar((string) ($request->ruta['lista'] ?? ''), $id, $datos, $request->usuario->id);
        } catch (ListasOpcionesException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['ok' => true]);
    }

    /** PUT /api/listas/{lista}/orden {ids: [int, ...]} — todas las opciones de la lista, en el orden nuevo. */
    public function reordenar(Request $request): Response
    {
        if (($error = $this->exigir($request, true)) !== null) {
            return $error;
        }
        $ids = $request->input('ids');
        if (!is_array($ids) || $ids === [] || array_filter($ids, static fn($v): bool => !is_int($v)) !== []) {
            return Response::error('PARAMETROS_INVALIDOS', 'Falta el orden de las opciones.', 400);
        }
        try {
            $this->svc->reordenar((string) ($request->ruta['lista'] ?? ''), array_values($ids), $request->usuario->id);
        } catch (ListasOpcionesException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['ok' => true]);
    }

    /** Sesión y, si $editar, el permiso de edición. null si puede seguir. */
    private function exigir(Request $request, bool $editar): ?Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'Sesión requerida.', 401);
        }
        if ($editar && !$request->usuario->tienePermiso(self::PERMISO_EDITAR)) {
            return Response::error('SIN_PERMISO', 'No tienes permisos para esta acción.', 403);
        }
        return null;
    }
}
