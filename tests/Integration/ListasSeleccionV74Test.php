<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\HabitacionesController;
use Atankalama\Limpieza\Controllers\TicketsController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Models\Usuario;
use Atankalama\Limpieza\Services\TicketService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * v7.4 (gerencia, 10/10/2026: sin cuadros de texto): el mensaje de Recepción a la mucama, el tipo de problema
 * de un ticket y sus comentarios se eligen de listas (Ajustes → Checklists y listas). Texto escrito sin la
 * opción → OPCION_REQUERIDA; opción de otra lista o desactivada → OPCION_INVALIDA.
 */
final class ListasSeleccionV74Test extends TestCase
{
    private int $carla;
    private int $hotel;
    private int $hab;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        $this->hotel = (int) Database::fetchColumn("SELECT id FROM hoteles WHERE codigo = '1_sur'");
        $tipo = (int) Database::fetchColumn('SELECT id FROM tipos_habitacion LIMIT 1');
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado, es_espacio_comun) VALUES (?, '101', ?, 'sucia', 0)",
            [$this->hotel, $tipo]
        );
        $this->hab = Database::lastInsertId();
        [$this->carla] = TestDatabase::crearUsuario('33333333-3', 'Carla', 'Recepción');
    }

    /**
     * @param array<string, mixed> $cuerpo
     * @param array<string, string> $ruta
     */
    private function req(array $cuerpo, array $ruta = []): Request
    {
        $r = new Request(metodo: 'POST', path: '/api', cuerpo: $cuerpo, ruta: $ruta, query: [], cookies: [], headers: []);
        $r->usuario = new Usuario(
            id: $this->carla,
            rut: '33333333-3',
            nombre: 'Carla',
            email: null,
            activo: true,
            requiereCambioPwd: false,
            hotelDefault: null,
            temaPreferido: 'claro',
            permisos: ['habitaciones.agregar_nota', 'tickets.crear', 'tickets.ver_propios'],
            roles: ['Recepción'],
        );
        return $r;
    }

    /** @return array<string, mixed> */
    private function json(Response $r): array
    {
        return json_decode($r->cuerpo, true);
    }

    private function codigo(Response $r): ?string
    {
        return $this->json($r)['error']['codigo'] ?? null;
    }

    public function testElMensajeALaMucamaSeArmaConUnaOVariasOpciones(): void
    {
        $ctrl = new HabitacionesController();
        $ruta = ['id' => (string) $this->hab];

        $escrito = $ctrl->agregarNota($this->req(['nota' => 'cama extra por favor'], $ruta));
        $this->assertSame(400, $escrito->status);
        $this->assertSame('OPCION_REQUERIDA', $this->codigo($escrito));

        $otraLista = $ctrl->agregarNota($this->req(['opcion_ids' => [TestDatabase::opcionId('saltar', 'Otro')]], $ruta));
        $this->assertSame('OPCION_INVALIDA', $this->codigo($otraLista));

        $ok = $ctrl->agregarNota($this->req([
            'opcion_ids' => [TestDatabase::opcionId('nota_recepcion', 'Cuna'), TestDatabase::opcionId('nota_recepcion', 'Cama extra')],
        ], $ruta));
        $this->assertSame(200, $ok->status);
        $this->assertSame('Cama extra · Cuna', $this->json($ok)['data']['habitacion']['nota_recepcion'], 'en el orden de la lista');
    }

    public function testElTicketNuevoSeCreaPorTipoDeProblema(): void
    {
        $ctrl = new TicketsController();

        $escrito = $ctrl->crear($this->req(['hotel_id' => $this->hotel, 'titulo' => 'Fuga', 'descripcion' => 'gotea la ducha']));
        $this->assertSame(400, $escrito->status);
        $this->assertSame('OPCION_REQUERIDA', $this->codigo($escrito));

        $tipo = TestDatabase::opcionId('ticket_tipo', 'Fuga o problema de agua');
        $ok = $ctrl->crear($this->req(['hotel_id' => $this->hotel, 'habitacion_id' => $this->hab, 'tipo_id' => $tipo]));
        $this->assertSame(201, $ok->status);
        $ticket = $this->json($ok)['data']['ticket'];
        $this->assertSame('Fuga o problema de agua', $ticket['titulo']);
        $this->assertSame('Fuga o problema de agua', $ticket['descripcion']);
        $this->assertSame($tipo, (int) Database::fetchColumn('SELECT tipo_id FROM tickets WHERE id = ?', [$ticket['id']]));
    }

    public function testLosComentariosSonRespuestasDeLaLista(): void
    {
        $ticket = (new TicketService())->crear($this->hotel, 'Luz o ampolleta', 'Luz o ampolleta', 'normal', $this->carla);
        $ctrl = new TicketsController();
        $ruta = ['id' => (string) $ticket->id];

        $escrito = $ctrl->comentar($this->req(['comentario' => 'ya voy'], $ruta));
        $this->assertSame(400, $escrito->status);
        $this->assertSame('OPCION_REQUERIDA', $this->codigo($escrito));

        $ok = $ctrl->comentar($this->req(['opcion_id' => TestDatabase::opcionId('ticket_respuesta', 'En reparación')], $ruta));
        $this->assertSame(200, $ok->status);
        $this->assertSame(['En reparación'], array_column($this->json($ok)['data']['comentarios'], 'comentario'));
    }
}
