<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Ticket;
use Atankalama\Limpieza\Services\TicketException;
use Atankalama\Limpieza\Services\TicketService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class TicketServiceTest extends TestCase
{
    private TicketService $svc;
    private int $hotelId;
    private int $tipoId;
    private int $habitacionId;
    private int $usuarioId;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        $this->hotelId = (int) Database::fetchOne("SELECT id FROM hoteles")['id'];
        $this->tipoId = (int) Database::fetchOne('SELECT id FROM tipos_habitacion')['id'];
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, '101', ?, 'sucia')",
            [$this->hotelId, $this->tipoId]
        );
        $this->habitacionId = Database::lastInsertId();
        [$this->usuarioId] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        $this->svc = new TicketService();
    }

    public function testCrearTicketGeneraAlertaP2(): void
    {
        $t = $this->svc->crear(
            $this->hotelId,
            'Ducha rota',
            'La ducha de la habitación 101 no funciona.',
            Ticket::PRIORIDAD_ALTA,
            $this->usuarioId,
            $this->habitacionId
        );
        $this->assertSame('abierto', $t->estado);

        $alerta = Database::fetchOne("SELECT * FROM alertas_activas WHERE tipo='ticket_nuevo'");
        $this->assertNotNull($alerta);
        $this->assertSame(2, (int) $alerta['prioridad']);
    }

    public function testPrioridadInvalidaLanza(): void
    {
        try {
            $this->svc->crear($this->hotelId, 't', 'd', 'inexistente', $this->usuarioId);
            $this->fail('Debía lanzar');
        } catch (TicketException $e) {
            $this->assertSame('PRIORIDAD_INVALIDA', $e->codigo);
        }
    }

    public function testTituloVacioLanza(): void
    {
        try {
            $this->svc->crear($this->hotelId, '   ', 'd', Ticket::PRIORIDAD_NORMAL, $this->usuarioId);
            $this->fail('Debía lanzar');
        } catch (TicketException $e) {
            $this->assertSame('TITULO_INVALIDO', $e->codigo);
        }
    }

    public function testCambiarAResueltoSeteaTimestampYResuelveAlerta(): void
    {
        $t = $this->svc->crear($this->hotelId, 't', 'd', Ticket::PRIORIDAD_NORMAL, $this->usuarioId);
        $r = $this->svc->cambiarEstado($t->id, Ticket::ESTADO_RESUELTO, $this->usuarioId);
        $this->assertSame('resuelto', $r->estado);
        $this->assertNotNull($r->resueltoAt);

        $count = (int) Database::fetchOne("SELECT COUNT(*) AS n FROM alertas_activas WHERE tipo='ticket_nuevo'")['n'];
        $this->assertSame(0, $count);
    }

    public function testTicketCerradoNoSePuedeCambiar(): void
    {
        $t = $this->svc->crear($this->hotelId, 't', 'd', Ticket::PRIORIDAD_NORMAL, $this->usuarioId);
        $this->svc->cambiarEstado($t->id, Ticket::ESTADO_RESUELTO, $this->usuarioId);
        $this->svc->cambiarEstado($t->id, Ticket::ESTADO_CERRADO, $this->usuarioId);

        try {
            $this->svc->cambiarEstado($t->id, Ticket::ESTADO_EN_PROGRESO, $this->usuarioId);
            $this->fail('Debía lanzar');
        } catch (TicketException $e) {
            $this->assertSame('TICKET_CERRADO', $e->codigo);
        }
    }

    public function testAsignarTicket(): void
    {
        [$otro] = TestDatabase::crearUsuario('22222222-2', 'Bea', 'Trabajador');
        $t = $this->svc->crear($this->hotelId, 't', 'd', Ticket::PRIORIDAD_NORMAL, $this->usuarioId);
        $r = $this->svc->asignar($t->id, $otro, $this->usuarioId);
        $this->assertSame($otro, $r->asignadoA);
    }

    public function testListarFiltraPorEstado(): void
    {
        $this->svc->crear($this->hotelId, 'A', 'd', Ticket::PRIORIDAD_NORMAL, $this->usuarioId);
        $b = $this->svc->crear($this->hotelId, 'B', 'd', Ticket::PRIORIDAD_NORMAL, $this->usuarioId);
        $this->svc->cambiarEstado($b->id, Ticket::ESTADO_RESUELTO, $this->usuarioId);

        $abiertos = $this->svc->listar(['estado' => 'abierto']);
        $this->assertCount(1, $abiertos);
        $this->assertSame('A', $abiertos[0]['titulo']);
    }
}
