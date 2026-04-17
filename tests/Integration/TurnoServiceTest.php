<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\TurnoException;
use Atankalama\Limpieza\Services\TurnoService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class TurnoServiceTest extends TestCase
{
    private TurnoService $svc;
    private int $adminId;
    private int $trabajadorId;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        [$this->adminId] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');
        [$this->trabajadorId] = TestDatabase::crearUsuario('22222222-2', 'Juan', 'Trabajador');
        $this->svc = new TurnoService();
    }

    public function testCrearTurno(): void
    {
        $id = $this->svc->crear('Mañana', '08:00', '16:00', $this->adminId);
        $this->assertGreaterThan(0, $id);
        $turnos = $this->svc->listar();
        $this->assertCount(1, $turnos);
        $this->assertSame('Mañana', $turnos[0]['nombre']);
    }

    public function testRangoInvalidoLanza(): void
    {
        try {
            $this->svc->crear('Invalido', '16:00', '08:00', $this->adminId);
            $this->fail('Debía lanzar');
        } catch (TurnoException $e) {
            $this->assertSame('RANGO_INVALIDO', $e->codigo);
        }
    }

    public function testHoraInvalidaLanza(): void
    {
        try {
            $this->svc->crear('X', '25:99', '26:00', $this->adminId);
            $this->fail('Debía lanzar');
        } catch (TurnoException $e) {
            $this->assertSame('HORA_INVALIDA', $e->codigo);
        }
    }

    public function testNombreDuplicadoLanza(): void
    {
        $this->svc->crear('Mañana', '08:00', '16:00', $this->adminId);
        try {
            $this->svc->crear('Mañana', '09:00', '17:00', $this->adminId);
            $this->fail('Debía lanzar');
        } catch (TurnoException $e) {
            $this->assertSame('NOMBRE_DUPLICADO', $e->codigo);
        }
    }

    public function testActualizarCambiaHoras(): void
    {
        $id = $this->svc->crear('Mañana', '08:00', '16:00', $this->adminId);
        $this->svc->actualizar($id, ['hora_inicio' => '09:00', 'hora_fin' => '17:00'], $this->adminId);
        $t = Database::fetchOne('SELECT hora_inicio, hora_fin FROM turnos WHERE id = ?', [$id]);
        $this->assertSame('09:00', $t['hora_inicio']);
        $this->assertSame('17:00', $t['hora_fin']);
    }

    public function testAsignarAUsuarioEsUpsert(): void
    {
        $t1 = $this->svc->crear('Mañana', '08:00', '16:00', $this->adminId);
        $t2 = $this->svc->crear('Tarde', '14:00', '22:00', $this->adminId);
        $id1 = $this->svc->asignarAUsuario($this->trabajadorId, $t1, '2026-04-15', $this->adminId);
        $id2 = $this->svc->asignarAUsuario($this->trabajadorId, $t2, '2026-04-15', $this->adminId);
        $this->assertSame($id1, $id2);

        $asignaciones = Database::fetchAll(
            'SELECT turno_id FROM usuarios_turnos WHERE usuario_id = ? AND fecha = ?',
            [$this->trabajadorId, '2026-04-15']
        );
        $this->assertCount(1, $asignaciones);
        $this->assertSame($t2, (int) $asignaciones[0]['turno_id']);
    }

    public function testQuitarDeUsuario(): void
    {
        $t1 = $this->svc->crear('Mañana', '08:00', '16:00', $this->adminId);
        $this->svc->asignarAUsuario($this->trabajadorId, $t1, '2026-04-15', $this->adminId);
        $this->svc->quitarDeUsuario($this->trabajadorId, '2026-04-15', $this->adminId);
        $count = (int) Database::fetchOne(
            'SELECT COUNT(*) AS n FROM usuarios_turnos WHERE usuario_id = ? AND fecha = ?',
            [$this->trabajadorId, '2026-04-15']
        )['n'];
        $this->assertSame(0, $count);
    }

    public function testTurnosDelDiaRetornaJoin(): void
    {
        $t1 = $this->svc->crear('Mañana', '08:00', '16:00', $this->adminId);
        $this->svc->asignarAUsuario($this->trabajadorId, $t1, '2026-04-15', $this->adminId);
        $filas = $this->svc->turnosDelDia('2026-04-15');
        $this->assertCount(1, $filas);
        $this->assertSame('Juan', $filas[0]['usuario_nombre']);
        $this->assertSame('Mañana', $filas[0]['turno_nombre']);
    }
}
