<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\TurnosImportService;

final class TurnosImportController
{
    private TurnosImportService $service;

    public function __construct()
    {
        $this->service = new TurnosImportService();
    }

    /**
     * POST /api/turnos/importar/preview
     * Recibe multipart/form-data con campo "archivo": .csv/.txt (reporte "Turnos
     * planificados" de Breik) o .xlsx (calendario semanal — así llegan hoy los
     * archivos de Breik: DNI, Nombre + una columna por fecha).
     * Analiza el archivo y guarda el resultado en sesión.
     * Devuelve preview sin filas_importar (quedan en sesión).
     */
    public function preview(Request $request): Response
    {
        if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
            return Response::json(['ok' => false, 'error' => ['codigo' => 'ARCHIVO_REQUERIDO', 'mensaje' => 'Debes subir un archivo CSV o Excel (.xlsx) válido.']], 400);
        }

        $archivo = $_FILES['archivo'];

        $ext = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
            return Response::json(['ok' => false, 'error' => ['codigo' => 'FORMATO_INVALIDO', 'mensaje' => 'El archivo debe ser .csv o .xlsx']], 400);
        }

        if ($archivo['size'] > 5 * 1024 * 1024) {
            return Response::json(['ok' => false, 'error' => ['codigo' => 'ARCHIVO_MUY_GRANDE', 'mensaje' => 'El archivo no puede superar 5 MB.']], 400);
        }

        if ($ext === 'xlsx') {
            try {
                $filas = $this->service->parsearXlsxCalendario($archivo['tmp_name']);
            } catch (\Throwable $e) {
                return Response::json(['ok' => false, 'error' => ['codigo' => 'ARCHIVO_INVALIDO', 'mensaje' => 'No se pudo leer el archivo .xlsx.']], 400);
            }
            if ($filas === []) {
                return Response::json(['ok' => false, 'error' => ['codigo' => 'ARCHIVO_VACIO', 'mensaje' => 'El archivo no tiene turnos para importar.']], 400);
            }
            $preview = $this->service->previewCalendario($filas);
        } else {
            $contenido = file_get_contents($archivo['tmp_name']);
            if ($contenido === false || trim($contenido) === '') {
                return Response::json(['ok' => false, 'error' => ['codigo' => 'ARCHIVO_VACIO', 'mensaje' => 'El archivo está vacío.']], 400);
            }
            $filas   = $this->service->parsearCsv($contenido);
            $preview = $this->service->preview($filas);
        }

        // Guardar filas_importar en sesión para el confirm
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $token = bin2hex(random_bytes(16));
        $_SESSION['breik_import_' . $token] = $preview['filas_importar'];

        // No exponer filas_importar al cliente
        unset($preview['filas_importar']);
        $preview['token'] = $token;

        return Response::json(['ok' => true, 'data' => $preview]);
    }

    /**
     * POST /api/turnos/importar/confirmar
     * Body JSON: { "token": "...", "reemplazar": bool }
     */
    public function confirmar(Request $request): Response
    {
        $token      = trim($request->inputString('token'));
        $reemplazar = (bool) $request->input('reemplazar', false);

        if ($token === '') {
            return Response::json(['ok' => false, 'error' => ['codigo' => 'TOKEN_REQUERIDO', 'mensaje' => 'Token de importación requerido.']], 400);
        }

        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $clave = 'breik_import_' . $token;

        if (!isset($_SESSION[$clave])) {
            return Response::json(['ok' => false, 'error' => ['codigo' => 'TOKEN_INVALIDO', 'mensaje' => 'La sesión de importación expiró. Sube el archivo nuevamente.']], 400);
        }

        $filasImportar = $_SESSION[$clave];
        unset($_SESSION[$clave]);

        $resultado = $this->service->importar($filasImportar, $reemplazar, $request->usuario->id);

        return Response::json(['ok' => true, 'data' => $resultado]);
    }
}
