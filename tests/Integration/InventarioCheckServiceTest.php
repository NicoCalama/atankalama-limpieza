<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\AlertaActiva;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\InventarioCheckService;
use Atankalama\Limpieza\Services\InventarioImportService;
use Atankalama\Limpieza\Tests\Support\FakeHttpTransport;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Detección de altas/bajas de inventario en Cloudbeds y su alerta a la supervisora.
 * Ver src/Services/InventarioCheckService.php.
 */
final class InventarioCheckServiceTest extends TestCase
{
    private FakeHttpTransport $transport;
    private InventarioCheckService $check;
    private int $hotelId;

    protected function setUp(): void
    {
        TestDatabase::recrear();

        Database::execute("INSERT INTO hoteles (codigo, nombre, cloudbeds_property_id) VALUES ('1_sur', '1 Sur', 'CB_1SUR')");
        $this->hotelId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];

        foreach (['Singular', 'Doble/Matrimonial', 'Suite/Familiar'] as $nombre) {
            Database::execute('INSERT INTO tipos_habitacion (nombre) VALUES (?)', [$nombre]);
        }

        $this->transport = new FakeHttpTransport();
        $client = new CloudbedsClient(
            transport: $this->transport,
            baseUrl: 'https://cb.test',
            apiKey: 'k',
            backoffs: [0, 0, 0],
            dormir: static fn(int $s) => null,
        );
        $this->check = new InventarioCheckService(new InventarioImportService($client));
    }

    /**
     * @param list<array<string, mixed>> $rooms
     */
    private function encolarRooms(array $rooms): void
    {
        $this->transport->encolarOk(200, [
            'success' => true,
            'total' => count($rooms),
            'data' => [['propertyID' => 'CB_1SUR', 'rooms' => $rooms]],
        ]);
    }

    /** @return array<string, mixed> */
    private function room(string $roomId, string $roomName, int $maxGuests): array
    {
        return ['roomID' => $roomId, 'roomName' => $roomName, 'maxGuests' => $maxGuests, 'roomBlocked' => false];
    }

    private function contarAlertas(): int
    {
        return (int) Database::fetchOne(
            'SELECT COUNT(*) c FROM alertas_activas WHERE tipo = ?',
            [AlertaActiva::TIPO_INVENTARIO_CAMBIOS]
        )['c'];
    }

    private function sembrarPieza(string $roomId, string $numero, string $tipo): void
    {
        $tipoId = (int) Database::fetchOne('SELECT id FROM tipos_habitacion WHERE nombre = ?', [$tipo])['id'];
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, cloudbeds_room_id, estado, activa) VALUES (?, ?, ?, ?, 'sucia', 1)",
            [$this->hotelId, $numero, $tipoId, $roomId]
        );
    }

    public function testDetectaAltasYLevantaAlerta(): void
    {
        $this->encolarRooms([
            $this->room('CB_R1', '101-A', 2),
            $this->room('CB_R2', '102-B', 3),
        ]);

        $res = $this->check->revisar(true);

        $this->assertFalse($res['omitido']);
        $this->assertSame('alerta_levantada', $res['accion']);
        $this->assertSame(2, $res['cambios']);
        $this->assertSame(1, $this->contarAlertas());

        // Dry-run: NO escribió piezas.
        $this->assertSame(0, (int) Database::fetchOne('SELECT COUNT(*) c FROM habitaciones')['c']);

        $alerta = Database::fetchOne('SELECT titulo, descripcion FROM alertas_activas WHERE tipo = ?', [AlertaActiva::TIPO_INVENTARIO_CAMBIOS]);
        $this->assertStringContainsString('2 altas', $alerta['titulo']);
        $this->assertStringContainsString('101', $alerta['descripcion']);
    }

    public function testSinCambiosNoLevantaAlerta(): void
    {
        // La app ya está sincronizada: las piezas de Cloudbeds ya existen igualitas.
        $this->sembrarPieza('CB_R1', '101', 'Doble/Matrimonial');
        $this->encolarRooms([$this->room('CB_R1', '101-A', 2)]);

        $res = $this->check->revisar(true);

        $this->assertSame('sin_cambios', $res['accion']);
        $this->assertSame(0, $this->contarAlertas());
    }

    public function testRechazoNoVuelveAAlertarHastaQueCambie(): void
    {
        $rooms = [$this->room('CB_R1', '101-A', 2)];

        // 1) Se detecta y se levanta la alerta.
        $this->encolarRooms($rooms);
        $this->check->revisar(true);
        $this->assertSame(1, $this->contarAlertas());

        // 2) La supervisora rechaza: guardamos la huella y resolvemos la alerta (como el controller).
        $alertaId = (int) Database::fetchOne(
            'SELECT id FROM alertas_activas WHERE tipo = ? ORDER BY id DESC LIMIT 1',
            [AlertaActiva::TIPO_INVENTARIO_CAMBIOS]
        )['id'];
        $huella = $this->check->huellaDeAlerta($alertaId);
        $this->assertNotNull($huella);
        $this->check->registrarRechazo($huella);
        Database::execute('DELETE FROM alertas_activas WHERE id = ?', [$alertaId]);

        // 3) Mismo set de cambios → NO se vuelve a molestar.
        $this->encolarRooms($rooms);
        $res = $this->check->revisar(true);
        $this->assertSame('rechazado_previamente', $res['accion']);
        $this->assertSame(0, $this->contarAlertas());
    }

    public function testCambioDistintoVuelveAAlertarTrasRechazo(): void
    {
        // Rechaza el set A.
        $this->encolarRooms([$this->room('CB_R1', '101-A', 2)]);
        $this->check->revisar(true);
        $alertaId = (int) Database::fetchOne(
            'SELECT id FROM alertas_activas WHERE tipo = ? ORDER BY id DESC LIMIT 1',
            [AlertaActiva::TIPO_INVENTARIO_CAMBIOS]
        )['id'];
        $this->check->registrarRechazo($this->check->huellaDeAlerta($alertaId));
        Database::execute('DELETE FROM alertas_activas WHERE id = ?', [$alertaId]);

        // Cloudbeds cambia (aparece otra pieza) → set B distinto → vuelve a alertar.
        $this->encolarRooms([
            $this->room('CB_R1', '101-A', 2),
            $this->room('CB_R2', '102-B', 3),
        ]);
        $res = $this->check->revisar(true);

        $this->assertSame('alerta_levantada', $res['accion']);
        $this->assertSame(1, $this->contarAlertas());
    }

    public function testHotelConErrorNoResuelveAlertaPendiente(): void
    {
        // 1) Se detecta y levanta la alerta.
        $this->encolarRooms([$this->room('CB_R1', '101-A', 2)]);
        $this->check->revisar(true);
        $this->assertSame(1, $this->contarAlertas());

        // 2) Chequeo posterior donde Cloudbeds FALLA para el hotel (getRooms sin success=true).
        //    Un fallo transitorio NO debe confundirse con "estado limpio".
        $this->transport->encolarOk(200, ['success' => false, 'message' => 'error transitorio']);
        $res = $this->check->revisar(true);

        $this->assertSame('incompleto', $res['accion']);
        // La alerta pendiente sigue viva (no se auto-resolvió por el fallo externo).
        $this->assertSame(1, $this->contarAlertas());
    }

    public function testThrottleOmiteElSegundoChequeo(): void
    {
        $this->encolarRooms([$this->room('CB_R1', '101-A', 2)]);
        $primero = $this->check->revisar(true);
        $this->assertFalse($primero['omitido']);

        // Sin --force, dentro del mismo día: se omite (no consume otra respuesta del transport).
        $segundo = $this->check->revisar(false);
        $this->assertTrue($segundo['omitido']);
        $this->assertSame('throttle', $segundo['motivo']);
    }
}
