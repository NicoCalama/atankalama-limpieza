<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\TicketsController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Models\Ticket;
use Atankalama\Limpieza\Models\Usuario;
use Atankalama\Limpieza\Services\TicketService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * v7.19 (reunión del 08/11/2026): vuelve el cuadro de comentario opcional en el detalle del ticket, junto a las
 * respuestas de la lista, y al cerrarlo. Alcanza con la respuesta, con el texto o con ambos.
 */
final class TicketComentariosV719Test extends TestCase
{
    private int $carla;
    private int $ticketId;
    private TicketService $tickets;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        $hotel = (int) Database::fetchColumn("SELECT id FROM hoteles WHERE codigo = '1_sur'");
        [$this->carla] = TestDatabase::crearUsuario('33333333-3', 'Carla', 'Supervisora');
        $this->tickets = new TicketService();
        $this->ticketId = $this->tickets->crear($hotel, 'Luz o ampolleta', 'Luz o ampolleta', 'normal', $this->carla)->id;
    }

    /** @param array<string, mixed> $cuerpo */
    private function req(array $cuerpo): Request
    {
        $r = new Request(metodo: 'POST', path: '/api', cuerpo: $cuerpo, ruta: ['id' => (string) $this->ticketId], query: [], cookies: [], headers: []);
        $r->usuario = new Usuario(
            id: $this->carla,
            rut: '33333333-3',
            nombre: 'Carla',
            email: null,
            activo: true,
            requiereCambioPwd: false,
            hotelDefault: null,
            temaPreferido: 'claro',
            permisos: ['tickets.ver_todos'],
            roles: ['Supervisora'],
        );
        return $r;
    }

    private function codigo(Response $r): ?string
    {
        return json_decode($r->cuerpo, true)['error']['codigo'] ?? null;
    }

    /** @return list<string> */
    private function comentariosGuardados(): array
    {
        return array_column($this->tickets->comentariosDe($this->ticketId), 'comentario');
    }

    private function dejarResuelto(): void
    {
        $this->tickets->cambiarEstado($this->ticketId, Ticket::ESTADO_RESUELTO, $this->carla);
    }

    public function testSeComentaSoloConTexto(): void
    {
        $r = (new TicketsController())->comentar($this->req(['comentario' => "  falta el repuesto\r\nllega el martes  "]));

        $this->assertSame(200, $r->status, $r->cuerpo);
        $this->assertSame(["falta el repuesto\nllega el martes"], $this->comentariosGuardados());
    }

    public function testRespuestaYTextoQuedanEnUnSoloComentario(): void
    {
        $r = (new TicketsController())->comentar($this->req([
            'opcion_id' => TestDatabase::opcionId('ticket_respuesta', 'En reparación'),
            'comentario' => 'llega el martes',
        ]));

        $this->assertSame(200, $r->status, $r->cuerpo);
        $this->assertSame(['En reparación: llega el martes'], $this->comentariosGuardados());
    }

    public function testSinRespuestaNiTextoEsError(): void
    {
        $r = (new TicketsController())->comentar($this->req(['comentario' => "   \n "]));

        $this->assertSame(400, $r->status);
        $this->assertSame('OPCION_REQUERIDA', $this->codigo($r));
        $this->assertSame([], $this->comentariosGuardados());
    }

    public function testElTextoTieneElMismoLimiteQueAlCrear(): void
    {
        $justo = (new TicketsController())->comentar($this->req(['comentario' => str_repeat('ñ', TicketsController::MAX_COMENTARIO)]));
        $this->assertSame(200, $justo->status, 'cuenta caracteres, no bytes');

        $largo = (new TicketsController())->comentar($this->req(['comentario' => str_repeat('a', TicketsController::MAX_COMENTARIO + 1)]));
        $this->assertSame(400, $largo->status);
        $this->assertSame('COMENTARIO_MUY_LARGO', $this->codigo($largo));
    }

    public function testUnaRespuestaInvalidaNoGuardaElTexto(): void
    {
        $r = (new TicketsController())->comentar($this->req([
            'opcion_id' => TestDatabase::opcionId('saltar', 'Otro'),
            'comentario' => 'igual lo escribí',
        ]));

        $this->assertSame('OPCION_INVALIDA', $this->codigo($r));
        $this->assertSame([], $this->comentariosGuardados());
    }

    public function testCerrarConComentarioLoDejaEnElHistorial(): void
    {
        $this->dejarResuelto();

        $r = (new TicketsController())->cerrar($this->req(['comentario' => 'cambiada la ampolleta']));

        $this->assertSame(200, $r->status, $r->cuerpo);
        $this->assertSame(Ticket::ESTADO_CERRADO, $this->tickets->obtener($this->ticketId)?->estado);
        $this->assertSame(['cambiada la ampolleta'], $this->comentariosGuardados());
        $this->assertSame(['cambiada la ampolleta'], array_column(json_decode($r->cuerpo, true)['data']['comentarios'], 'comentario'));
    }

    public function testCerrarSinComentarioNoAgregaNada(): void
    {
        $this->dejarResuelto();

        $r = (new TicketsController())->cerrar($this->req([]));

        $this->assertSame(200, $r->status, $r->cuerpo);
        $this->assertSame([], $this->comentariosGuardados());
    }

    public function testUnComentarioDemasiadoLargoNoCierraElTicket(): void
    {
        $largo = (new TicketsController())->cerrar($this->req(['comentario' => str_repeat('a', TicketsController::MAX_COMENTARIO + 1)]));

        $this->assertSame('COMENTARIO_MUY_LARGO', $this->codigo($largo));
        $this->assertSame(Ticket::ESTADO_ABIERTO, $this->tickets->obtener($this->ticketId)?->estado);
        $this->assertSame([], $this->comentariosGuardados());
    }

    public function testCerrarDosVecesNoDuplicaElComentario(): void
    {
        (new TicketsController())->cerrar($this->req(['comentario' => 'listo']));
        $segundo = (new TicketsController())->cerrar($this->req(['comentario' => 'listo']));

        $this->assertSame(200, $segundo->status, 'cerrar de nuevo no es un error');
        $this->assertSame(['listo'], $this->comentariosGuardados());
    }
}
