<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Models\Usuario;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\Copilot\CopilotToolExecutor;
use Atankalama\Limpieza\Services\UsuarioService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Ejercita las tools del copilot contra los services reales, fijando sus
 * firmas: un argumento mal pasado (p.ej. int donde va string $fecha) revienta
 * aquí con TypeError aunque el copilot esté deshabilitado por flag.
 */
final class CopilotToolExecutorTest extends TestCase
{
    private CopilotToolExecutor $executor;
    private int $hotel1Id;
    private int $hotel2Id;
    private int $tipoId;
    private int $adminId;
    private int $trabajadorId;
    private Usuario $admin;
    private Usuario $trabajador;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO #__hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO #__hoteles (codigo, nombre) VALUES ('inn', 'Inn')");
        Database::execute("INSERT INTO #__tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();

        $this->hotel1Id = (int) Database::fetchOne("SELECT id FROM #__hoteles WHERE codigo='1_sur'")['id'];
        $this->hotel2Id = (int) Database::fetchOne("SELECT id FROM #__hoteles WHERE codigo='inn'")['id'];
        $this->tipoId = (int) Database::fetchOne("SELECT id FROM #__tipos_habitacion LIMIT 1")['id'];

        [$this->adminId] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');
        [$this->trabajadorId] = TestDatabase::crearUsuario('22222222-2', 'Juan', 'Trabajador');

        $svcUsuario = new UsuarioService();
        $this->admin = $svcUsuario->buscarPorId($this->adminId);
        $this->trabajador = $svcUsuario->buscarPorId($this->trabajadorId);

        $this->executor = new CopilotToolExecutor();
    }

    private function crearHabitacion(int $hotelId, string $numero, string $estado = 'sucia'): int
    {
        Database::execute(
            'INSERT INTO #__habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, ?, ?, ?)',
            [$hotelId, $numero, $this->tipoId, $estado]
        );
        return Database::lastInsertId();
    }

    // --- asignar_habitacion ---

    public function testAsignarHabitacionCreaAsignacionParaHoy(): void
    {
        $hab = $this->crearHabitacion($this->hotel1Id, '101');

        $r = $this->executor->ejecutar(
            'asignar_habitacion',
            ['habitacion_id' => $hab, 'usuario_id' => $this->trabajadorId],
            $this->admin
        );

        $this->assertTrue($r['ok'], 'La tool falló: ' . ($r['error'] ?? ''));
        $this->assertIsInt($r['resultado']['asignacion_id']);
        $this->assertGreaterThan(0, $r['resultado']['asignacion_id']);

        $fila = Database::fetchOne('SELECT * FROM #__asignaciones WHERE id = ?', [$r['resultado']['asignacion_id']]);
        $this->assertNotNull($fila);
        $this->assertSame($hab, (int) $fila['habitacion_id']);
        $this->assertSame($this->trabajadorId, (int) $fila['usuario_id']);
        $this->assertSame(date('Y-m-d'), (string) $fila['fecha']);
        $this->assertSame($this->adminId, (int) $fila['asignado_por']);
    }

    public function testAsignarHabitacionSinPermisoNivel2Falla(): void
    {
        $hab = $this->crearHabitacion($this->hotel1Id, '101');

        $r = $this->executor->ejecutar(
            'asignar_habitacion',
            ['habitacion_id' => $hab, 'usuario_id' => $this->trabajadorId],
            $this->trabajador
        );

        $this->assertFalse($r['ok']);
        $this->assertNotNull($r['error']);
        $this->assertSame(0, (int) Database::fetchOne('SELECT COUNT(*) AS n FROM #__asignaciones')['n']);
    }

    // --- listar_habitaciones_hotel ---

    public function testListarHabitacionesHotelFiltraPorHotel(): void
    {
        $this->crearHabitacion($this->hotel1Id, '101');
        $this->crearHabitacion($this->hotel2Id, '201');

        $r = $this->executor->ejecutar('listar_habitaciones_hotel', ['hotel' => '1_sur'], $this->admin);

        $this->assertTrue($r['ok'], 'La tool falló: ' . ($r['error'] ?? ''));
        $this->assertSame(1, $r['resultado']['total']);
        $this->assertSame('101', (string) $r['resultado']['habitaciones'][0]['numero']);
    }

    public function testListarHabitacionesHotelAmbosListaTodas(): void
    {
        $this->crearHabitacion($this->hotel1Id, '101');
        $this->crearHabitacion($this->hotel2Id, '201');

        $r = $this->executor->ejecutar('listar_habitaciones_hotel', ['hotel' => 'ambos'], $this->admin);

        $this->assertTrue($r['ok'], 'La tool falló: ' . ($r['error'] ?? ''));
        $this->assertSame(2, $r['resultado']['total']);
    }

    // --- completar_habitacion ---

    public function testCompletarHabitacionConEjecucionEnProgreso(): void
    {
        $hab = $this->crearHabitacion($this->hotel1Id, '101');
        $hoy = date('Y-m-d');

        // El admin tiene todos los permisos, así que actúa como trabajador aquí
        (new AsignacionService())->asignarManual($hab, $this->adminId, $hoy);
        $checklists = new ChecklistService();
        $ejec = $checklists->iniciarEjecucion($hab, $this->adminId, $hoy);
        foreach ($checklists->itemsDelTemplate($ejec->templateId) as $item) {
            $checklists->marcarItem($ejec->id, (int) $item['id'], true, $this->adminId);
        }

        $r = $this->executor->ejecutar('completar_habitacion', ['habitacion_id' => $hab], $this->admin);

        $this->assertTrue($r['ok'], 'La tool falló: ' . ($r['error'] ?? ''));
        $estado = Database::fetchOne('SELECT estado FROM #__habitaciones WHERE id = ?', [$hab]);
        $this->assertSame(Habitacion::ESTADO_COMPLETADA_PENDIENTE_AUDITORIA, $estado['estado']);
    }

    public function testCompletarHabitacionSinEjecucionEnProgresoFalla(): void
    {
        $hab = $this->crearHabitacion($this->hotel1Id, '101');

        $r = $this->executor->ejecutar('completar_habitacion', ['habitacion_id' => $hab], $this->admin);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('en progreso', (string) $r['error']);
    }

    // --- tools de consulta restantes (fijan firmas de los services) ---

    public function testVerEstadoEquipoRetornaResumen(): void
    {
        $hab = $this->crearHabitacion($this->hotel1Id, '101');
        (new AsignacionService())->asignarManual($hab, $this->trabajadorId, date('Y-m-d'));

        $r = $this->executor->ejecutar('ver_estado_equipo', [], $this->admin);

        $this->assertTrue($r['ok'], 'La tool falló: ' . ($r['error'] ?? ''));
        $this->assertSame(date('Y-m-d'), $r['resultado']['fecha']);
        $this->assertSame(1, $r['resultado']['habitaciones_pendientes']);
        $this->assertSame(0, $r['resultado']['habitaciones_completadas']);
    }

    public function testListarAlertasActivas(): void
    {
        $r = $this->executor->ejecutar('listar_alertas_activas', [], $this->admin);

        $this->assertTrue($r['ok'], 'La tool falló: ' . ($r['error'] ?? ''));
        $this->assertSame(0, $r['resultado']['total']);
    }

    public function testListarTicketsYCrearTicket(): void
    {
        $rCrear = $this->executor->ejecutar('crear_ticket', [
            'hotel_id' => $this->hotel1Id,
            'titulo' => 'Ampolleta quemada',
            'descripcion' => 'Lámpara del velador no enciende',
            'prioridad' => 'normal',
        ], $this->admin);

        $this->assertTrue($rCrear['ok'], 'La tool falló: ' . ($rCrear['error'] ?? ''));

        $rListar = $this->executor->ejecutar('listar_tickets', ['estado' => 'abierto'], $this->admin);
        $this->assertTrue($rListar['ok'], 'La tool falló: ' . ($rListar['error'] ?? ''));
        $this->assertSame(1, $rListar['resultado']['total']);
    }

    public function testListarMisHabitaciones(): void
    {
        $hab = $this->crearHabitacion($this->hotel1Id, '101');
        (new AsignacionService())->asignarManual($hab, $this->trabajadorId, date('Y-m-d'));

        $r = $this->executor->ejecutar('listar_mis_habitaciones', [], $this->trabajador);

        $this->assertTrue($r['ok'], 'La tool falló: ' . ($r['error'] ?? ''));
        $this->assertSame(1, $r['resultado']['total']);
    }
}
