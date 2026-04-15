<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\AsignacionException;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class AsignacionServiceTest extends TestCase
{
    private AsignacionService $svc;
    private int $hotel1Id;
    private int $tipoId;
    private int $turnoId;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('inn', 'Inn')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        Database::execute("INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES ('mañana', '08:00', '16:00')");

        $this->hotel1Id = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $this->tipoId = (int) Database::fetchOne("SELECT id FROM tipos_habitacion WHERE nombre='Doble'")['id'];
        $this->turnoId = (int) Database::fetchOne("SELECT id FROM turnos LIMIT 1")['id'];

        $this->svc = new AsignacionService();
    }

    private function crearHabitacion(string $numero, string $estado = 'sucia'): int
    {
        Database::execute(
            'INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, ?, ?, ?)',
            [$this->hotel1Id, $numero, $this->tipoId, $estado]
        );
        return Database::lastInsertId();
    }

    private function crearTrabajador(string $rut, string $nombre, string $fechaTurno): int
    {
        [$id] = TestDatabase::crearUsuario($rut, $nombre, 'Trabajador');
        Database::execute(
            'INSERT INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)',
            [$id, $this->turnoId, $fechaTurno]
        );
        return $id;
    }

    public function testAsignarManualCreaFila(): void
    {
        $hab = $this->crearHabitacion('101');
        [$uid] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');

        $a = $this->svc->asignarManual($hab, $uid, '2026-04-14');

        $this->assertSame($hab, $a->habitacionId);
        $this->assertSame($uid, $a->usuarioId);
        $this->assertTrue($a->activa);
        $this->assertSame(1, $a->ordenCola);
    }

    public function testReasignarDesactivaAnterior(): void
    {
        $hab = $this->crearHabitacion('101');
        [$u1] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        [$u2] = TestDatabase::crearUsuario('22222222-2', 'Bea', 'Trabajador');

        $this->svc->asignarManual($hab, $u1, '2026-04-14');
        $this->svc->reasignar($hab, $u2, '2026-04-14', 'rechazada');

        $activas = Database::fetchAll('SELECT * FROM asignaciones WHERE habitacion_id = ? AND activa = 1', [$hab]);
        $this->assertCount(1, $activas);
        $this->assertSame($u2, (int) $activas[0]['usuario_id']);
    }

    public function testOrdenColaIncrementa(): void
    {
        $h1 = $this->crearHabitacion('101');
        $h2 = $this->crearHabitacion('102');
        [$uid] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');

        $a1 = $this->svc->asignarManual($h1, $uid, '2026-04-14');
        $a2 = $this->svc->asignarManual($h2, $uid, '2026-04-14');

        $this->assertSame(1, $a1->ordenCola);
        $this->assertSame(2, $a2->ordenCola);
    }

    public function testRoundRobinReparteEquitativamente(): void
    {
        $this->crearHabitacion('101');
        $this->crearHabitacion('102');
        $this->crearHabitacion('103');
        $this->crearHabitacion('104');
        $u1 = $this->crearTrabajador('11111111-1', 'Ana', '2026-04-14');
        $u2 = $this->crearTrabajador('22222222-2', 'Bea', '2026-04-14');

        $resultado = $this->svc->autoAsignar('1_sur', '2026-04-14');

        $this->assertSame(4, $resultado['habitaciones']);
        $this->assertSame(2, $resultado['trabajadores']);

        $u1Cola = $this->svc->colaDelTrabajador($u1, '2026-04-14');
        $u2Cola = $this->svc->colaDelTrabajador($u2, '2026-04-14');
        $this->assertCount(2, $u1Cola);
        $this->assertCount(2, $u2Cola);
    }

    public function testRoundRobinSinTrabajadoresLanza(): void
    {
        $this->crearHabitacion('101');
        try {
            $this->svc->autoAsignar('1_sur', '2026-04-14');
            $this->fail('Debía lanzar');
        } catch (AsignacionException $e) {
            $this->assertSame('SIN_TRABAJADORES', $e->codigo);
        }
    }

    public function testFechaInvalidaLanza(): void
    {
        $hab = $this->crearHabitacion('101');
        [$uid] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        try {
            $this->svc->asignarManual($hab, $uid, '14-04-2026');
            $this->fail('Debía lanzar');
        } catch (AsignacionException $e) {
            $this->assertSame('FECHA_INVALIDA', $e->codigo);
        }
    }

    public function testReordenarCola(): void
    {
        $h1 = $this->crearHabitacion('101');
        $h2 = $this->crearHabitacion('102');
        $h3 = $this->crearHabitacion('103');
        [$uid] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');

        $this->svc->asignarManual($h1, $uid, '2026-04-14');
        $this->svc->asignarManual($h2, $uid, '2026-04-14');
        $this->svc->asignarManual($h3, $uid, '2026-04-14');

        $this->svc->reordenarCola($uid, '2026-04-14', [$h3, $h1, $h2]);

        $cola = $this->svc->colaDelTrabajador($uid, '2026-04-14');
        $this->assertSame('103', (string) $cola[0]['numero']);
        $this->assertSame('101', (string) $cola[1]['numero']);
        $this->assertSame('102', (string) $cola[2]['numero']);
    }

    public function testEsHabitacionAsignadaA(): void
    {
        $hab = $this->crearHabitacion('101');
        [$uid] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        [$otra] = TestDatabase::crearUsuario('22222222-2', 'Bea', 'Trabajador');
        $this->svc->asignarManual($hab, $uid, '2026-04-14');

        $this->assertTrue($this->svc->esHabitacionAsignadaA($hab, $uid, '2026-04-14'));
        $this->assertFalse($this->svc->esHabitacionAsignadaA($hab, $otra, '2026-04-14'));
    }
}
