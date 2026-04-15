<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\CloudbedsSyncService;
use Atankalama\Limpieza\Services\HabitacionService;
use Atankalama\Limpieza\Tests\Support\FakeHttpTransport;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class CloudbedsSyncServiceTest extends TestCase
{
    private FakeHttpTransport $transport;
    private CloudbedsSyncService $sync;
    private int $hotel1SurId;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre, cloudbeds_property_id) VALUES ('1_sur', '1 Sur', 'CB_1SUR')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        $this->hotel1SurId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $tipo = (int) Database::fetchOne("SELECT id FROM tipos_habitacion WHERE nombre='Doble'")['id'];

        Database::execute('INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, cloudbeds_room_id, estado) VALUES (?, ?, ?, ?, ?)', [$this->hotel1SurId, '101', $tipo, 'CB_R101', 'aprobada']);
        Database::execute('INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, cloudbeds_room_id, estado) VALUES (?, ?, ?, ?, ?)', [$this->hotel1SurId, '102', $tipo, 'CB_R102', 'sucia']);

        $this->transport = new FakeHttpTransport();
        $client = new CloudbedsClient(
            transport: $this->transport,
            baseUrl: 'https://cb.test',
            apiKey: 'k',
            backoffs: [0, 0, 0],
            dormir: static fn(int $s) => null,
        );
        $this->sync = new CloudbedsSyncService($client);
    }

    public function testSincronizarMarcaChecOutComoSucia(): void
    {
        $this->transport->encolarOk(200, [
            'data' => [
                ['roomID' => 'CB_R101', 'cleaningStatus' => 'Dirty'],
                ['roomID' => 'CB_R102', 'cleaningStatus' => 'Dirty'],
            ],
        ]);

        $syncId = $this->sync->sincronizar(null, 'manual');

        $r101 = Database::fetchOne("SELECT estado FROM habitaciones WHERE numero='101'");
        $this->assertSame('sucia', $r101['estado']); // aprobada → sucia por check-out

        $r102 = Database::fetchOne("SELECT estado FROM habitaciones WHERE numero='102'");
        $this->assertSame('sucia', $r102['estado']); // ya estaba sucia, no cambia

        $hist = Database::fetchOne('SELECT * FROM cloudbeds_sync_historial WHERE id = ?', [$syncId]);
        $this->assertSame('exito', $hist['resultado']);
        $this->assertSame(1, (int) $hist['habitaciones_sincronizadas']);
    }

    public function testSincronizarSinCloudbedsPropertyIdGeneraError(): void
    {
        Database::execute("UPDATE hoteles SET cloudbeds_property_id = NULL WHERE codigo = '1_sur'");

        $syncId = $this->sync->sincronizar(null, 'manual');

        $hist = Database::fetchOne('SELECT * FROM cloudbeds_sync_historial WHERE id = ?', [$syncId]);
        $this->assertSame('error', $hist['resultado']);

        // Debe haber alerta P0
        $alerta = Database::fetchOne("SELECT * FROM alertas_activas WHERE tipo = 'cloudbeds_sync_failed'");
        $this->assertNotNull($alerta);
        $this->assertSame(0, (int) $alerta['prioridad']);
    }

    public function testEscribirEstadoCleanExitoso(): void
    {
        $this->transport->encolarOk(200, ['success' => true]);

        $hab = (new HabitacionService())->obtener(
            (int) Database::fetchOne("SELECT id FROM habitaciones WHERE numero='101'")['id']
        );
        $ok = $this->sync->escribirEstadoClean($hab);

        $this->assertTrue($ok);
        $hist = Database::fetchOne("SELECT * FROM cloudbeds_sync_historial WHERE tipo = 'escritura_estado'");
        $this->assertSame('exito', $hist['resultado']);
        $this->assertNull(Database::fetchOne("SELECT 1 FROM alertas_activas WHERE tipo = 'cloudbeds_sync_failed'"));
    }

    public function testEscribirEstadoCleanConFalloGeneraAlertaP0(): void
    {
        // 1 intento + 3 reintentos = 4 fallos
        for ($i = 0; $i < 4; $i++) {
            $this->transport->encolarFallo(500);
        }

        $hab = (new HabitacionService())->obtener(
            (int) Database::fetchOne("SELECT id FROM habitaciones WHERE numero='101'")['id']
        );
        $ok = $this->sync->escribirEstadoClean($hab);

        $this->assertFalse($ok);

        $alerta = Database::fetchOne("SELECT * FROM alertas_activas WHERE tipo = 'cloudbeds_sync_failed'");
        $this->assertNotNull($alerta);

        $hist = Database::fetchOne("SELECT * FROM cloudbeds_sync_historial WHERE tipo = 'escritura_estado'");
        $this->assertSame('error', $hist['resultado']);
    }

    public function testPayloadDeEscrituraSanitizaTokenEnLogs(): void
    {
        $this->transport->encolarOk(200);

        $hab = (new HabitacionService())->obtener(
            (int) Database::fetchOne("SELECT id FROM habitaciones WHERE numero='101'")['id']
        );
        $this->sync->escribirEstadoClean($hab);

        $hist = Database::fetchOne("SELECT payload_request FROM cloudbeds_sync_historial WHERE tipo = 'escritura_estado'");
        $this->assertStringNotContainsString('CLOUDBEDS_API_KEY', $hist['payload_request']);
        $this->assertStringContainsString('roomID', $hist['payload_request']);
    }

    public function testEstadoActualYHistorial(): void
    {
        $this->transport->encolarOk(200, ['data' => []]);
        $this->sync->sincronizar(null, 'manual');

        $actual = $this->sync->estadoActual();
        $this->assertNotNull($actual);
        $this->assertSame('exito', $actual['resultado']);

        $historial = $this->sync->historial(10);
        $this->assertCount(1, $historial);
    }
}
