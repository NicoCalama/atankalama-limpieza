<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\CloudbedsException;
use Atankalama\Limpieza\Tests\Support\FakeHttpTransport;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class CloudbedsClientTest extends TestCase
{
    protected function setUp(): void
    {
        // logs_eventos se escribe en cada intento; necesitamos BD real.
        TestDatabase::recrear();
    }

    private function crear(FakeHttpTransport $transport, array $backoffs = [0, 0, 0]): CloudbedsClient
    {
        return new CloudbedsClient(
            transport: $transport,
            baseUrl: 'https://api.cloudbeds.test/api/v1.1',
            apiKey: 'test_key',
            timeout: 10,
            backoffs: $backoffs,
            dormir: static fn(int $s) => null,
        );
    }

    public function testExitoEnPrimerIntento(): void
    {
        $t = new FakeHttpTransport();
        $t->encolarOk(200, ['data' => ['rooms' => []]]);

        $client = $this->crear($t);
        $data = $client->obtenerHabitaciones('42');

        $this->assertArrayHasKey('data', $data);
        $this->assertCount(1, $t->peticiones);
        $this->assertStringContainsString('/getRooms?propertyID=42', $t->peticiones[0]['url']);
        $this->assertSame('Bearer test_key', $t->peticiones[0]['headers']['Authorization']);
    }

    public function testObtenerHabitacionesPaginaHastaCompletarTotal(): void
    {
        $t = new FakeHttpTransport();
        $t->encolarOk(200, ['success' => true, 'total' => 3, 'count' => 2, 'data' => [['propertyID' => '42', 'rooms' => [['roomID' => 'A'], ['roomID' => 'B']]]]]);
        $t->encolarOk(200, ['success' => true, 'total' => 3, 'count' => 1, 'data' => [['propertyID' => '42', 'rooms' => [['roomID' => 'C']]]]]);

        $client = $this->crear($t);
        $data = $client->obtenerHabitaciones('42');

        // Dos páginas: pageNumber 1 y luego 2.
        $this->assertCount(2, $t->peticiones);
        $this->assertStringContainsString('pageNumber=1', $t->peticiones[0]['url']);
        $this->assertStringContainsString('pageNumber=2', $t->peticiones[1]['url']);

        // Habitaciones acumuladas de ambas páginas, en orden.
        $rooms = $data['data'][0]['rooms'];
        $this->assertSame(['A', 'B', 'C'], array_map(static fn(array $r) => $r['roomID'], $rooms));
        $this->assertSame(3, $data['count']);
        $this->assertSame(3, $data['total']);
    }

    public function testObtenerHabitacionesUnaPaginaCuandoTotalCabe(): void
    {
        $t = new FakeHttpTransport();
        $t->encolarOk(200, ['success' => true, 'total' => 2, 'count' => 2, 'data' => [['propertyID' => '42', 'rooms' => [['roomID' => 'A'], ['roomID' => 'B']]]]]);

        $client = $this->crear($t);
        $data = $client->obtenerHabitaciones('42');

        $this->assertCount(1, $t->peticiones, 'no debe pedir una segunda página si total ya está cubierto');
        $this->assertCount(2, $data['data'][0]['rooms']);
    }

    public function testObtenerHabitacionesCortaEnPaginaVaciaAntesDeTotal(): void
    {
        // total inflado (10) pero la 2ª página viene vacía: debe cortar, no iterar hasta el tope.
        $t = new FakeHttpTransport();
        $t->encolarOk(200, ['success' => true, 'total' => 10, 'count' => 2, 'data' => [['propertyID' => '42', 'rooms' => [['roomID' => 'A'], ['roomID' => 'B']]]]]);
        $t->encolarOk(200, ['success' => true, 'total' => 10, 'count' => 0, 'data' => [['propertyID' => '42', 'rooms' => []]]]);

        $client = $this->crear($t);
        $data = $client->obtenerHabitaciones('42');

        $this->assertCount(2, $t->peticiones, 'se detiene tras la página vacía');
        $this->assertCount(2, $data['data'][0]['rooms']);
    }

    public function testObtenerEstadosLlamaGetHousekeepingStatus(): void
    {
        $t = new FakeHttpTransport();
        $t->encolarOk(200, ['success' => true, 'data' => []]);

        $client = $this->crear($t);
        $client->obtenerEstadosHabitaciones('209760');

        // Endpoint real de v1.1; /getRoomsStatus devolvía 404.
        $this->assertStringContainsString('/getHousekeepingStatus?propertyID=209760', $t->peticiones[0]['url']);
    }

    public function testReintentaHasta3VecesEn5xx(): void
    {
        $t = new FakeHttpTransport();
        $t->encolarFallo(503);
        $t->encolarFallo(503);
        $t->encolarFallo(503);
        $t->encolarOk(200, ['ok' => true]);

        $client = $this->crear($t);
        $data = $client->obtenerHabitaciones('42');

        $this->assertTrue($data['ok']);
        $this->assertCount(4, $t->peticiones);
    }

    public function testAgotaReintentosYRetornaUltimaRespuesta(): void
    {
        $t = new FakeHttpTransport();
        for ($i = 0; $i < 5; $i++) {
            $t->encolarFallo(500);
        }

        $client = $this->crear($t, backoffs: [0, 0, 0]);
        $data = $client->obtenerHabitaciones('42');

        $this->assertSame([], $data);
        // 1 intento + 3 reintentos = 4 peticiones
        $this->assertCount(4, $t->peticiones);
    }

    public function testError401LanzaCredencialInvalidaSinReintentar(): void
    {
        $t = new FakeHttpTransport();
        $t->encolarFallo(401);

        $client = $this->crear($t);

        try {
            $client->obtenerHabitaciones('42');
            $this->fail('Debía lanzar CloudbedsException');
        } catch (CloudbedsException $e) {
            $this->assertSame('CREDENCIAL_INVALIDA', $e->codigo);
            $this->assertCount(1, $t->peticiones);
        }
    }

    public function testSinApiKeyLanzaCredencialAusente(): void
    {
        $client = new CloudbedsClient(
            transport: new FakeHttpTransport(),
            apiKey: '',
            dormir: static fn(int $s) => null,
        );
        try {
            $client->obtenerHabitaciones('42');
            $this->fail('Debía lanzar');
        } catch (CloudbedsException $e) {
            $this->assertSame('CREDENCIAL_AUSENTE', $e->codigo);
        }
    }

    public function testActualizarEstadoEnviaPayload(): void
    {
        $t = new FakeHttpTransport();
        $t->encolarOk(200, ['success' => true]);

        $client = $this->crear($t);
        $resp = $client->actualizarEstadoHabitacion('42', 'R101', 'Clean');

        $this->assertTrue($resp->esExito());
        $this->assertSame('POST', $t->peticiones[0]['metodo']);
        $this->assertSame(['propertyID' => '42', 'roomID' => 'R101', 'roomCondition' => 'Clean'], $t->peticiones[0]['cuerpo']);
    }

    public function testDryRunNoEnviaLaEscrituraYRetorna200(): void
    {
        $t = new FakeHttpTransport(); // sin respuestas encoladas: si tocara la red, fallaría distinto
        $client = new CloudbedsClient(
            transport: $t,
            baseUrl: 'https://api.cloudbeds.test/api/v1.1',
            apiKey: '', // en dry-run ni siquiera necesita credencial
            dormir: static fn(int $s) => null,
            dryRun: true,
        );

        $resp = $client->actualizarEstadoHabitacion('42', 'R101', 'Clean');

        $this->assertTrue($resp->esExito());
        $this->assertCount(0, $t->peticiones, 'dry-run no debe realizar ninguna petición HTTP');
        $this->assertTrue($resp->json()['dry_run'] ?? false);
    }

    public function testDryRunNoAfectaLasLecturas(): void
    {
        $t = new FakeHttpTransport();
        $t->encolarOk(200, ['data' => ['rooms' => []]]);

        $client = new CloudbedsClient(
            transport: $t,
            baseUrl: 'https://api.cloudbeds.test/api/v1.1',
            apiKey: 'test_key',
            dormir: static fn(int $s) => null,
            dryRun: true,
        );

        $client->obtenerHabitaciones('42');

        $this->assertCount(1, $t->peticiones, 'las lecturas deben ejecutarse normalmente aun en dry-run');
        $this->assertStringContainsString('/getRooms?propertyID=42', $t->peticiones[0]['url']);
    }

    public function testNoReintenta4xxNoReintentable(): void
    {
        $t = new FakeHttpTransport();
        $t->encolarFallo(404);

        $client = $this->crear($t);
        $resp = $client->actualizarEstadoHabitacion('42', 'R101', 'Clean');

        $this->assertFalse($resp->esExito());
        $this->assertCount(1, $t->peticiones);
    }

    public function testUsaClavePorPropiedadSegunPropertyId(): void
    {
        $t = new FakeHttpTransport();
        $t->encolarOk(200, ['data' => []]);
        $t->encolarOk(200, ['data' => []]);

        $client = new CloudbedsClient(
            transport: $t,
            baseUrl: 'https://api.cloudbeds.test/api/v1.1',
            apiKey: 'fallback',
            backoffs: [0, 0, 0],
            dormir: static fn(int $s) => null,
            apiKeysPorPropiedad: ['209761' => 'key_inn', '209760' => 'key_principal'],
        );

        $client->obtenerHabitaciones('209761');
        $client->obtenerHabitaciones('209760');

        $this->assertSame('Bearer key_inn', $t->peticiones[0]['headers']['Authorization']);
        $this->assertSame('Bearer key_principal', $t->peticiones[1]['headers']['Authorization']);
    }

    public function testCaeEnClaveUnicaSiLaPropiedadNoEstaEnElMapa(): void
    {
        $t = new FakeHttpTransport();
        $t->encolarOk(200, ['data' => []]);

        $client = new CloudbedsClient(
            transport: $t,
            baseUrl: 'https://api.cloudbeds.test/api/v1.1',
            apiKey: 'fallback',
            backoffs: [0, 0, 0],
            dormir: static fn(int $s) => null,
            apiKeysPorPropiedad: ['209761' => 'key_inn'],
        );

        $client->obtenerHabitaciones('999');

        $this->assertSame('Bearer fallback', $t->peticiones[0]['headers']['Authorization']);
    }
}
