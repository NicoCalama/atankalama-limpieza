<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Support;

use Atankalama\Limpieza\Services\Http\HttpResponse;
use Atankalama\Limpieza\Services\Http\HttpTransport;

/**
 * Cloudbeds de mentira con MEMORIA, para simular el ciclo de limpieza de punta a punta: la app lee
 * el estado de cada pieza (getHousekeepingStatus), lo escribe (postHousekeepingStatus) y el test
 * mueve a los huéspedes. A diferencia de FakeHttpTransport (respuestas en cola), acá cada
 * petición se responde según el estado actual, así que el orden de las llamadas no importa.
 *
 * Lo que hace Cloudbeds de verdad y se imita (docs/cloudbeds.md, docs/ocupacion-y-sabanas.md):
 * marca 'dirty' cuando llega un huésped, cuando se va y cada madrugada con las piezas ocupadas;
 * una escritura de la app ('clean' / 'dirty') cambia la condición y nada más.
 */
final class CloudbedsSimulado implements HttpTransport
{
    /** @var array<string, array{condicion: string, frontdesk: string, ocupada: bool}> roomID → estado */
    private array $piezas = [];

    /** @var list<array{roomID: string, condicion: string}> escrituras que la app mandó */
    public array $escrituras = [];

    /** Si es true, toda escritura responde success=false (Cloudbeds la rechazó). */
    public bool $fallarEscrituras = false;

    public function pieza(string $roomId, string $condicion, string $frontdesk, bool $ocupada): void
    {
        $this->piezas[$roomId] = ['condicion' => $condicion, 'frontdesk' => $frontdesk, 'ocupada' => $ocupada];
    }

    /** Pieza vacía y limpia, sin reserva hoy. */
    public function vacia(string $roomId): void
    {
        $this->pieza($roomId, 'clean', 'unused', false);
    }

    /** Huésped que sigue alojado (servicio diario): Cloudbeds la tiene sucia y ocupada. */
    public function estadia(string $roomId): void
    {
        $this->pieza($roomId, 'dirty', 'stayover', true);
    }

    /** Llega un huésped: Cloudbeds la marca sucia (es el aviso del aseo de MAÑANA). */
    public function llega(string $roomId): void
    {
        $this->pieza($roomId, 'dirty', 'check-in', true);
    }

    /** Se va el huésped: sucia y desocupada. */
    public function seVa(string $roomId): void
    {
        $this->pieza($roomId, 'dirty', 'check-out', false);
    }

    /** Madrugada: toda pieza ocupada amanece sucia, como servicio del día. */
    public function madrugada(): void
    {
        foreach ($this->piezas as $roomId => $p) {
            if ($p['ocupada']) {
                $this->pieza($roomId, 'dirty', 'stayover', true);
            }
        }
    }

    public function condicion(string $roomId): string
    {
        return $this->piezas[$roomId]['condicion'];
    }

    public function request(
        string $metodo,
        string $url,
        array $headers = [],
        ?array $cuerpo = null,
        int $timeoutSegundos = 10,
        string $contentType = 'application/json',
    ): HttpResponse {
        $ruta = (string) parse_url($url, PHP_URL_PATH);

        if (str_ends_with($ruta, '/getHousekeepingStatus')) {
            $data = [];
            foreach ($this->piezas as $roomId => $p) {
                $data[] = [
                    'roomID' => $roomId,
                    'roomCondition' => $p['condicion'],
                    'frontdeskStatus' => $p['frontdesk'],
                    'roomOccupied' => $p['ocupada'],
                ];
            }
            return $this->ok(['success' => true, 'data' => $data]);
        }

        if (str_ends_with($ruta, '/postHousekeepingStatus')) {
            if ($this->fallarEscrituras) {
                return $this->ok(['success' => false, 'message' => 'Simulado: Cloudbeds rechazó la escritura']);
            }
            $roomId = (string) ($cuerpo['roomID'] ?? '');
            $condicion = (string) ($cuerpo['roomCondition'] ?? '');
            $this->escrituras[] = ['roomID' => $roomId, 'condicion' => $condicion];
            if (isset($this->piezas[$roomId])) {
                $this->piezas[$roomId]['condicion'] = $condicion;
            }
            return $this->ok(['success' => true]);
        }

        // Reservas (huéspedes, cantidades): datos secundarios que el sync tolera vacíos.
        return $this->ok(['success' => true, 'total' => 0, 'data' => []]);
    }

    /** @param array<string, mixed> $cuerpo */
    private function ok(array $cuerpo): HttpResponse
    {
        return new HttpResponse(200, (string) json_encode($cuerpo));
    }
}
