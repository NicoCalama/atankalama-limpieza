<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\TicketsController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Models\Usuario;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * v7.9 (gerencia, 16/10/2026): al crear un ticket se puede agregar un comentario opcional (máx. 500), para que
 * mantención sepa el detalle. Si viene, es la descripción del ticket; si no, la descripción sigue siendo el
 * nombre del tipo (como en la v7.4).
 */
final class TicketComentarioV79Test extends TestCase
{
    private int $berta;
    private int $hotel;
    private int $tipo;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        $this->hotel = (int) Database::fetchColumn("SELECT id FROM hoteles WHERE codigo = '1_sur'");
        [$this->berta] = TestDatabase::crearUsuario('22222222-2', 'Berta', 'Trabajador');
        $this->tipo = TestDatabase::opcionId('ticket_tipo', 'Fuga o problema de agua');
    }

    /** @param array<string, mixed> $cuerpo */
    private function crear(array $cuerpo): Response
    {
        $r = new Request(metodo: 'POST', path: '/api/tickets', cuerpo: $cuerpo + [
            'hotel_id' => $this->hotel,
            'tipo_id' => $this->tipo,
        ], ruta: [], query: [], cookies: [], headers: []);
        $r->usuario = new Usuario(
            id: $this->berta,
            rut: '22222222-2',
            nombre: 'Berta',
            email: null,
            activo: true,
            requiereCambioPwd: false,
            hotelDefault: null,
            temaPreferido: 'claro',
            permisos: ['tickets.crear', 'tickets.ver_propios'],
            roles: ['Trabajador'],
        );
        return (new TicketsController())->crear($r);
    }

    /** @return array<string, mixed> */
    private function ticket(Response $r): array
    {
        $this->assertSame(201, $r->status, $r->cuerpo);
        return json_decode($r->cuerpo, true)['data']['ticket'];
    }

    public function testElComentarioQuedaComoDescripcionYElTipoComoTitulo(): void
    {
        $ticket = $this->ticket($this->crear(['comentario' => "  Gotea la llave del lavamanos.\r\nTraer golilla de 1/2\"  "]));

        $this->assertSame('Fuga o problema de agua', $ticket['titulo']);
        $this->assertSame("Gotea la llave del lavamanos.\nTraer golilla de 1/2\"", $ticket['descripcion'], 'sin espacios en los bordes y con «\n»');
        $this->assertSame($ticket['descripcion'], Database::fetchColumn('SELECT descripcion FROM tickets WHERE id = ?', [$ticket['id']]));
    }

    public function testSinComentarioLaDescripcionSigueSiendoElTipo(): void
    {
        $sinCampo = $this->ticket($this->crear([]));
        $soloEspacios = $this->ticket($this->crear(['comentario' => "  \r\n  "]));

        $this->assertSame('Fuga o problema de agua', $sinCampo['descripcion']);
        $this->assertSame('Fuga o problema de agua', $soloEspacios['descripcion']);
    }

    public function testElTopeEsDe500CaracteresContandoTildesYSaltosComoUno(): void
    {
        // 500 con «ñ» (2 bytes) y un salto «\r\n» (como lo manda el navegador): entra.
        $justo = str_repeat('ñ', 250) . "\r\n" . str_repeat('a', 249);
        $this->assertSame(500, mb_strlen($this->ticket($this->crear(['comentario' => $justo]))['descripcion']));

        $antes = (int) Database::fetchColumn('SELECT COUNT(*) FROM tickets');
        $largo = $this->crear(['comentario' => str_repeat('a', 501)]);
        $this->assertSame(400, $largo->status);
        $this->assertSame('COMENTARIO_MUY_LARGO', json_decode($largo->cuerpo, true)['error']['codigo']);
        $this->assertSame($antes, (int) Database::fetchColumn('SELECT COUNT(*) FROM tickets'), 'no crea el ticket');
    }
}
