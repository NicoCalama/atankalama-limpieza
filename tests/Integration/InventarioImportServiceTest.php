<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\InventarioImportService;
use Atankalama\Limpieza\Tests\Support\FakeHttpTransport;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class InventarioImportServiceTest extends TestCase
{
    private FakeHttpTransport $transport;
    private InventarioImportService $servicio;
    private int $hotel1SurId;
    private int $hotelInnId;

    protected function setUp(): void
    {
        TestDatabase::recrear();

        Database::execute("INSERT INTO hoteles (codigo, nombre, cloudbeds_property_id) VALUES ('1_sur', '1 Sur', 'CB_1SUR')");
        Database::execute("INSERT INTO hoteles (codigo, nombre, cloudbeds_property_id) VALUES ('inn', 'Atankalama INN', 'CB_INN')");
        $this->hotel1SurId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $this->hotelInnId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='inn'")['id'];

        // Los tipos ya NO se pre-siembran: el import los crea on-the-fly por cada roomTypeName real.

        $this->transport = new FakeHttpTransport();
        $client = new CloudbedsClient(
            transport: $this->transport,
            baseUrl: 'https://cb.test',
            apiKey: 'k',
            backoffs: [0, 0, 0],
            dormir: static fn(int $s) => null,
        );
        $this->servicio = new InventarioImportService($client);
    }

    /**
     * Encola una respuesta de getRooms (una sola página: count == total, no pagina).
     *
     * @param list<array<string, mixed>> $rooms
     */
    private function encolarRooms(array $rooms, string $propertyId = 'CB_1SUR'): void
    {
        $this->transport->encolarOk(200, [
            'success' => true,
            'total' => count($rooms),
            'data' => [['propertyID' => $propertyId, 'rooms' => $rooms]],
        ]);
    }

    /** @return array<string, mixed> */
    private function room(string $roomId, string $roomName, int $maxGuests, string $roomTypeName = 'Estándar', bool $blocked = false): array
    {
        return [
            'roomID' => $roomId,
            'roomName' => $roomName,
            'maxGuests' => $maxGuests,
            'roomTypeName' => $roomTypeName,
            'roomBlocked' => $blocked,
        ];
    }

    private function tipoDe(string $numero): string
    {
        return (string) Database::fetchOne(
            'SELECT th.nombre FROM habitaciones h JOIN tipos_habitacion th ON th.id = h.tipo_habitacion_id WHERE h.numero = ?',
            [$numero]
        )['nombre'];
    }

    public function testCreaHabitacionesYMapeaTipoPorRoomTypeName(): void
    {
        $this->encolarRooms([
            $this->room('CB_R1', '101-BOT M', 1, 'Premium single'),
            $this->room('CB_R2', '102-BOT2 M', 2, 'Premium doble'),
            $this->room('CB_R3', '201-EXE3 (3S)', 3, 'Premium triple'),
            $this->room('CB_R4', '301-CUAD (4s)', 4, 'Premium triple'),
        ]);

        $res = $this->servicio->importar('1_sur');

        $this->assertSame(4, $res['totales']['creadas']);
        $this->assertSame(4, (int) Database::fetchOne('SELECT COUNT(*) c FROM habitaciones')['c']);

        // El tipo sale del roomTypeName real de Cloudbeds, no de maxGuests.
        $this->assertSame('Premium single', $this->tipoDe('101'));
        $this->assertSame('Premium doble', $this->tipoDe('102'));
        $this->assertSame('Premium triple', $this->tipoDe('201'));
        $this->assertSame('Premium triple', $this->tipoDe('301'));

        // Se crearon 3 tipos (las dos 'Premium triple' comparten uno).
        $this->assertSame(3, (int) Database::fetchOne("SELECT COUNT(*) c FROM tipos_habitacion")['c']);
    }

    public function testCadaTipoNuevoObtieneChecklistDefaultCompartido(): void
    {
        $this->encolarRooms([$this->room('CB_R1', '101-A', 2, 'Premium doble')]);
        $this->servicio->importar('1_sur');

        $tipoId = (int) Database::fetchOne("SELECT id FROM tipos_habitacion WHERE nombre = 'Premium doble'")['id'];
        $tpl = Database::fetchOne(
            'SELECT id FROM checklists_template
              WHERE tipo_habitacion_id = ? AND hotel_id IS NULL AND habitacion_id IS NULL AND activo = 1',
            [$tipoId]
        );
        $this->assertNotNull($tpl, 'el tipo nuevo debe tener un checklist default compartido');
        $items = (int) Database::fetchOne(
            'SELECT COUNT(*) c FROM items_checklist WHERE template_id = ?',
            [$tpl['id']]
        )['c'];
        $this->assertGreaterThan(0, $items, 'el checklist default debe tener ítems');
    }

    public function testParseaPrefijoNumericoDelRoomName(): void
    {
        $this->encolarRooms([$this->room('CB_R1', '403-INN2 2S', 3)]);

        $this->servicio->importar('1_sur');

        $fila = Database::fetchOne("SELECT numero, cloudbeds_room_id FROM habitaciones WHERE cloudbeds_room_id = 'CB_R1'");
        $this->assertSame('403', $fila['numero']);
    }

    public function testRoomNameSinPrefijoUsaElNombreCompleto(): void
    {
        $this->encolarRooms([$this->room('CB_R1', 'LOFT-A', 4)]);

        $this->servicio->importar('1_sur');

        $fila = Database::fetchOne("SELECT numero FROM habitaciones WHERE cloudbeds_room_id = 'CB_R1'");
        $this->assertSame('LOFT-A', $fila['numero']);
    }

    public function testUpsertEsIdempotente(): void
    {
        $rooms = [
            $this->room('CB_R1', '101-A', 2),
            $this->room('CB_R2', '102-B', 3),
        ];

        $this->encolarRooms($rooms);
        $primera = $this->servicio->importar('1_sur');
        $this->assertSame(2, $primera['totales']['creadas']);

        $this->encolarRooms($rooms);
        $segunda = $this->servicio->importar('1_sur');

        $this->assertSame(0, $segunda['totales']['creadas']);
        $this->assertSame(0, $segunda['totales']['actualizadas']);
        $this->assertSame(2, $segunda['totales']['sin_cambio']);
        $this->assertSame(2, (int) Database::fetchOne('SELECT COUNT(*) c FROM habitaciones')['c']);
    }

    public function testRoomBlockedSeImportaInactiva(): void
    {
        $this->encolarRooms([
            $this->room('CB_R1', '101-A', 2),
            $this->room('CB_R2', '102-B', 2, blocked: true),
        ]);

        $res = $this->servicio->importar('1_sur');

        $this->assertSame(2, $res['totales']['creadas']);
        $this->assertSame(1, $res['totales']['bloqueadas']);

        $activa = (int) Database::fetchOne("SELECT activa FROM habitaciones WHERE cloudbeds_room_id = 'CB_R1'")['activa'];
        $bloqueada = (int) Database::fetchOne("SELECT activa FROM habitaciones WHERE cloudbeds_room_id = 'CB_R2'")['activa'];
        $this->assertSame(1, $activa);
        $this->assertSame(0, $bloqueada);
    }

    public function testColisionDePrefijoSeReportaYNoRompeElUnique(): void
    {
        // Dos piezas distintas cuyo prefijo colisiona (defensivo: el inventario real no las tiene).
        $this->encolarRooms([
            $this->room('CB_R1', '101-A', 2),
            $this->room('CB_R2', '101-B', 3),
        ]);

        $res = $this->servicio->importar('1_sur');

        $this->assertSame(0, $res['totales']['creadas']);
        $this->assertSame(1, $res['totales']['colisiones']);
        // Ninguna se creó: no se violó el UNIQUE(hotel_id, numero).
        $this->assertSame(0, (int) Database::fetchOne('SELECT COUNT(*) c FROM habitaciones')['c']);

        $colision = $res['hoteles'][0]['colisiones'][0];
        $this->assertSame('101', $colision['numero']);
        $this->assertContains('CB_R1', $colision['room_ids']);
        $this->assertContains('CB_R2', $colision['room_ids']);
    }

    public function testRoomIdQueDesapareceSeDesactiva(): void
    {
        $this->encolarRooms([
            $this->room('CB_R1', '101-A', 2),
            $this->room('CB_R2', '102-B', 2),
        ]);
        $this->servicio->importar('1_sur');
        $this->assertSame(2, (int) Database::fetchOne('SELECT COUNT(*) c FROM habitaciones WHERE activa = 1')['c']);

        // Segundo import: CB_R1 ya no viene; aparece CB_R3.
        $this->encolarRooms([
            $this->room('CB_R2', '102-B', 2),
            $this->room('CB_R3', '103-C', 3),
        ]);
        $res = $this->servicio->importar('1_sur');

        $this->assertSame(1, $res['totales']['creadas']);       // CB_R3
        $this->assertSame(1, $res['totales']['desactivadas']);  // CB_R1

        $desactivada = (int) Database::fetchOne("SELECT activa FROM habitaciones WHERE cloudbeds_room_id = 'CB_R1'")['activa'];
        $this->assertSame(0, $desactivada);
        // No se borró: sigue en la tabla (histórico).
        $this->assertSame(3, (int) Database::fetchOne('SELECT COUNT(*) c FROM habitaciones')['c']);
    }

    public function testVinculaPiezaLegadaPorNumeroSinRoomId(): void
    {
        // Pieza preexistente con numero pero sin cloudbeds_room_id (dato legado), con un tipo viejo.
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Tipo viejo')");
        $tipoViejoId = (int) Database::fetchOne("SELECT id FROM tipos_habitacion WHERE nombre = 'Tipo viejo'")['id'];
        Database::execute(
            'INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, cloudbeds_room_id) VALUES (?, ?, ?, NULL)',
            [$this->hotel1SurId, '101', $tipoViejoId]
        );

        $this->encolarRooms([$this->room('CB_R1', '101-A', 2, 'Premium doble')]);
        $res = $this->servicio->importar('1_sur');

        $this->assertSame(0, $res['totales']['creadas']);
        $this->assertSame(1, $res['totales']['actualizadas']);
        $this->assertSame(1, (int) Database::fetchOne('SELECT COUNT(*) c FROM habitaciones')['c']);

        $fila = Database::fetchOne("SELECT cloudbeds_room_id, tipo_habitacion_id FROM habitaciones WHERE numero = '101'");
        $this->assertSame('CB_R1', $fila['cloudbeds_room_id']);
        // Se re-tipó al roomTypeName real de Cloudbeds.
        $this->assertSame('Premium doble', $this->tipoDe('101'));
    }

    public function testDryRunNoEscribeNada(): void
    {
        $this->encolarRooms([
            $this->room('CB_R1', '101-A', 2),
            $this->room('CB_R2', '102-B', 3),
        ]);

        $res = $this->servicio->importar('1_sur', dryRun: true);

        $this->assertTrue($res['dry_run']);
        $this->assertSame(2, $res['totales']['creadas']);
        // Nada tocó la BD: ni habitaciones ni tipos nuevos (get-or-create solo escribe al aplicar).
        $this->assertSame(0, (int) Database::fetchOne('SELECT COUNT(*) c FROM habitaciones')['c']);
        $this->assertSame(0, (int) Database::fetchOne('SELECT COUNT(*) c FROM tipos_habitacion')['c']);
    }

    public function testImportaLosDosHotelesEnUnaCorrida(): void
    {
        // Orden por id: 1_sur primero, inn después.
        $this->encolarRooms([$this->room('CB_R1', '101-A', 2)], 'CB_1SUR');
        $this->encolarRooms([$this->room('CB_I1', '10-X', 3)], 'CB_INN');

        $res = $this->servicio->importar(null);

        $this->assertSame(2, $res['totales']['creadas']);
        $this->assertCount(2, $res['hoteles']);

        $en1Sur = (int) Database::fetchOne('SELECT hotel_id FROM habitaciones WHERE numero = ?', ['101'])['hotel_id'];
        $enInn = (int) Database::fetchOne('SELECT hotel_id FROM habitaciones WHERE numero = ?', ['10'])['hotel_id'];
        $this->assertSame($this->hotel1SurId, $en1Sur);
        $this->assertSame($this->hotelInnId, $enInn);
    }
}
