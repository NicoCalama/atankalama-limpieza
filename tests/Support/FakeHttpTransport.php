<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Support;

use Atankalama\Limpieza\Services\Http\HttpResponse;
use Atankalama\Limpieza\Services\Http\HttpTransport;

/**
 * Transport de prueba: devuelve respuestas programadas en orden.
 * Lleva un registro de las peticiones realizadas para aserciones.
 */
final class FakeHttpTransport implements HttpTransport
{
    /** @var list<HttpResponse> */
    private array $respuestas = [];

    /** @var list<array{metodo:string, url:string, headers:array<string, string>, cuerpo:array<string, mixed>|null}> */
    public array $peticiones = [];

    public function encolar(HttpResponse $respuesta): void
    {
        $this->respuestas[] = $respuesta;
    }

    public function encolarOk(int $status = 200, array $cuerpo = []): void
    {
        $this->encolar(new HttpResponse($status, json_encode($cuerpo) ?: '{}'));
    }

    public function encolarFallo(int $status, string $errorRed = ''): void
    {
        $this->encolar(new HttpResponse($status, '', $errorRed !== '' ? $errorRed : null));
    }

    public function request(
        string $metodo,
        string $url,
        array $headers = [],
        ?array $cuerpoJson = null,
        int $timeoutSegundos = 10,
    ): HttpResponse {
        $this->peticiones[] = ['metodo' => $metodo, 'url' => $url, 'headers' => $headers, 'cuerpo' => $cuerpoJson];

        if (empty($this->respuestas)) {
            return new HttpResponse(0, '', 'sin respuesta programada');
        }
        return array_shift($this->respuestas);
    }
}
