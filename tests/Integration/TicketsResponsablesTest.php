<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\TicketsController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Models\Ticket;
use Atankalama\Limpieza\Services\TicketException;
use Atankalama\Limpieza\Services\TicketService;
use Atankalama\Limpieza\Services\UsuarioService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tickets con varios responsables (tabla tickets_asignados). tickets.asignado_a guarda solo
 * al responsable "principal"; los permisos de "es su ticket" deben mirar la lista completa.
 */
final class TicketsResponsablesTest extends TestCase
{
    private TicketService $svc;
    private int $hotelId;
    private int $supervisoraId;
    private int $anaId;
    private int $betoId;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        $this->hotelId = (int) Database::fetchOne('SELECT id FROM hoteles')['id'];
        [$this->supervisoraId] = TestDatabase::crearUsuario('15000001-7', 'Sofía', 'Supervisora');
        [$this->anaId] = TestDatabase::crearUsuario('16000001-5', 'Ana', 'Trabajador');
        [$this->betoId] = TestDatabase::crearUsuario('17000001-3', 'Beto', 'Trabajador');
        $this->svc = new TicketService();
    }

    private function crearTicket(): Ticket
    {
        return $this->svc->crear($this->hotelId, 'Ducha rota', 'No sale agua caliente.', Ticket::PRIORIDAD_NORMAL, $this->supervisoraId);
    }

    private function pedirCambioEstado(int $ticketId, int $usuarioId, string $estado): int
    {
        $req = new Request(
            metodo: 'PUT',
            path: "/api/tickets/{$ticketId}/estado",
            cuerpo: ['estado' => $estado],
            ruta: ['id' => (string) $ticketId],
            query: [],
            cookies: [],
            headers: [],
        );
        $req->usuario = (new UsuarioService())->buscarPorId($usuarioId);
        return (new TicketsController())->cambiarEstado($req)->status;
    }

    /** @param list<int> $usuarioIds */
    private function pedirAsignacion(int $ticketId, int $usuarioId, array $usuarioIds): int
    {
        $req = new Request(
            metodo: 'PUT',
            path: "/api/tickets/{$ticketId}/asignar",
            cuerpo: ['usuario_ids' => $usuarioIds],
            ruta: ['id' => (string) $ticketId],
            query: [],
            cookies: [],
            headers: [],
        );
        $req->usuario = (new UsuarioService())->buscarPorId($usuarioId);
        return (new TicketsController())->asignar($req)->status;
    }

    public function testCualquierResponsablePuedeResolver(): void
    {
        $t = $this->crearTicket();
        $this->svc->asignar($t->id, [$this->anaId, $this->betoId], $this->supervisoraId);

        // Beto no es el responsable principal (asignado_a), pero sí es responsable.
        $this->assertSame(200, $this->pedirCambioEstado($t->id, $this->betoId, Ticket::ESTADO_RESUELTO));
        $this->assertSame(Ticket::ESTADO_RESUELTO, $this->svc->obtenerOFallar($t->id)->estado);
    }

    public function testQuienNoEsResponsableNoPuedeCambiarElEstado(): void
    {
        $t = $this->crearTicket();
        $this->svc->asignar($t->id, [$this->anaId], $this->supervisoraId);

        $this->assertSame(403, $this->pedirCambioEstado($t->id, $this->betoId, Ticket::ESTADO_RESUELTO));
        $this->assertSame(Ticket::ESTADO_ABIERTO, $this->svc->obtenerOFallar($t->id)->estado);
    }

    public function testAgregarCorresponsableConservaAlPrincipal(): void
    {
        $t = $this->crearTicket();
        $this->svc->asignar($t->id, [$this->betoId], $this->supervisoraId);

        // La UI reenvía la lista ordenada por nombre: Ana queda primera, pero Beto sigue
        // siendo el principal porque sigue en la lista.
        $t2 = $this->svc->asignar($t->id, [$this->anaId, $this->betoId], $this->supervisoraId);
        $this->assertSame($this->betoId, $t2->asignadoA);
        $this->assertCount(2, $t2->responsables);

        // Si el principal sale de la lista, el principal pasa al primero de la lista nueva.
        $t3 = $this->svc->asignar($t->id, [$this->anaId], $this->supervisoraId);
        $this->assertSame($this->anaId, $t3->asignadoA);
    }

    public function testResponsablesNoExponenEmail(): void
    {
        $t = $this->crearTicket();
        $t2 = $this->svc->asignar($t->id, [$this->anaId], $this->supervisoraId);
        $this->assertArrayNotHasKey('email', $t2->responsables[0]);
    }

    public function testCrearConResponsableInactivoNoDejaTicketCreado(): void
    {
        [$inactivoId] = TestDatabase::crearUsuario('18000001-1', 'Carla', 'Trabajador', activo: false);

        try {
            $this->svc->crear(
                $this->hotelId, 'Luz quemada', 'Pasillo 2.', Ticket::PRIORIDAD_NORMAL, $this->supervisoraId,
                asignadoA: [$this->anaId, $inactivoId],
            );
            $this->fail('Debía lanzar');
        } catch (TicketException $e) {
            $this->assertSame('USUARIO_NO_ENCONTRADO', $e->codigo);
        }
        // Antes validaba DESPUÉS de insertar: el ticket (y su alerta) quedaban creados y cada
        // reintento del usuario lo duplicaba.
        $this->assertSame(0, (int) Database::fetchOne('SELECT COUNT(*) AS n FROM tickets')['n']);
    }

    public function testSupervisoraPuedeAgregarAunqueHayaUnResponsableDeOtroPerfil(): void
    {
        // Un Admin designó a otra Supervisora (perfil que una Supervisora no puede designar).
        [$supervisora2Id] = TestDatabase::crearUsuario('19000001-K', 'Sara', 'Supervisora');
        $t = $this->crearTicket();
        $this->svc->asignar($t->id, [$supervisora2Id], $this->supervisoraId);

        // La UI reenvía la lista completa: la Supervisora existente + Ana, que se agrega.
        $this->assertSame(200, $this->pedirAsignacion($t->id, $this->supervisoraId, [$supervisora2Id, $this->anaId]));
        $ids = array_map(static fn(array $r): int => $r['id'], $this->svc->obtenerOFallar($t->id)->responsables);
        sort($ids);
        $esperado = [$supervisora2Id, $this->anaId];
        sort($esperado);
        $this->assertSame($esperado, $ids);
    }

    public function testSupervisoraNoPuedeAgregarAOtroPerfil(): void
    {
        [$supervisora2Id] = TestDatabase::crearUsuario('19000001-K', 'Sara', 'Supervisora');
        $t = $this->crearTicket();
        $this->assertSame(403, $this->pedirAsignacion($t->id, $this->supervisoraId, [$this->anaId, $supervisora2Id]));
    }

    public function testResponsableDesactivadoSeQuitaAlEditarSinError(): void
    {
        $t = $this->crearTicket();
        $this->svc->asignar($t->id, [$this->anaId, $this->betoId], $this->supervisoraId);
        Database::execute('UPDATE usuarios SET activo = 0 WHERE id = ?', [$this->betoId]);
        [$carlaId] = TestDatabase::crearUsuario('18000001-1', 'Carla', 'Trabajador');

        // Antes: 404 «usuarios no encontrados o inactivos» por Beto, que ya no se ve en la lista.
        $this->assertSame(200, $this->pedirAsignacion($t->id, $this->supervisoraId, [$this->anaId, $this->betoId, $carlaId]));
        $ids = array_map(static fn(array $r): int => $r['id'], $this->svc->obtenerOFallar($t->id)->responsables);
        $this->assertNotContains($this->betoId, $ids);
        $this->assertContains($carlaId, $ids);
    }

    public function testAgregarUnUsuarioInactivoSigueFallando(): void
    {
        [$inactivoId] = TestDatabase::crearUsuario('18000001-1', 'Carla', 'Trabajador', activo: false);
        $t = $this->crearTicket();
        $this->assertSame(404, $this->pedirAsignacion($t->id, $this->supervisoraId, [$this->anaId, $inactivoId]));
    }

    public function testMisTicketsIncluyeLosDondeSoyCorresponsable(): void
    {
        $t = $this->crearTicket();
        $this->svc->asignar($t->id, [$this->anaId, $this->betoId], $this->supervisoraId);
        $ids = array_map(static fn(array $f): int => (int) $f['id'], $this->svc->listar(['asignado_a' => $this->betoId]));
        $this->assertContains($t->id, $ids);
        $sinAsignar = array_map(static fn(array $f): int => (int) $f['id'], $this->svc->listar(['sin_asignar' => true]));
        $this->assertNotContains($t->id, $sinAsignar);
    }

    public function testExportDeDatosPersonalesIncluyeTicketsComoCorresponsable(): void
    {
        $t = $this->crearTicket();
        $this->svc->asignar($t->id, [$this->anaId, $this->betoId], $this->supervisoraId);
        $export = (new UsuarioService())->exportarDatosPersonales($this->betoId, ocultaTimestampsKpi: true);
        $fila = array_values(array_filter($export['tickets'], static fn(array $f): bool => (int) $f['id'] === $t->id));
        $this->assertCount(1, $fila);
        $this->assertSame('asignado', $fila[0]['relacion']);
    }
}
