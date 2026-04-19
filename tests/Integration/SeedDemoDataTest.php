<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class SeedDemoDataTest extends TestCase
{
    protected function setUp(): void
    {
        TestDatabase::recrear();
        // Catálogos mínimos que el seeder demo necesita
        $catalogos = require dirname(__DIR__, 2) . '/database/seeds/catalogos.php';
        foreach ($catalogos['hoteles'] as $h) {
            Database::execute(
                'INSERT INTO hoteles (codigo, nombre, cloudbeds_property_id) VALUES (?, ?, ?)',
                [$h['codigo'], $h['nombre'], $h['cloudbeds_property_id']]
            );
        }
        foreach ($catalogos['turnos'] as $t) {
            Database::execute(
                'INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES (?, ?, ?)',
                [$t['nombre'], $t['hora_inicio'], $t['hora_fin']]
            );
        }
        foreach ($catalogos['tipos_habitacion'] as $ti) {
            Database::execute(
                'INSERT INTO tipos_habitacion (nombre, descripcion) VALUES (?, ?)',
                [$ti['nombre'], $ti['descripcion']]
            );
        }
        TestDatabase::sembrarChecklistTemplates();
        // Admin original (el seeder demo lo preserva en --reset)
        TestDatabase::crearUsuario('11111111-1', 'Admin Seed', 'Admin');

        require_once dirname(__DIR__, 2) . '/scripts/seed-demo-data.php';
    }

    public function testSeederPuebla15UsuariosYDistribuyeRoles(): void
    {
        ejecutarSeedDemo(reset: false, silencioso: true);

        $total = (int) Database::fetchOne('SELECT COUNT(*) c FROM usuarios')['c'];
        $this->assertSame(15, $total, 'Se esperaban 14 demo + 1 admin de TestDatabase');

        $countPorRol = [];
        foreach (Database::fetchAll('SELECT r.nombre, COUNT(*) c FROM usuarios_roles ur JOIN roles r ON r.id = ur.rol_id GROUP BY r.nombre') as $row) {
            $countPorRol[$row['nombre']] = (int) $row['c'];
        }
        $this->assertSame(10, $countPorRol['Trabajador']);
        $this->assertSame(2, $countPorRol['Supervisora']);
        $this->assertSame(2, $countPorRol['Recepción']);
    }

    public function testSeederCrea20HabitacionesEn2Hoteles(): void
    {
        ejecutarSeedDemo(reset: false, silencioso: true);

        $this->assertSame(20, (int) Database::fetchOne('SELECT COUNT(*) c FROM habitaciones')['c']);

        $porHotel = Database::fetchAll('SELECT h.codigo, COUNT(*) c FROM habitaciones hab JOIN hoteles h ON h.id = hab.hotel_id GROUP BY h.codigo');
        $map = array_column($porHotel, 'c', 'codigo');
        $this->assertSame(12, (int) $map['1_sur']);
        $this->assertSame(8, (int) $map['inn']);
    }

    public function testSeederGeneraAsignacionesYEjecucionesParaHoy(): void
    {
        ejecutarSeedDemo(reset: false, silencioso: true);

        $hoy = date('Y-m-d');
        $asig = (int) Database::fetchOne('SELECT COUNT(*) c FROM asignaciones WHERE fecha = ? AND activa = 1', [$hoy])['c'];
        $this->assertSame(14, $asig);

        $completadas = (int) Database::fetchOne("SELECT COUNT(*) c FROM ejecuciones_checklist WHERE estado IN ('completada','auditada')")['c'];
        $enProgreso = (int) Database::fetchOne("SELECT COUNT(*) c FROM ejecuciones_checklist WHERE estado = 'en_progreso'")['c'];
        $this->assertSame(4, $completadas);
        $this->assertSame(2, $enProgreso);
    }

    public function testSeederCreaAuditoriasConLos3Veredictos(): void
    {
        ejecutarSeedDemo(reset: false, silencioso: true);

        $veredictos = [];
        foreach (Database::fetchAll('SELECT veredicto, COUNT(*) c FROM auditorias GROUP BY veredicto') as $r) {
            $veredictos[$r['veredicto']] = (int) $r['c'];
        }
        $this->assertSame(2, $veredictos['aprobado'] ?? 0);
        $this->assertSame(1, $veredictos['aprobado_con_observacion'] ?? 0);
        $this->assertSame(1, $veredictos['rechazado'] ?? 0);
    }

    public function testSeederCreaTicketsMixPrioridades(): void
    {
        ejecutarSeedDemo(reset: false, silencioso: true);

        $total = (int) Database::fetchOne('SELECT COUNT(*) c FROM tickets')['c'];
        $this->assertSame(5, $total);

        $urgentes = (int) Database::fetchOne("SELECT COUNT(*) c FROM tickets WHERE prioridad = 'urgente'")['c'];
        $this->assertSame(1, $urgentes);
    }

    public function testSeederEsIdempotenteAlCorrerDosVeces(): void
    {
        ejecutarSeedDemo(reset: false, silencioso: true);
        $u1 = (int) Database::fetchOne('SELECT COUNT(*) c FROM usuarios')['c'];
        $t1 = (int) Database::fetchOne('SELECT COUNT(*) c FROM tickets')['c'];

        ejecutarSeedDemo(reset: false, silencioso: true);
        $u2 = (int) Database::fetchOne('SELECT COUNT(*) c FROM usuarios')['c'];
        $t2 = (int) Database::fetchOne('SELECT COUNT(*) c FROM tickets')['c'];

        $this->assertSame($u1, $u2);
        $this->assertSame($t1, $t2);
    }

    public function testResetLimpiaDemoPeroConservaAdmin(): void
    {
        ejecutarSeedDemo(reset: false, silencioso: true);
        $this->assertGreaterThan(1, (int) Database::fetchOne('SELECT COUNT(*) c FROM usuarios')['c']);

        ejecutarSeedDemo(reset: true, silencioso: true);
        $admin = Database::fetchOne("SELECT id FROM usuarios WHERE rut = '11111111-1'");
        $this->assertNotNull($admin, 'El admin original debe persistir tras --reset');
        $this->assertSame(15, (int) Database::fetchOne('SELECT COUNT(*) c FROM usuarios')['c']);
    }
}
