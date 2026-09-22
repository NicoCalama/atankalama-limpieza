<?php

declare(strict_types=1);

// Server embebido de PHP en desarrollo (`composer serve` = php -S ... public/index.php):
// los archivos estáticos reales de public/ se sirven tal cual y todo lo demás pasa por la
// app. Sin este router, el server embebido responde 404 por su cuenta a cualquier URI con
// extensión que no exista en public/ (p. ej. /views/recursos/*.js). En producción no aplica.
if (PHP_SAPI === 'cli-server') {
    $archivoEstatico = __DIR__ . (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (is_file($archivoEstatico) && !str_ends_with($archivoEstatico, '.php')) {
        return false;
    }
}

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
