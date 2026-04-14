<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Core;

final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var array<int, array{nombre:string, valor:string, opciones:array<string, mixed>}> */
    private array $cookies = [];

    public function __construct(
        public readonly int $status,
        public readonly string $cuerpo,
        public readonly string $contentType = 'application/json; charset=utf-8',
    ) {
    }

    public static function ok(array $data, int $status = 200): self
    {
        return self::json(['ok' => true, 'data' => $data], $status);
    }

    public static function error(string $codigo, string $mensaje, int $status): self
    {
        return self::json(['ok' => false, 'error' => ['codigo' => $codigo, 'mensaje' => $mensaje]], $status);
    }

    public static function json(array $payload, int $status = 200): self
    {
        $cuerpo = json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}';
        return new self($status, $cuerpo);
    }

    public function conHeader(string $nombre, string $valor): self
    {
        $this->headers[$nombre] = $valor;
        return $this;
    }

    /**
     * @param array<string, mixed> $opciones
     */
    public function conCookie(string $nombre, string $valor, array $opciones = []): self
    {
        $this->cookies[] = ['nombre' => $nombre, 'valor' => $valor, 'opciones' => $opciones];
        return $this;
    }

    public function emitir(): void
    {
        http_response_code($this->status);
        header('Content-Type: ' . $this->contentType);
        foreach ($this->headers as $nombre => $valor) {
            header($nombre . ': ' . $valor);
        }
        foreach ($this->cookies as $c) {
            setcookie($c['nombre'], $c['valor'], $c['opciones']);
        }
        echo $this->cuerpo;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @return array<int, array{nombre:string, valor:string, opciones:array<string, mixed>}> */
    public function cookies(): array
    {
        return $this->cookies;
    }
}
