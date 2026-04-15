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

    public function testNoReintenta4xxNoReintentable(): void
    {
        $t = new FakeHttpTransport();
        $t->encolarFallo(404);

        $client = $this->crear($t);
        $resp = $client->actualizarEstadoHabitacion('42', 'R101', 'Clean');

        $this->assertFalse($resp->esExito());
        $this->assertCount(1, $t->peticiones);
    }
}
