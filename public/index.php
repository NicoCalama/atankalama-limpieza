<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Kernel;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;

Config::load(dirname(__DIR__));

$request = Request::desdeGlobales();
$router = Kernel::construirRouter();

try {
    $response = $router->despachar($request);
} catch (\Throwable $e) {
    Logger::error('http', 'excepción no controlada: ' . $e->getMessage(), [
        'path' => $request->path,
        'metodo' => $request->metodo,
        'trace' => $e->getTraceAsString(),
    ]);
    $mostrar = Config::getBool('APP_DEBUG', false) ? $e->getMessage() : 'Ocurrió un error inesperado.';
    $response = Response::error('ERROR_INTERNO', $mostrar, 500);
}

$response->emitir();
