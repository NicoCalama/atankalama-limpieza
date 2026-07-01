<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Helpers\LogSanitizer;
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

    private const BASE_URL_DEFAULT = 'https://api.cloudbeds.com/api/v1.1';

    /**
     * Sufijos de las propiedades en el .env (CLOUDBEDS_API_KEY_<SUFIJO> /
     * CLOUDBEDS_PROPERTY_ID_<SUFIJO>). El proyecto opera exactamente dos
     * propiedades de Atankalama: INN (Chorrillos 558) y PRINCIPAL (1 Sur 858).
     */
    private const SUFIJOS_PROPIEDAD = ['INN', 'PRINCIPAL'];

    /** Tamaño de página para getRooms (paginado). Cloudbeds admite hasta 100. */
    private const HABITACIONES_PAGE_SIZE = 100;

    /** Tope de páginas anti-loop (100 * 50 = 5000 habitaciones). */
    private const HABITACIONES_MAX_PAGINAS = 50;

    private readonly string $baseUrl;
    private readonly string $apiKey;

    /** @var array<string, string> propertyID => apiKey */
    private readonly array $apiKeysPorPropiedad;

    private readonly int $timeout;

    /**
     * Modo simulación: si es true, actualizarEstadoHabitacion() loguea el payload
     * que enviaría y devuelve un 200 sintético SIN tocar la red. Las lecturas no
     * se ven afectadas (ya son de riesgo cero). Ver docs/cloudbeds-pruebas-seguras.md.
     */
    private readonly bool $dryRun;

    /** @var int[] */
    private array $backoffsSegundos;

    /** @var callable */
    private $dormir;

    /**
     * @param array<string, string> $apiKeysPorPropiedad Mapa propertyID => apiKey.
     *        Si una propiedad no está mapeada, se usa $apiKey (clave única) como fallback.
     */
    public function __construct(
        private readonly HttpTransport $transport = new CurlTransport(),
        ?string $baseUrl = null,
        ?string $apiKey = null,
        ?int $timeout = null,
        ?array $backoffs = null,
        ?callable $dormir = null,
        array $apiKeysPorPropiedad = [],
        ?bool $dryRun = null,
    ) {
        $this->baseUrl = rtrim($baseUrl ?? (string) Config::get('CLOUDBEDS_BASE_URL', self::BASE_URL_DEFAULT), '/');
        $this->apiKey = $apiKey ?? (string) Config::get('CLOUDBEDS_API_KEY', '');
        $this->apiKeysPorPropiedad = $apiKeysPorPropiedad;
        $this->timeout = $timeout ?? Config::getInt('CLOUDBEDS_TIMEOUT_SECONDS', 10);
        $this->dryRun = $dryRun ?? Config::getBool('CLOUDBEDS_DRY_RUN', false);
        $this->backoffsSegundos = $backoffs ?? self::BACKOFFS_DEFAULT;
        $this->dormir = $dormir ?? static function (int $s): void {
            if ($s > 0) {
                sleep($s);
            }
        };
    }

    /**
     * Construye el cliente leyendo las credenciales por propiedad del .env.
     * Arma el mapa propertyID => apiKey con una entrada por cada propiedad
     * que tenga propertyID y key configurados.
     */
    public static function desdeConfig(): self
    {
        $mapa = [];
        foreach (self::SUFIJOS_PROPIEDAD as $sufijo) {
            $propertyId = (string) Config::get("CLOUDBEDS_PROPERTY_ID_{$sufijo}", '');
            $apiKey = (string) Config::get("CLOUDBEDS_API_KEY_{$sufijo}", '');
            if ($propertyId !== '' && $apiKey !== '') {
                $mapa[$propertyId] = $apiKey;
            }
        }
        return new self(apiKeysPorPropiedad: $mapa);
    }

    /**
     * Inventario de habitaciones de una propiedad (GET /getRooms).
     *
     * getRooms está PAGINADO: cada respuesta trae `count` (de esta página) y `total`
     * (de la propiedad). Este método itera pageNumber/pageSize hasta juntar `total`
     * habitaciones y devuelve el shape agregado
     * { success, data: [ { propertyID, rooms: [TODAS] } ], count, total }.
     *
     * Retrocompatibilidad: si la primera respuesta no trae `total` numérico (respuestas
     * de test/error), devuelve esa respuesta tal cual, sin paginar.
     *
     * @return array<string, mixed>  Payload del cuerpo JSON (agregado si paginó)
     */
    public function obtenerHabitaciones(string $propertyId): array
    {
        $apiKey = $this->claveParaPropiedad($propertyId);
        $primera = $this->obtenerPaginaHabitaciones($propertyId, $apiKey, 1);

        $total = $primera['total'] ?? null;
        if (!is_numeric($total)) {
            // Sin metadata de paginación: comportamiento legado (una sola llamada).
            return $primera;
        }
        $total = (int) $total;

        $rooms = $this->extraerRooms($primera);
        $pagina = 1;
        while (count($rooms) < $total && $pagina < self::HABITACIONES_MAX_PAGINAS) {
            $pagina++;
            $nuevas = $this->extraerRooms($this->obtenerPaginaHabitaciones($propertyId, $apiKey, $pagina));
            if ($nuevas === []) {
                // Página vacía antes de alcanzar `total`: corta para no colgar el loop.
                Logger::warning('cloudbeds', 'getRooms devolvió página vacía antes de total', [
                    'propertyID' => $propertyId,
                    'pagina' => $pagina,
                    'acumuladas' => count($rooms),
                    'total' => $total,
                ]);
                break;
            }
            foreach ($nuevas as $room) {
                $rooms[] = $room;
            }
        }

        return [
            'success' => true,
            'data' => [
                ['propertyID' => $propertyId, 'rooms' => $rooms],
            ],
            'count' => count($rooms),
            'total' => $total,
        ];
    }

    /**
     * Una página de getRooms (pageNumber 1-based, pageSize fijo).
     *
     * @return array<string, mixed>
     */
    private function obtenerPaginaHabitaciones(string $propertyId, string $apiKey, int $pageNumber): array
    {
        $query = 'propertyID=' . urlencode($propertyId)
            . '&pageNumber=' . $pageNumber
            . '&pageSize=' . self::HABITACIONES_PAGE_SIZE;
        return $this->ejecutarConReintentos('GET', '/getRooms?' . $query, $apiKey)->json();
    }

    /**
     * Aplana las habitaciones de todos los grupos de `data`.
     *
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function extraerRooms(array $payload): array
    {
        $rooms = [];
        foreach (($payload['data'] ?? []) as $grupo) {
            if (!is_array($grupo)) {
                continue;
            }
            foreach (($grupo['rooms'] ?? []) as $room) {
                if (is_array($room)) {
                    $rooms[] = $room;
                }
            }
        }
        return $rooms;
    }

    /**
     * Estado de limpieza (housekeeping) de todas las habitaciones de una propiedad.
     *
     * Endpoint real de Cloudbeds v1.1: getHousekeepingStatus (NO getRoomsStatus, que
     * devuelve 404). Responde { success: true, data: [ { roomID, roomCondition, ... } ] }
     * con `data` como array plano por habitación.
     *
     * @return array<string, mixed>
     */
    public function obtenerEstadosHabitaciones(string $propertyId, ?string $fecha = null): array
    {
        $query = 'propertyID=' . urlencode($propertyId);
        if ($fecha !== null) {
            $query .= '&date=' . urlencode($fecha);
        }
        $response = $this->ejecutarConReintentos('GET', '/getHousekeepingStatus?' . $query, $this->claveParaPropiedad($propertyId));
        return $response->json();
    }

    /**
     * Actualiza el estado de limpieza de una habitación en Cloudbeds.
     * Retorna el HttpResponse final; lanza CloudbedsException solo en 401 inmediato.
     *
     * En modo dry-run (CLOUDBEDS_DRY_RUN) NO envía la petición: loguea el payload
     * exacto que enviaría y devuelve un 200 sintético, para validar el flujo de
     * escritura completo sin tocar Cloudbeds. Ver docs/cloudbeds-pruebas-seguras.md.
     */
    public function actualizarEstadoHabitacion(string $propertyId, string $roomId, string $estadoCloudbeds): HttpResponse
    {
        $cuerpo = [
            'propertyID' => $propertyId,
            'roomID' => $roomId,
            'roomCondition' => $estadoCloudbeds,
        ];

        if ($this->dryRun) {
            Logger::info('cloudbeds', 'DRY-RUN: escritura simulada, no se envió a Cloudbeds', [
                'endpoint' => 'POST /postHousekeepingStatus',
                'payload' => LogSanitizer::sanitize($cuerpo),
            ]);
            return new HttpResponse(200, (string) json_encode(['success' => true, 'dry_run' => true], JSON_UNESCAPED_UNICODE));
        }

        return $this->ejecutarConReintentos('POST', '/postHousekeepingStatus', $this->claveParaPropiedad($propertyId), $cuerpo);
    }

    /**
     * Resuelve la API key para una propiedad: usa la clave específica del mapa,
     * o cae en la clave única (compat/tests) si la propiedad no está mapeada.
     */
    private function claveParaPropiedad(string $propertyId): string
    {
        if ($propertyId !== '' && ($this->apiKeysPorPropiedad[$propertyId] ?? '') !== '') {
            return $this->apiKeysPorPropiedad[$propertyId];
        }
        return $this->apiKey;
    }

    /**
     * @param array<string, mixed>|null $cuerpo
     */
    private function ejecutarConReintentos(string $metodo, string $path, string $apiKey, ?array $cuerpo = null): HttpResponse
    {
        if ($apiKey === '') {
            throw new CloudbedsException('CREDENCIAL_AUSENTE', 'No hay credencial Cloudbeds configurada para la propiedad.');
        }

        $url = $this->baseUrl . $path;
        $headers = ['Authorization' => 'Bearer ' . $apiKey];

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
