<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services\Http;

interface HttpTransport
{
    /**
     * Ejecuta una petición HTTP.
     *
     * @param array<string, string>   $headers      Headers a enviar.
     * @param array<string, mixed>|null $cuerpoJson Cuerpo (se codifica como JSON) o null.
     */
    public function request(
        string $metodo,
        string $url,
        array $headers = [],
        ?array $cuerpoJson = null,
        int $timeoutSegundos = 10,
    ): HttpResponse;
}
