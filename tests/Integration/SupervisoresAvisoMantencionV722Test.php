<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Ticket;
use Atankalama\Limpieza\Services\AvisoMantencionService;
use Atankalama\Limpieza\Services\SupervisoresService;
use Atankalama\Limpieza\Services\TicketService;
use Atankalama\Limpieza\Services\UsuarioException;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * v7.22 (reunión del 08/11/2026): supervisores de cada persona y aviso a mantención.
 *  - Al asignar un ticket, avisa a los supervisores de cada persona recién asignada (uno por supervisor,
 *    no a quien asignó, no a quien es también responsable, no al tomarlo uno mismo).
 *  - Ticket nuevo sin responsable: aviso a quien tiene tickets.aviso_mantencion, salvo a quien lo levantó.
 *  - Barra: trabajos abiertos de la persona y tickets sin responsable.
 *  - Recordatorio de la mañana: una vez por persona y día, solo si hay algo pendiente.
 */
final class SupervisoresAvisoMantencionV722Test extends TestCase
{
    private int $hotelId;
    private int $habitacionId;
    private int $habitacionPrueba;
    private int $sofia;      // Supervisora
    private int $carla;      // Supervisora
    private int $recepcion;  // Recepción (ve todos los tickets)
    private int $ana;        // Aseo
    private int $berta;      // Aseo
    private int $felix;      // Mantenimiento
    private int $jaime;      // Mantenimiento
    private int $jefe;       // Admin
    private SupervisoresService $sup;
    private TicketService $tickets;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', 'Atankalama')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        $this->hotelId = (int) Database::fetchColumn('SELECT id FROM hoteles');
        $tipo = (int) Database::fetchColumn('SELECT id FROM tipos_habitacion');
        Database::execute("INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, '302', ?, 'sucia')", [$this->hotelId, $tipo]);
        $this->habitacionId = Database::lastInsertId();
        Database::execute("INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado, es_prueba) VALUES (?, 'PRUEBA', ?, 'sucia', 1)", [$this->hotelId, $tipo]);
        $this->habitacionPrueba = Database::lastInsertId();

        // Rol Mantenimiento como el de producción: ve todos los tickets y tiene el aviso.
        Database::execute("INSERT INTO roles (nombre, descripcion, es_sistema) VALUES ('Mantenimiento', 'Mantención', 0)");
        $rolMant = Database::lastInsertId();
        foreach (['tickets.crear', 'tickets.ver_todos', 'tickets.ver_propios', AvisoMantencionService::PERMISO, 'notificaciones.ver'] as $p) {
            Database::execute('INSERT INTO rol_permisos (rol_id, permiso_codigo) VALUES (?, ?)', [$rolMant, $p]);
        }

        [$this->sofia] = TestDatabase::crearUsuario('20000001-1', 'Sofía Supervisora', 'Supervisora');
        [$this->carla] = TestDatabase::crearUsuario('20000002-2', 'Carla Supervisora', 'Supervisora');
        [$this->recepcion] = TestDatabase::crearUsuario('20000003-3', 'Rosa Recepción', 'Recepción');
        [$this->ana] = TestDatabase::crearUsuario('20000004-4', 'Ana Aseo', 'Aseo');
        [$this->berta] = TestDatabase::crearUsuario('20000005-5', 'Berta Aseo', 'Aseo');
        [$this->felix] = TestDatabase::crearUsuario('20000006-6', 'Felix Rojas', 'Mantenimiento');
        [$this->jaime] = TestDatabase::crearUsuario('20000007-7', 'Jaime Flores', 'Mantenimiento');
        // El Admin del seed tiene todo el catálogo, también el aviso: se le quita, como en producción.
        [$this->jefe] = TestDatabase::crearUsuario('20000008-8', 'Jorge Jefe', 'Admin');
        Database::execute(
            "DELETE FROM rol_permisos WHERE permiso_codigo = ? AND rol_id = (SELECT id FROM roles WHERE nombre = 'Admin')",
            [AvisoMantencionService::PERMISO]
        );

        $this->sup = new SupervisoresService();
        $this->tickets = new TicketService();
    }

    /** @return list<array{usuario_id: int, titulo: string, cuerpo: string}> */
    private function notifs(string $tipo): array
    {
        return array_map(static fn(array $f): array => [
            'usuario_id' => (int) $f['usuario_id'],
            'titulo' => (string) $f['titulo'],
            'cuerpo' => (string) $f['cuerpo'],
        ], Database::fetchAll('SELECT usuario_id, titulo, cuerpo FROM notificaciones WHERE tipo = ? ORDER BY usuario_id, id', [$tipo]));
    }

    private function crearTicket(int $por, ?int $habitacion = null, int|array|null $asignados = null): Ticket
    {
        return $this->tickets->crear($this->hotelId, 'Fuga de agua', 'Gotea el lavamanos', Ticket::PRIORIDAD_NORMAL, $por, $habitacion, null, $asignados);
    }

    // ─────────────────────────── Supervisores ───────────────────────────

    public function testCandidatosSonLosQuePuedenSerSupervisorActivosYNoLaPersona(): void
    {
        [$inactiva] = TestDatabase::crearUsuario('20000009-9', 'Inés Inactiva', 'Supervisora', activo: false);
        $ids = array_column($this->sup->candidatos($this->ana), 'id');
        sort($ids);
        $esperado = [$this->sofia, $this->carla, $this->jefe];
        sort($esperado);
        $this->assertSame($esperado, $ids, 'Supervisora y Admin (usuarios.ser_supervisor); sin Recepción ni Mantenimiento ni inactivas');
        $this->assertNotContains($inactiva, $ids);
        $this->assertNotContains($this->sofia, array_column($this->sup->candidatos($this->sofia), 'id'), 'no se elige a sí misma');
    }

    public function testAgregarQuitarYValidaciones(): void
    {
        $this->sup->agregar($this->ana, $this->sofia, $this->jefe);
        $this->sup->agregar($this->ana, $this->sofia, $this->jefe); // repetir no duplica
        $this->sup->agregar($this->ana, $this->carla, $this->jefe);
        $this->assertSame(['Carla Supervisora', 'Sofía Supervisora'], array_column($this->sup->supervisoresDe($this->ana), 'nombre'));

        foreach ([[$this->ana, $this->ana], [$this->ana, $this->berta], [$this->ana, $this->recepcion]] as [$persona, $supervisor]) {
            try {
                $this->sup->agregar($persona, $supervisor, $this->jefe);
                $this->fail('Debía rechazar');
            } catch (UsuarioException $e) {
                $this->assertSame('SUPERVISOR_INVALIDO', $e->codigo);
            }
        }

        $this->sup->quitar($this->ana, $this->sofia, $this->jefe);
        $this->sup->quitar($this->ana, $this->sofia, $this->jefe); // repetir no falla
        $this->assertSame(['Carla Supervisora'], array_column($this->sup->supervisoresDe($this->ana), 'nombre'));
        $this->assertSame(3, (int) Database::fetchColumn("SELECT COUNT(*) FROM audit_log WHERE accion LIKE 'usuario.%_supervisor'"), '2 agregados y 1 quitado; los repetidos no dejan rastro');
    }

    // ─────────────────────────── Aviso al asignar ───────────────────────────

    public function testAsignarAvisaALosSupervisoresUnoPorSupervisor(): void
    {
        $this->sup->agregar($this->ana, $this->sofia, $this->jefe);
        $this->sup->agregar($this->berta, $this->sofia, $this->jefe);
        $this->sup->agregar($this->berta, $this->carla, $this->jefe);
        $t = $this->crearTicket($this->recepcion, $this->habitacionId);

        $this->tickets->asignar($t->id, [$this->ana, $this->berta], $this->recepcion);

        $avisos = $this->notifs('ticket_supervisado');
        $this->assertSame([$this->sofia, $this->carla], array_column($avisos, 'usuario_id'), 'uno por supervisor');
        $porSupervisor = array_column($avisos, null, 'usuario_id');
        $this->assertSame('Ticket asignado a Ana Aseo y Berta Aseo', $porSupervisor[$this->sofia]['titulo']);
        $this->assertSame('Ticket asignado a Berta Aseo', $porSupervisor[$this->carla]['titulo']);
        $this->assertStringContainsString('Lo asignó Rosa Recepción', $porSupervisor[$this->sofia]['cuerpo']);
        $this->assertCount(2, $this->notifs('ticket_asignado'), 'las asignadas siguen recibiendo su aviso');

        // Volver a guardar la misma lista no repite avisos.
        $this->tickets->asignar($t->id, [$this->ana, $this->berta], $this->recepcion);
        $this->assertCount(2, $this->notifs('ticket_supervisado'));
    }

    public function testNoAvisaAQuienAsignaNiAlSupervisorQueTambienEsResponsable(): void
    {
        $this->sup->agregar($this->ana, $this->sofia, $this->jefe);
        $this->sup->agregar($this->felix, $this->jefe, $this->jefe);
        $this->sup->agregar($this->felix, $this->sofia, $this->jefe);

        // Sofía asigna a Ana: Sofía no se avisa a sí misma.
        $t1 = $this->crearTicket($this->recepcion, $this->habitacionId);
        $this->tickets->asignar($t1->id, [$this->ana], $this->sofia);
        $this->assertSame([], $this->notifs('ticket_supervisado'));

        // Jorge asigna a Felix y a Sofía: Sofía es responsable (recibe el de asignación), Jorge asignó.
        $t2 = $this->crearTicket($this->recepcion, $this->habitacionId);
        $this->tickets->asignar($t2->id, [$this->felix, $this->sofia], $this->jefe);
        $this->assertSame([], $this->notifs('ticket_supervisado'));
    }

    public function testTomarloUnoMismoNoAvisaAlSupervisor(): void
    {
        $this->sup->agregar($this->felix, $this->jefe, $this->jefe);
        $t = $this->crearTicket($this->recepcion, $this->habitacionId);
        $this->tickets->asignar($t->id, [$this->felix], $this->felix);
        $this->assertSame([], $this->notifs('ticket_supervisado'));
    }

    public function testSupervisorQuePerdioElPermisoOEstaInactivoNoRecibe(): void
    {
        $this->sup->agregar($this->ana, $this->sofia, $this->jefe);
        $this->sup->agregar($this->ana, $this->carla, $this->jefe);
        Database::execute('UPDATE usuarios SET activo = 0 WHERE id = ?', [$this->carla]);
        Database::execute(
            "DELETE FROM rol_permisos WHERE permiso_codigo = 'usuarios.ser_supervisor' AND rol_id = (SELECT id FROM roles WHERE nombre = 'Supervisora')"
        );
        $t = $this->crearTicket($this->recepcion, $this->habitacionId);
        $this->tickets->asignar($t->id, [$this->ana], $this->recepcion);
        $this->assertSame([], $this->notifs('ticket_supervisado'));
    }

    public function testCrearConResponsableAvisaAlAsignadoYASuSupervisorYNoAMantencion(): void
    {
        $this->sup->agregar($this->felix, $this->jefe, $this->jefe);
        $this->crearTicket($this->sofia, $this->habitacionId, [$this->felix]);

        $this->assertSame([$this->felix], array_column($this->notifs('ticket_asignado'), 'usuario_id'));
        $this->assertSame([$this->jefe], array_column($this->notifs('ticket_supervisado'), 'usuario_id'));
        $this->assertSame([], $this->notifs(AvisoMantencionService::NOTIF_TICKET_NUEVO));
    }

    public function testTicketDePruebaNoAvisaANadie(): void
    {
        $this->sup->agregar($this->ana, $this->sofia, $this->jefe);
        $this->crearTicket($this->recepcion, $this->habitacionPrueba);
        $this->crearTicket($this->recepcion, $this->habitacionPrueba, [$this->ana]);
        $this->assertSame([], $this->notifs(AvisoMantencionService::NOTIF_TICKET_NUEVO));
        $this->assertSame([], $this->notifs('ticket_supervisado'));
    }

    // ─────────────────────────── Aviso a mantención ───────────────────────────

    public function testTicketNuevoSinResponsableAvisaAMantencionMenosAQuienLoLevanto(): void
    {
        $this->crearTicket($this->felix, $this->habitacionId);
        $avisos = $this->notifs(AvisoMantencionService::NOTIF_TICKET_NUEVO);
        $this->assertSame([$this->jaime], array_column($avisos, 'usuario_id'));
        $this->assertSame('Ticket nuevo: Fuga de agua', $avisos[0]['titulo']);
        $this->assertSame('Felix Rojas lo levantó en la habitación 302 (Atankalama). Todavía no tiene responsable.', $avisos[0]['cuerpo']);

        $this->crearTicket($this->ana);
        $avisos = $this->notifs(AvisoMantencionService::NOTIF_TICKET_NUEVO);
        $this->assertSame([$this->felix, $this->jaime, $this->jaime], array_column($avisos, 'usuario_id'));
        $this->assertStringContainsString('Ana Aseo lo levantó en Atankalama.', end($avisos)['cuerpo']);
    }

    public function testResumenDeLaBarra(): void
    {
        $svc = new AvisoMantencionService();
        $this->assertSame(['mios' => 0, 'sin_responsable' => 0], $svc->resumenDe($this->felix));

        $a = $this->crearTicket($this->ana, $this->habitacionId);
        $b = $this->crearTicket($this->ana, $this->habitacionId);
        $this->crearTicket($this->ana, $this->habitacionId);                       // sin responsable
        $this->crearTicket($this->ana, $this->habitacionPrueba);                   // de prueba: no cuenta
        $this->tickets->asignar($a->id, [$this->felix, $this->jaime], $this->sofia);
        $this->tickets->asignar($b->id, [$this->felix], $this->sofia);
        $this->tickets->cambiarEstado($b->id, Ticket::ESTADO_EN_PROGRESO, $this->felix);
        $c = $this->crearTicket($this->ana, $this->habitacionId, [$this->felix]);
        $this->tickets->cambiarEstado($c->id, Ticket::ESTADO_RESUELTO, $this->felix); // resuelto: ya no

        $this->assertSame(['mios' => 2, 'sin_responsable' => 1], $svc->resumenDe($this->felix));
        $this->assertSame(['mios' => 1, 'sin_responsable' => 1], $svc->resumenDe($this->jaime));
        $this->assertSame('Tienes 2 trabajos abiertos · 1 ticket sin responsable', AvisoMantencionService::textoResumen($svc->resumenDe($this->felix)));
        $this->assertSame('3 tickets sin responsable', AvisoMantencionService::textoResumen(['mios' => 0, 'sin_responsable' => 3]));
    }

    public function testRecordatorioUnaVezPorDiaYSoloConAlgoPendiente(): void
    {
        $svc = new AvisoMantencionService();
        $hoy = date('Y-m-d');
        $this->assertSame(0, $svc->enviarRecordatorioDiario($hoy), 'sin tickets, no molesta');

        $t = $this->crearTicket($this->ana, $this->habitacionId, [$this->felix]);
        // Jaime no tiene nada suyo y no hay sin responsable: no recibe.
        $this->assertSame(1, $svc->enviarRecordatorioDiario($hoy));
        $this->assertSame(0, $svc->enviarRecordatorioDiario($hoy), 'la segunda pasada del cron no repite');
        $avisos = $this->notifs(AvisoMantencionService::NOTIF_RECORDATORIO);
        $this->assertSame([$this->felix], array_column($avisos, 'usuario_id'));
        $this->assertSame('Tienes 1 trabajo abierto.', $avisos[0]['cuerpo']);

        // Otro día vuelve a avisar.
        $manana = date('Y-m-d', strtotime('+1 day'));
        $this->assertSame(1, $svc->enviarRecordatorioDiario($manana));
        $this->tickets->cambiarEstado($t->id, Ticket::ESTADO_RESUELTO, $this->felix);
        $pasado = date('Y-m-d', strtotime('+2 day'));
        $this->assertSame(0, $svc->enviarRecordatorioDiario($pasado), 'resuelto: ya no hay nada pendiente');
    }

    public function testUsuariosAsignablesMarcanAQuienAtiendeTickets(): void
    {
        $marcados = array_column(array_filter(
            $this->tickets->usuariosActivos(),
            static fn(array $u): bool => $u['aviso_mantencion']
        ), 'id');
        sort($marcados);
        $this->assertSame([$this->felix, $this->jaime], $marcados);
    }
}
