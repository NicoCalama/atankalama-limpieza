<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\RbacException;
use Atankalama\Limpieza\Services\RbacService;

final class RolesController
{
    public function __construct(
        private readonly RbacService $rbac = new RbacService(),
    ) {
    }

    public function listar(Request $request): Response
    {
        return Response::ok(['roles' => $this->rbac->listarRoles()]);
    }

    public function obtener(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ID de rol inválido.', 400);
        }
        $rol = $this->rbac->obtenerRol($id);
        if ($rol === null) {
            return Response::error('ROL_NO_ENCONTRADO', 'Rol no encontrado.', 404);
        }
        return Response::ok(['rol' => $rol]);
    }

    public function crear(Request $request): Response
    {
        $nombre = $request->inputString('nombre');
        $descripcion = $request->input('descripcion');
        $permisos = $request->input('permisos', []);

        if (!is_array($permisos)) {
            return Response::error('PERMISOS_INVALIDOS', 'permisos debe ser un array.', 400);
        }

        try {
            $rolId = $this->rbac->crearRol($nombre, is_string($descripcion) ? $descripcion : null, $permisos, $request->usuario->id);
        } catch (RbacException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        return Response::ok(['rol' => $this->rbac->obtenerRol($rolId)], 201);
    }

    public function actualizar(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ID de rol inválido.', 400);
        }

        $nombre = array_key_exists('nombre', $request->cuerpo) ? (string) $request->cuerpo['nombre'] : null;
        $descripcion = array_key_exists('descripcion', $request->cuerpo)
            ? ($request->cuerpo['descripcion'] === null ? null : (string) $request->cuerpo['descripcion'])
            : null;
        $permisos = array_key_exists('permisos', $request->cuerpo) ? $request->cuerpo['permisos'] : null;

        if ($permisos !== null && !is_array($permisos)) {
            return Response::error('PERMISOS_INVALIDOS', 'permisos debe ser un array.', 400);
        }

        try {
            $this->rbac->actualizarRol($id, $nombre, $descripcion, $permisos, $request->usuario->id);
        } catch (RbacException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        return Response::ok(['rol' => $this->rbac->obtenerRol($id)]);
    }

    public function eliminar(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ID de rol inválido.', 400);
        }
        try {
            $this->rbac->eliminarRol($id, $request->usuario->id);
        } catch (RbacException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['mensaje' => 'Rol eliminado.']);
    }

    public function listarPermisos(Request $request): Response
    {
        return Response::ok(['permisos' => $this->rbac->listarPermisos()]);
    }

    public function asignarRolAUsuario(Request $request): Response
    {
        $usuarioId = $request->rutaInt('id');
        $rolId = $request->inputInt('rol_id');
        if ($usuarioId === null || $rolId === null) {
            return Response::error('CAMPOS_REQUERIDOS', 'usuario_id y rol_id son obligatorios.', 400);
        }
        try {
            $this->rbac->asignarRolAUsuario($usuarioId, $rolId, $request->usuario->id);
        } catch (RbacException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['mensaje' => 'Rol asignado.']);
    }

    public function quitarRolAUsuario(Request $request): Response
    {
        $usuarioId = $request->rutaInt('id');
        $rolId = $request->rutaInt('rolId');
        if ($usuarioId === null || $rolId === null) {
            return Response::error('CAMPOS_REQUERIDOS', 'usuario_id y rol_id son obligatorios.', 400);
        }
        $this->rbac->quitarRolAUsuario($usuarioId, $rolId, $request->usuario->id);
        return Response::ok(['mensaje' => 'Rol quitado.']);
    }
}
