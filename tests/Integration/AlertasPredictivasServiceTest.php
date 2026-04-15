<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\AlertaActiva;
use Atankalama\Limpieza\Services\AlertasPredictivasService;
use Atankalama\Limpieza\Services\AlertasService;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class AlertasPredictivasServiceTest extends TestCase
{
    private AlertasPredictivasService $svc;
    private int $hotelId;
    private int $tipoId;
    private int $turnoId;
    private string $fecha = '2026-04-15';

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        Database::execute("INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES ('mañana', '08:00', '16:00')");
        TestDatabase::sembrarChecklistTemplates();

        $this->hotelId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $this->tipoId = (int) Database::fetchOne('SELECT id FROM tipos_habitacion LIMIT 1')['id'];
        $this->turnoId = (int) Database::fetchOne('SELECT id FROM turnos LIMIT 1')['id'];

        $this->svc = new AlertasPredictivasService();
    }

    private function crearTrabajadorEnTurno(string $rut, string $nombre): int
    {
        [$id] = TestDatabase::crearUsuario($rut, $nombre, 'Trabajador');
        Database::execute(
            'INSERT INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)',
            [$id, $this->turnoId, $this->fecha]
        );
        return $id;
    }

    private function crearHabitacionAsignada(int $usuarioId, string $numero): int
    {
        Database::execute(
            'INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, ?, ?, ?)',
            [$this->hotelId, $numero, $this->tipoId, 'sucia']
        );
        $habId = Database::lastInsertId();
        (new AsignacionService())->asignarManual($habId, $usuarioId, $this->fecha);
        return $habId;
    }

    public function testTrabajadorEnRiesgoLevantaP1(): void
    {
        $uid = $this->crearTrabajadorEnTurno('11111111-1', 'Ana');
        for ($i = 1; $i <= 6; $i++) {
            $this->crearHabitacionAsignada($uid, "10{$i}");
        }
        // 6 hab × 30 min fallback = 180 min estimados; quedan 60 min hasta 16:00; margen 15 → umbral 45 < 180 → riesgo
        $stats = $this->svc->evaluarTrabajador($uid, $this->fecha, '16:00', '15:00');

        $this->assertTrue($stats['en_riesgo_levantada']);
        $alerta = Database::fetchOne(
            "SELECT * FROM alertas_activas WHERE tipo = 'trabajador_en_riesgo'"
        );
        $this->assertNotNull($alerta);
        $this->assertSame(1, (int) $alerta['prioridad']);
    }

    public function testTrabajadorSinSobrecargaNoLevanta(): void
    {
        $uid = $this->crearTrabajadorEnTurno('11111111-1', 'Ana');
        $this->crearHabitacionAsignada($uid, '101');
        // 1 × 30 = 30 min; quedan 6h = 360; umbral 345 → no riesgo
        $stats = $this->svc->evaluarTrabajador($uid, $this->fecha, '16:00', '10:00');

        $this->assertFalse($stats['en_riesgo_levantada']);
        $this->assertFalse($stats['fin_turno_levantada']);
    }

    public function testRiesgoSeResuelveAutomaticamenteCuandoCondicionDesaparece(): void
    {
        $uid = $this->crearTrabajadorEnTurno('11111111-1', 'Ana');
        for ($i = 1; $i <= 6; $i++) {
            $this->crearHabitacionAsignada($uid, "10{$i}");
        }
        $this->svc->evaluarTrabajador($uid, $this->fecha, '16:00', '15:00');
        $this->assertNotNull(Database::fetchOne("SELECT id FROM alertas_activas WHERE tipo='trabajador_en_riesgo'"));

        // Cambia las habitaciones a aprobadas (ya no cuentan)
        Database::execute("UPDATE habitaciones SET estado = 'aprobada' WHERE hotel_id = ?", [$this->hotelId]);

        $stats = $this->svc->evaluarTrabajador($uid, $this->fecha, '16:00', '15:00');
        $this->assertFalse($stats['en_riesgo_levantada']);
        $this->assertGreaterThanOrEqual(1, $stats['resueltas']);
        $this->assertNull(Database::fetchOne("SELECT id FROM alertas_activas WHERE tipo='trabajador_en_riesgo'"));
    }

    public function testFinTurnoPendientesLevantaP1(): void
    {
        $uid = $this->crearTrabajadorEnTurno('11111111-1', 'Ana');
        $this->crearHabitacionAsignada($uid, '101');
        // Quedan 20 min; default anticipo 30 → activa fin_turno
        $stats = $this->svc->evaluarTrabajador($uid, $this->fecha, '16:00', '15:40');
        $this->assertTrue($stats['fin_turno_levantada']);
    }

    public function testTiempoPromedioPersonalNullSiPocosDatos(): void
    {
        $uid = $this->crearTrabajadorEnTurno('11111111-1', 'Ana');
        $this->assertNull($this->svc->tiempoPromedioPersonal($uid));
    }

    public function testTiempoPromedioPersonalCalculaDesdeHistorial(): void
    {
        $uid = $this->crearTrabajadorEnTurno('11111111-1', 'Ana');
        $habId = $this->crearHabitacionAsignada($uid, '101');
        $asigId = (int) Database::fetchOne('SELECT id FROM asignaciones WHERE habitacion_id = ?', [$habId])['id'];
        $tplId = (int) Database::fetchOne('SELECT id FROM checklists_template LIMIT 1')['id'];

        // 5 ejecuciones de ~25 min cada una
        for ($i = 0; $i < 5; $i++) {
            Database::execute(
                "INSERT INTO ejecuciones_checklist (habitacion_id, asignacion_id, usuario_id, template_id, estado, timestamp_inicio, timestamp_fin)
                 VALUES (?, ?, ?, ?, 'completada', '2026-04-14T10:00:00.000Z', '2026-04-14T10:25:00.000Z')",
                [$habId, $asigId, $uid, $tplId]
            );
        }

        $promedio = $this->svc->tiempoPromedioPersonal($uid);
        $this->assertSame(25, $promedio);
    }

    public function testRecalcularTodosNoFallaSinTrabajadores(): void
    {
        $stats = $this->svc->recalcularTodos($this->fecha, '12:00');
        $this->assertSame(0, $stats['evaluados']);
    }

    public function testDedupePreviene2AlertasParaMismaCondicion(): void
    {
        $uid = $this->crearTrabajadorEnTurno('11111111-1', 'Ana');
        for ($i = 1; $i <= 6; $i++) {
            $this->crearHabitacionAsignada($uid, "10{$i}");
        }
        $this->svc->evaluarTrabajador($uid, $this->fecha, '16:00', '15:00');
        $this->svc->evaluarTrabajador($uid, $this->fecha, '16:00', '15:00');

        $count = Database::fetchOne(
            "SELECT COUNT(*) AS n FROM alertas_activas WHERE tipo = 'trabajador_en_riesgo'"
        )['n'];
        $this->assertSame(1, (int) $count);
    }
}
