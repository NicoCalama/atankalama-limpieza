<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Core;

use Atankalama\Limpieza\Middleware\Middleware;

final class Router
{
    /** @var array<int, array{metodo:string, patron:string, regex:string, params:string[], handler:callable, middlewares:Middleware[]}> */
    private array $rutas = [];

    /**
     * @param Middleware[] $middlewares
     */
    public function get(string $patron, callable $handler, array $middlewares = []): void
    {
        $this->agregar('GET', $patron, $handler, $middlewares);
    }

    /** @param Middleware[] $middlewares */
    public function post(string $patron, callable $handler, array $middlewares = []): void
    {
        $this->agregar('POST', $patron, $handler, $middlewares);
    }

    /** @param Middleware[] $middlewares */
    public function put(string $patron, callable $handler, array $middlewares = []): void
    {
        $this->agregar('PUT', $patron, $handler, $middlewares);
    }

    /** @param Middleware[] $middlewares */
    public function delete(string $patron, callable $handler, array $middlewares = []): void
    {
        $this->agregar('DELETE', $patron, $handler, $middlewares);
    }

    /** @param Middleware[] $middlewares */
    private function agregar(string $metodo, string $patron, callable $handler, array $middlewares): void
    {
        $params = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function ($m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $patron);
        $regex = '#^' . $regex . '$#';

        $this->rutas[] = [
            'metodo' => $metodo,
            'patron' => $patron,
            'regex' => $regex,
            'params' => $params,
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
    }

    public function despachar(Request $request): Response
    {
        foreach ($this->rutas as $ruta) {
            if ($ruta['metodo'] !== $request->metodo) {
                continue;
            }
            if (preg_match($ruta['regex'], $request->path, $matches) === 1) {
                array_shift($matches);
                foreach ($ruta['params'] as $i => $nombre) {
                    $request->ruta[$nombre] = $matches[$i] ?? '';
                }
                return $this->ejecutarCadena($request, $ruta['middlewares'], $ruta['handler']);
            }
        }

        return Response::error('NO_ENCONTRADO', 'Ruta no encontrada.', 404);
    }

    /**
     * @param Middleware[] $middlewares
     */
    private function ejecutarCadena(Request $request, array $middlewares, callable $handler): Response
    {
        $next = function (Request $req) use ($handler): Response {
            return $handler($req);
        };

        foreach (array_reverse($middlewares) as $mw) {
            $siguiente = $next;
            $next = function (Request $req) use ($mw, $siguiente): Response {
                return $mw->handle($req, $siguiente);
            };
        }

        return $next($request);
    }
}
