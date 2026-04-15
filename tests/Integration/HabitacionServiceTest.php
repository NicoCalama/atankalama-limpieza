<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Services\HabitacionException;
use Atankalama\Limpieza\Services\HabitacionService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class HabitacionServiceTest extends TestCase
{
    private HabitacionService $svc;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        $this->sembrarHotelesYHabitaciones();
        $this->svc = new HabitacionService();
    }

    private function sembrarHotelesYHabitaciones(): void
    {
        Database::execute("INSERT INTO hoteles (codigo, nombre, cloudbeds_property_id) VALUES ('1_sur', '1 Sur', 'CB_1SUR')");
        Database::execute("INSERT INTO hoteles (codigo, nombre, cloudbeds_property_id) VALUES ('inn', 'Inn', 'CB_INN')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Suite')");

        $h1 = Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $h2 = Database::fetchOne("SELECT id FROM hoteles WHERE codigo='inn'")['id'];
        $t1 = Database::fetchOne("SELECT id FROM tipos_habitacion WHERE nombre='Doble'")['id'];

        Database::execute('INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, cloudbeds_room_id, estado) VALUES (?, ?, ?, ?, ?)', [$h1, '101', $t1, 'CB_R101', 'sucia']);
        Database::execute('INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, cloudbeds_room_id, estado) VALUES (?, ?, ?, ?, ?)', [$h1, '102', $t1, 'CB_R102', 'en_progreso']);
        Database::execute('INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, cloudbeds_room_id, estado) VALUES (?, ?, ?, ?, ?)', [$h2, '201', $t1, 'CB_R201', 'aprobada']);
    }

    public function testListarTodas(): void
    {
        $filas = $this->svc->listar('ambos');
        $this->assertCount(3, $filas);
    }

    public function testFiltrarPorHotel(): void
    {
        $this->assertCount(2, $this->svc->listar('1_sur'));
        $this->assertCount(1, $this->svc->listar('inn'));
    }

    public function testFiltrarPorEstado(): void
    {
        $filas = $this->svc->listar('ambos', 'sucia');
        $this->assertCount(1, $filas);
        $this->assertSame('101', $filas[0]['numero']);
    }

    public function testEstadoInvalidoLanza(): void
    {
        try {
            $this->svc->listar('ambos', 'foo');
            $this->fail('Debía lanzar');
        } catch (HabitacionException $e) {
            $this->assertSame('ESTADO_INVALIDO', $e->codigo);
        }
    }

    public function testObtenerDetalleIncluyeJoins(): void
    {
        $id = (int) Database::fetchOne("SELECT id FROM habitaciones WHERE numero='101'")['id'];
        $d = $this->svc->obtenerDetalle($id);
        $this->assertSame('1_sur', $d['hotel_codigo']);
        $this->assertSame('Doble', $d['tipo_nombre']);
    }

    public function testCambiarEstadoValidoFunciona(): void
    {
        $id = (int) Database::fetchOne("SELECT id FROM habitaciones WHERE numero='101'")['id'];
        $hab = $this->svc->cambiarEstado($id, Habitacion::ESTADO_EN_PROGRESO, usuarioId: null);
        $this->assertSame('en_progreso', $hab->estado);

        $fila = Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$id]);
        $this->assertSame('en_progreso', $fila['estado']);
    }

    public function testCambiarEstadoInvalidoLanza(): void
    {
        $id = (int) Database::fetchOne("SELECT id FROM habitaciones WHERE numero='101'")['id'];
        try {
            $this->svc->cambiarEstado($id, Habitacion::ESTADO_APROBADA);
            $this->fail('Debía lanzar');
        } catch (HabitacionException $e) {
            $this->assertSame('TRANSICION_INVALIDA', $e->codigo);
        }
    }

    public function testBuscarPorCloudbedsRoomId(): void
    {
        $hotelId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $hab = $this->svc->buscarPorCloudbedsRoomId($hotelId, 'CB_R101');
        $this->assertNotNull($hab);
        $this->assertSame('101', $hab->numero);
    }
}
