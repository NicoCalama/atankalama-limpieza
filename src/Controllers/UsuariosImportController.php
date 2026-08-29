<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\PasswordService;
use Atankalama\Limpieza\Services\UsuariosImportService;

final class UsuariosImportController
{
    private UsuariosImportService $service;
    private PasswordService $passwords;

    public function __construct()
    {
        $this->service = new UsuariosImportService();
        $this->passwords = new PasswordService();
    }

    /**
     * POST /api/usuarios/importar/preview
     * Recibe multipart/form-data con campo "excel_file" (.xlsx).
     * Analiza el archivo y guarda los usuarios nuevos en sesión.
     * Devuelve el resumen sin la lista completa (queda en sesión).
     */
    public function preview(Request $request): Response
    {
        if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
            return Response::error('ARCHIVO_REQUERIDO', 'Debes subir un archivo .xlsx válido.', 400);
        }

        $archivo = $_FILES['excel_file'];

        $ext = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            return Response::error('FORMATO_INVALIDO', 'El archivo debe ser .xlsx', 400);
        }

        if ($archivo['size'] > 5 * 1024 * 1024) {
            return Response::error('ARCHIVO_MUY_GRANDE', 'El archivo no puede superar 5 MB.', 400);
        }

        try {
            $filas = $this->service->parsearXlsx($archivo['tmp_name']);
        } catch (\Throwable $e) {
            return Response::error('ARCHIVO_INVALIDO', 'No se pudo leer el archivo .xlsx.', 400);
        }

        if ($filas === []) {
            return Response::error('ARCHIVO_VACIO', 'El archivo no tiene filas con DNI o Nombre.', 400);
        }

        $preview = $this->service->preview($filas);

        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $token = bin2hex(random_bytes(16));
        $_SESSION['usuarios_import_' . $token] = $preview['usuarios_nuevos'];

        unset($preview['usuarios_nuevos']);
        $preview['token'] = $token;

        return Response::ok($preview);
    }

    /**
     * POST /api/usuarios/importar/confirmar
     * Body: { "token": "...", "rol_id": 3 }
     */
    public function confirmar(Request $request): Response
    {
        $token = trim($request->inputString('token'));
        $rolId = $request->inputInt('rol_id');

        if ($token === '') {
            return Response::error('TOKEN_REQUERIDO', 'Token de importación requerido.', 400);
        }
        if ($rolId === null) {
            return Response::error('ROL_REQUERIDO', 'Debes elegir un rol para los usuarios nuevos.', 400);
        }

        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $clave = 'usuarios_import_' . $token;

        if (!isset($_SESSION[$clave])) {
            return Response::error('TOKEN_INVALIDO', 'La sesión de importación expiró. Sube el archivo nuevamente.', 400);
        }

        $usuariosNuevos = $_SESSION[$clave];
        unset($_SESSION[$clave]);

        $resultado = $this->service->importar($usuariosNuevos, $rolId, $request->usuario->id, $this->passwords);

        return Response::ok($resultado);
    }
}
