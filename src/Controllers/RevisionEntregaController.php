<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\CloudbedsSyncService;
use Atankalama\Limpieza\Services\ImagenAdjuntoService;
use Atankalama\Limpieza\Services\ImagenException;
use Atankalama\Limpieza\Services\RevisionEntregaException;
use Atankalama\Limpieza\Services\RevisionEntregaService;

/**
 * Inspección pre-entrega (v6.18; en código «revision_entrega»). Se registra desde la tarjeta de la pieza
 * en Habitaciones. Las rutas llevan PermissionCheck en Kernel; acá se re-chequea el permiso como
 * cinturón (igual que ReportesController). Ver docs/revision-entrega.md.
 */
final class RevisionEntregaController
{
    private const PERMISO_REGISTRAR = 'revision_entrega.registrar';
    private const PERMISO_CONFIGURAR = 'revision_entrega.configurar';

    public function __construct(
        private ?RevisionEntregaService $svc = null,
        private readonly ImagenAdjuntoService $imagenes = new ImagenAdjuntoService(),
    ) {
    }

    /**
     * Servicio con el cliente Cloudbeds real: con el interruptor prendido, un NO avisa 'dirty'.
     * Lazy, como AuditoriaController::servicio(). CLOUDBEDS_DRY_RUN hace segura esa escritura.
     */
    private function servicio(): RevisionEntregaService
    {
        return $this->svc ??= new RevisionEntregaService(
            cloudbeds: new CloudbedsSyncService(CloudbedsClient::desdeConfig()),
        );
    }

    /** GET /api/revision-entrega/formulario — motivos activos + interruptor, para la ventana del NO. */
    public function formulario(Request $request): Response
    {
        if (($error = $this->exigir($request, self::PERMISO_REGISTRAR)) !== null) {
            return $error;
        }
        return Response::ok($this->servicio()->formulario());
    }

    /**
     * POST /api/revision-entrega — multipart desde la pantalla (JSON también sirve, sin foto).
     *
     * DEFAULT APLICADO (plan v6.18 aprobado por el usuario): veredicto y foto en UN solo request.
     * La foto nunca hace fallar la revisión: si no se pudo guardar, el NO queda igual y la
     * respuesta lo dice en `foto_fallida`.
     */
    public function registrar(Request $request): Response
    {
        if (($error = $this->exigir($request, self::PERMISO_REGISTRAR)) !== null) {
            return $error;
        }

        // Cuando el cuerpo supera post_max_size, PHP vacía $_POST y $_FILES enteros: sin este
        // aviso el NO llegaría como «faltan datos» sin explicación.
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (str_contains($contentType, 'multipart/form-data') && $request->cuerpo === [] && $_FILES === []
            && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            return Response::error('CUERPO_MUY_GRANDE', 'La foto es muy pesada para el servidor. Quita la foto y vuelve a enviar.', 413);
        }

        $habitacionId = $request->inputInt('habitacion_id');
        if ($habitacionId === null) {
            return Response::error('PARAMETROS_INVALIDOS', 'Falta la habitación.', 400);
        }
        $resultado = $request->inputString('resultado');
        $motivoId = $request->inputInt('motivo_id');
        $comentario = $request->inputString('comentario');
        $key = $request->inputString('idempotency_key');
        $svc = $this->servicio();

        // Reintento de algo que ya llegó: se devuelve sin procesar la foto (no deja un .webp huérfano).
        if ($key !== '' && ($existente = $svc->reintentoVigente($key, $habitacionId, $resultado)) !== null) {
            return Response::ok(['revision' => $existente, 'repetida' => true, 'foto_fallida' => null]);
        }

        [$fotoRuta, $fotoFallida] = $resultado === RevisionEntregaService::RESULTADO_NO
            ? $this->procesarFoto()
            : [null, null];

        try {
            $r = $svc->registrar(
                $habitacionId,
                $resultado,
                $motivoId,
                $comentario !== '' ? $comentario : null,
                $fotoRuta,
                $request->usuario->id,
                $key !== '' ? $key : null,
            );
        } catch (RevisionEntregaException $e) {
            $this->borrarFoto($fotoRuta);
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        if ($r['repetida']) {
            // Carrera del doble toque: la otra petición ganó; esta foto sobra.
            $this->borrarFoto($fotoRuta);
            return Response::ok(['revision' => $r['revision'], 'repetida' => true, 'foto_fallida' => null]);
        }
        return Response::ok(['revision' => $r['revision'], 'repetida' => false, 'foto_fallida' => $fotoFallida], 201);
    }

    /**
     * Una sola foto opcional (campo `foto`). Mismo pipeline que los tickets (WebP liviano).
     * No se testea por controller: is_uploaded_file() no se puede simular (igual que tickets).
     *
     * @return array{0: ?string, 1: ?string} [ruta guardada, motivo si falló]
     */
    private function procesarFoto(): array
    {
        $archivo = $_FILES['foto'] ?? null;
        if (!is_array($archivo) || is_array($archivo['error'] ?? null) || (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }
        $error = (int) $archivo['error'];
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return [null, 'La foto es muy pesada para el servidor.'];
        }
        $tmp = (string) ($archivo['tmp_name'] ?? '');
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            return [null, 'No pudimos leer la foto.'];
        }
        try {
            $guardada = $this->imagenes->guardarComoWebp($tmp, (int) ($archivo['size'] ?? 0), RevisionEntregaService::SUBCARPETA_FOTO);
            return [$guardada['ruta'], null];
        } catch (ImagenException $e) {
            return [null, $e->getMessage()];
        } catch (\Throwable $e) {
            // Cualquier otra falla al procesar la foto (p. ej. un PHP sin GD lanza \Error): el NO igual
            // se guarda. Nunca un 500 por la foto.
            Logger::warning('revision_entrega', 'no se pudo procesar la foto', ['error' => $e->getMessage()]);
            return [null, 'No pudimos procesar la foto.'];
        }
    }

    private function borrarFoto(?string $ruta): void
    {
        if ($ruta !== null) {
            @unlink(Config::basePath() . '/public/uploads/' . $ruta);
        }
    }

    /**
     * POST /api/revision-entrega/{id}/relimpiar {trabajador_id, prioridad} — botón «Re-limpiar» de la
     * supervisora sobre una pieza que Recepción no aprobó. La ruta exige asignaciones.asignar_manual (es
     * una asignación); la prioridad (primera de la cola) exige además reordenar la cola.
     */
    public function relimpiar(Request $request): Response
    {
        if (($error = $this->exigir($request, 'asignaciones.asignar_manual')) !== null) {
            return $error;
        }
        $id = $request->rutaInt('id');
        $trabajadorId = $request->inputInt('trabajador_id');
        if ($id === null || $trabajadorId === null) {
            return Response::error('PARAMETROS_INVALIDOS', 'Elige a quién le asignas la re-limpieza.', 400);
        }
        $prioridad = $request->input('prioridad') === true;
        if ($prioridad && !$request->usuario->tienePermiso('asignaciones.reordenar_cola_trabajador')) {
            return Response::error('SIN_PERMISO', 'No tienes permiso para cambiar el orden de la cola.', 403);
        }
        try {
            $revision = $this->servicio()->pedirRelimpieza($id, $trabajadorId, $prioridad, $request->usuario->id);
        } catch (RevisionEntregaException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['revision' => $revision]);
    }

    /** GET /api/revision-entrega/motivos?todos=1 (Ajustes; el modal del NO usa /piezas). */
    public function listarMotivos(Request $request): Response
    {
        if (($error = $this->exigir($request, self::PERMISO_CONFIGURAR)) !== null) {
            return $error;
        }
        $todos = ($request->query['todos'] ?? '') === '1';
        return Response::ok(['motivos' => $this->servicio()->listarMotivos(!$todos)]);
    }

    /** POST /api/revision-entrega/motivos {nombre} */
    public function crearMotivo(Request $request): Response
    {
        if (($error = $this->exigir($request, self::PERMISO_CONFIGURAR)) !== null) {
            return $error;
        }
        $nombre = trim($request->inputString('nombre'));
        if ($nombre === '') {
            return Response::error('PARAMETROS_INVALIDOS', 'Escribe el nombre del motivo.', 400);
        }
        try {
            $id = $this->servicio()->crearMotivo($nombre, $request->usuario->id);
        } catch (RevisionEntregaException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['id' => $id], 201);
    }

    /** PUT /api/revision-entrega/motivos/{id} {nombre?, activo?} */
    public function actualizarMotivo(Request $request): Response
    {
        if (($error = $this->exigir($request, self::PERMISO_CONFIGURAR)) !== null) {
            return $error;
        }
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'Motivo inválido.', 400);
        }
        $datos = [];
        if (array_key_exists('nombre', $request->cuerpo)) {
            $datos['nombre'] = $request->inputString('nombre');
        }
        if (array_key_exists('activo', $request->cuerpo)) {
            $datos['activo'] = (bool) $request->input('activo');
        }
        try {
            $this->servicio()->actualizarMotivo($id, $datos, $request->usuario->id);
        } catch (RevisionEntregaException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['ok' => true]);
    }

    /** GET /api/revision-entrega/config */
    public function config(Request $request): Response
    {
        if (($error = $this->exigir($request, self::PERMISO_CONFIGURAR)) !== null) {
            return $error;
        }
        return Response::ok(['no_ensucia' => $this->servicio()->noEnsucia()]);
    }

    /** PUT /api/revision-entrega/config {no_ensucia: bool} */
    public function guardarConfig(Request $request): Response
    {
        if (($error = $this->exigir($request, self::PERMISO_CONFIGURAR)) !== null) {
            return $error;
        }
        $valor = $request->input('no_ensucia');
        if (!is_bool($valor)) {
            return Response::error('PARAMETROS_INVALIDOS', 'Elige si un NO solo avisa o también devuelve la pieza a limpieza.', 400);
        }
        $this->servicio()->configurarNoEnsucia($valor, $request->usuario->id);
        return Response::ok(['ok' => true, 'no_ensucia' => $valor]);
    }

    /** Sesión + permiso. null si puede seguir (y entonces $request->usuario no es null). */
    private function exigir(Request $request, string $permiso): ?Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'Sesión requerida.', 401);
        }
        if (!$request->usuario->tienePermiso($permiso)) {
            return Response::error('SIN_PERMISO', 'No tienes permisos para esta acción.', 403);
        }
        return null;
    }
}
