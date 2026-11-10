<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\PasswordService;
use Atankalama\Limpieza\Services\SupervisoresService;
use Atankalama\Limpieza\Services\UsuarioException;
use Atankalama\Limpieza\Services\UsuarioService;

final class UsuariosController
{
    public function __construct(
        private readonly UsuarioService $svc = new UsuarioService(),
        private readonly PasswordService $passwords = new PasswordService(),
        private readonly SupervisoresService $supervisores = new SupervisoresService(),
    ) {
    }

    /**
     * v7.22: supervisores de la persona y a quiénes se puede elegir (los que ven todos los tickets).
     * GET /api/usuarios/{id}/supervisores — usuarios.ver.
     */
    public function supervisores(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'usuario_id inválido.', 400);
        }
        if ($this->svc->buscarPorId($id) === null) {
            return Response::error('USUARIO_NO_ENCONTRADO', 'Usuario no encontrado.', 404);
        }
        return Response::ok([
            'supervisores' => $this->supervisores->supervisoresDe($id),
            'candidatos' => $this->supervisores->candidatos($id),
        ]);
    }

    /** POST /api/usuarios/{id}/supervisores { supervisor_id } — usuarios.editar. */
    public function agregarSupervisor(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $id = $request->rutaInt('id');
        $supervisorId = $request->inputInt('supervisor_id');
        if ($id === null || $supervisorId === null || $supervisorId <= 0) {
            return Response::error('DATOS_INVALIDOS', 'Elige a quién agregar como supervisor.', 400);
        }
        try {
            $this->supervisores->agregar($id, $supervisorId, $request->usuario->id);
        } catch (UsuarioException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['supervisores' => $this->supervisores->supervisoresDe($id)]);
    }

    /** DELETE /api/usuarios/{id}/supervisores/{supervisorId} — usuarios.editar. */
    public function quitarSupervisor(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $id = $request->rutaInt('id');
        $supervisorId = $request->rutaInt('supervisorId');
        if ($id === null || $supervisorId === null) {
            return Response::error('ID_INVALIDO', 'usuario_id inválido.', 400);
        }
        $this->supervisores->quitar($id, $supervisorId, $request->usuario->id);
        return Response::ok(['supervisores' => $this->supervisores->supervisoresDe($id)]);
    }

    public function listar(Request $request): Response
    {
        $filtros = [];
        if (isset($request->query['activo'])) {
            $filtros['activo'] = ((string) $request->query['activo']) === '1';
        }
        if (isset($request->query['rol']) && is_string($request->query['rol'])) {
            $filtros['rol'] = (string) $request->query['rol'];
        }
        if (isset($request->query['busqueda']) && is_string($request->query['busqueda'])) {
            $filtros['busqueda'] = (string) $request->query['busqueda'];
        }
        $usuarios = $this->svc->listar($filtros);
        return Response::ok(['usuarios' => $usuarios, 'total' => count($usuarios)]);
    }

    public function obtener(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'usuario_id inválido.', 400);
        }
        $u = $this->svc->buscarPorId($id);
        if ($u === null) {
            return Response::error('USUARIO_NO_ENCONTRADO', 'Usuario no encontrado.', 404);
        }
        return Response::ok(['usuario' => $u->toArrayPublico()]);
    }

    public function crear(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $datos = [
            'rut' => $request->inputString('rut'),
            'nombre' => $request->inputString('nombre'),
            'email' => $request->inputString('email', ''),
            'hotel_default' => $request->inputString('hotel_default', ''),
            'jornada' => $request->inputString('jornada', ''),
            'roles' => $request->input('roles', []),
        ];
        if ($datos['email'] === '') {
            $datos['email'] = null;
        }
        if ($datos['hotel_default'] === '') {
            $datos['hotel_default'] = null;
        }
        if ($datos['jornada'] === '') {
            $datos['jornada'] = null;
        }

        try {
            $resultado = $this->svc->crear($datos, $request->usuario->id, $this->passwords);
        } catch (UsuarioException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok([
            'usuario' => $resultado['usuario']->toArrayPublico(),
            'password_temporal' => $resultado['password_temporal'],
        ], 201);
    }

    public function actualizar(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'usuario_id inválido.', 400);
        }
        $datos = [];
        foreach (['rut', 'nombre', 'email', 'hotel_default', 'jornada', 'tema_preferido'] as $k) {
            if (array_key_exists($k, $request->cuerpo)) {
                $datos[$k] = $request->cuerpo[$k];
            }
        }
        try {
            $u = $this->svc->actualizar($id, $datos, $request->usuario->id);
        } catch (UsuarioException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['usuario' => $u->toArrayPublico()]);
    }

    public function activar(Request $request): Response
    {
        return $this->cambiarActivo($request, true);
    }

    public function desactivar(Request $request): Response
    {
        return $this->cambiarActivo($request, false);
    }

    public function eliminar(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'usuario_id inválido.', 400);
        }
        if ($id === $request->usuario->id) {
            return Response::error(
                'AUTO_ELIMINACION_NO_PERMITIDA',
                'No puedes eliminarte a ti mismo.',
                400
            );
        }
        $motivo = $request->inputString('motivo', '');
        if ($motivo === '') {
            $motivo = 'derecho_cancelacion';
        }
        try {
            $this->svc->eliminar($id, $request->usuario->id, $motivo);
        } catch (UsuarioException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['eliminado' => true, 'usuario_id' => $id]);
    }

    /**
     * GET /api/usuarios/{id}/datos-personales
     *
     * Derecho de acceso a datos personales (Ley 19.628 art. 12).
     * Permite al propio usuario obtener un export JSON de sus datos. Un admin con
     * `usuarios.editar` también puede consultar los datos de otro usuario (excepción
     * regulatoria para soportar solicitudes que el titular hace por email/teléfono).
     *
     * Cuando lo solicita el propio usuario, los timestamps de KPI ocultos
     * (timestamp_inicio/timestamp_fin de ejecuciones_checklist) se omiten —
     * son tracking interno que el trabajador nunca debe ver.
     */
    public function exportarDatos(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'usuario_id inválido.', 400);
        }

        $esPropio = $id === $request->usuario->id;
        $puedeVerOtros = $request->usuario->tienePermiso('usuarios.editar');

        if ($esPropio && !$request->usuario->tienePermiso('usuarios.exportar_datos_propios')) {
            return Response::error('SIN_PERMISO', 'No tienes permiso para exportar tus datos.', 403);
        }
        if (!$esPropio && !$puedeVerOtros) {
            return Response::error(
                'SIN_PERMISO',
                'No tienes permiso para exportar los datos de otro usuario.',
                403
            );
        }

        try {
            $export = $this->svc->exportarDatosPersonales($id, ocultaTimestampsKpi: $esPropio);
        } catch (UsuarioException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        Logger::audit(
            $request->usuario->id,
            'usuario.exportar_datos_personales',
            'usuario',
            $id,
            ['solicitante_id' => $request->usuario->id]
        );

        return Response::ok([
            'datos' => $export,
            'generado_en' => date('c'),
            'usuario_id' => $id,
        ]);
    }

    private function cambiarActivo(Request $request, bool $activo): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'usuario_id inválido.', 400);
        }
        try {
            $u = $this->svc->activar($id, $activo, $request->usuario->id);
        } catch (UsuarioException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['usuario' => $u->toArrayPublico()]);
    }
}
