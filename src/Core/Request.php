<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Core;

use Atankalama\Limpieza\Models\Usuario;

final class Request
{
    /** @var array<string, mixed> */
    public array $contexto = [];

    public ?Usuario $usuario = null;

    /** @var string[] */
    public array $permisos = [];

    public ?string $sessionToken = null;

    /**
     * @param array<string, mixed> $cuerpo
     * @param array<string, string> $ruta  Parámetros capturados de la URL (ej. {id})
     * @param array<string, string> $query
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $metodo,
        public readonly string $path,
        public array $cuerpo = [],
        public array $ruta = [],
        public readonly array $query = [],
        public readonly array $cookies = [],
        public readonly array $headers = [],
        public readonly ?string $ip = null,
    ) {
    }

    public static function desdeGlobales(): self
    {
        $metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $cuerpo = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $cuerpo = $decoded;
                }
            }
        } elseif ($metodo === 'POST' || $metodo === 'PUT' || $metodo === 'PATCH') {
            $cuerpo = $_POST;
        }

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }

        return new self(
            metodo: $metodo,
            path: $path,
            cuerpo: $cuerpo,
            ruta: [],
            query: $_GET,
            cookies: $_COOKIE,
            headers: $headers,
            ip: $_SERVER['REMOTE_ADDR'] ?? null,
        );
    }

    public function input(string $clave, mixed $default = null): mixed
    {
        return $this->cuerpo[$clave] ?? $default;
    }

    public function inputString(string $clave, string $default = ''): string
    {
        $valor = $this->cuerpo[$clave] ?? $default;
        return is_string($valor) ? $valor : $default;
    }

    public function inputInt(string $clave, ?int $default = null): ?int
    {
        $valor = $this->cuerpo[$clave] ?? null;
        if ($valor === null || $valor === '') {
            return $default;
        }
        return is_numeric($valor) ? (int) $valor : $default;
    }

    public function rutaInt(string $clave): ?int
    {
        $valor = $this->ruta[$clave] ?? null;
        return $valor !== null && is_numeric($valor) ? (int) $valor : null;
    }

    public function userAgent(): ?string
    {
        return $this->headers['user-agent'] ?? null;
    }
}
