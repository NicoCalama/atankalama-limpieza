<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Services\Http\CurlTransport;
use Atankalama\Limpieza\Services\Http\HttpResponse;
use Atankalama\Limpieza\Services\Http\HttpTransport;

/**
 * Wrapper de la API de Cloudbeds (v1.1) con cola de reintentos.
 *
 * Los endpoints concretos deben verificarse contra la documentación actual de Cloudbeds;
 * este cliente centraliza auth, timeouts, reintentos (1s/2s/4s) y logging sanitizado.
 */
final class CloudbedsClient
{
    public const BACKOFFS_DEFAULT = [1, 2, 4];

    private readonly string $baseUrl;
    private readonly string $apiKey;
    private readonly int $timeout;

    /** @var int[] */
    private array $backoffsSegundos;

    /** @var callable */
    private $dormir;

    public function __construct(
        private readonly HttpTransport $transport = new CurlTransport(),
        ?string $baseUrl = null,
        ?string $apiKey = null,
        ?int $timeout = null,
        ?array $backoffs = null,
        ?callable $dormir = null,
    ) {
        $this->baseUrl = rtrim($baseUrl ?? (string) Config::get('CLOUDBEDS_API_BASE_URL', 'https://api.cloudbeds.com/api/v1.1'), '/');
        $this->apiKey = $apiKey ?? (string) Config::get('CLOUDBEDS_API_KEY', '');
        $this->timeout = $timeout ?? Config::getInt('CLOUDBEDS_TIMEOUT_SECONDS', 10);
        $this->backoffsSegundos = $backoffs ?? self::BACKOFFS_DEFAULT;
        $this->dormir = $dormir ?? static function (int $s): void {
            if ($s > 0) {
                sleep($s);
            }
        };
    }

    /**
     * @return array<string, mixed>  Payload del cuerpo JSON
     */
    public function obtenerHabitaciones(string $propertyId): array
    {
        $response = $this->ejecutarConReintentos('GET', '/getRooms?propertyID=' . urlencode($propertyId));
        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function obtenerEstadosHabitaciones(string $propertyId, ?string $fecha = null): array
    {
        $query = 'propertyID=' . urlencode($propertyId);
        if ($fecha !== null) {
            $query .= '&date=' . urlencode($fecha);
        }
        $response = $this->ejecutarConReintentos('GET', '/getRoomsStatus?' . $query);
        return $response->json();
    }

    /**
     * Actualiza el estado de limpieza de una habitación en Cloudbeds.
     * Retorna el HttpResponse final; lanza CloudbedsException solo en 401 inmediato.
     */
    public function actualizarEstadoHabitacion(string $propertyId, string $roomId, string $estadoCloudbeds): HttpResponse
    {
        $cuerpo = [
            'propertyID' => $propertyId,
            'roomID' => $roomId,
            'roomCondition' => $estadoCloudbeds,
        ];
        return $this->ejecutarConReintentos('POST', '/postHousekeepingStatus', $cuerpo);
    }

    /**
     * @param array<string, mixed>|null $cuerpo
     */
    private function ejecutarConReintentos(string $metodo, string $path, ?array $cuerpo = null): HttpResponse
    {
        if ($this->apiKey === '') {
            throw new CloudbedsException('CREDENCIAL_AUSENTE', 'CLOUDBEDS_API_KEY no configurada.');
        }

        $url = $this->baseUrl . $path;
        $headers = ['Authorization' => 'Bearer ' . $this->apiKey];

        $intentos = count($this->backoffsSegundos) + 1;
        $ultima = null;

        for ($i = 0; $i < $intentos; $i++) {
            $resp = $this->transport->request($metodo, $url, $headers, $cuerpo, $this->timeout);
            $ultima = $resp;

            if ($resp->esExito()) {
                Logger::info('cloudbeds', "{$metodo} {$path} ok", [
                    'status' => $resp->status,
                    'intento' => $i + 1,
                ]);
                return $resp;
            }

            if ($resp->status === 401) {
                Logger::error('cloudbeds', 'credencial inválida (401)', ['path' => $path]);
                throw new CloudbedsException('CREDENCIAL_INVALIDA', 'Credenciales Cloudbeds inválidas.');
            }

            if (!$resp->esReintentable() || $i === $intentos - 1) {
                break;
            }

            $espera = $this->backoffsSegundos[$i] ?? 0;
            Logger::warning('cloudbeds', "{$metodo} {$path} fallo, reintentando", [
                'status' => $resp->status,
                'intento' => $i + 1,
                'esperar_segundos' => $espera,
                'error_red' => $resp->errorRed,
            ]);
            ($this->dormir)($espera);
        }

        Logger::error('cloudbeds', "{$metodo} {$path} agotó reintentos", [
            'status' => $ultima?->status,
            'error_red' => $ultima?->errorRed,
        ]);

        return $ultima ?? new HttpResponse(0, '', 'sin respuesta');
    }
}
