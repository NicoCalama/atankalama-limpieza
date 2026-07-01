<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services\Http;

interface HttpTransport
{
    /**
     * Ejecuta una petición HTTP.
     *
     * @param array<string, string>   $headers      Headers a enviar.
     * @param array<string, mixed>|null $cuerpo      Cuerpo de la petición o null.
     * @param string $contentType  Cómo se codifica el cuerpo: 'application/json' (default)
     *                             o 'application/x-www-form-urlencoded' (Cloudbeds API v1.1).
     */
    public function request(
        string $metodo,
        string $url,
        array $headers = [],
        ?array $cuerpo = null,
        int $timeoutSegundos = 10,
        string $contentType = 'application/json',
    ): HttpResponse;
}
