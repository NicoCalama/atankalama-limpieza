<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\ChecklistException;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class ChecklistServiceTest extends TestCase
{
    private ChecklistService $svc;
    private AsignacionService $asignaciones;
    private int $habitacionId;
    private int $usuarioId;
    private string $fecha = '2026-04-14';

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();

        $hotelId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $tipoId = (int) Database::fetchOne("SELECT id FROM tipos_habitacion LIMIT 1")['id'];

        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, '101', ?, 'sucia')",
            [$hotelId, $tipoId]
        );
        $this->habitacionId = Database::lastInsertId();

        [$this->usuarioId] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');

        $this->asignaciones = new AsignacionService();
        $this->asignaciones->asignarManual($this->habitacionId, $this->usuarioId, $this->fecha);

        $this->svc = new ChecklistService();
    }

    public function testIniciarCreaEjecucionYCambiaEstado(): void
    {
        $ejec = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);

        $this->assertSame('en_progreso', $ejec->estado);
        $hab = Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$this->habitacionId]);
        $this->assertSame(Habitacion::ESTADO_EN_PROGRESO, $hab['estado']);
    }

    public function testIniciarEsIdempotente(): void
    {
        $e1 = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        $e2 = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        $this->assertSame($e1->id, $e2->id);
    }

    public function testIniciarSinAsignacionLanza(): void
    {
        [$otro] = TestDatabase::crearUsuario('22222222-2', 'Bea', 'Trabajador');
        try {
            $this->svc->iniciarEjecucion($this->habitacionId, $otro, $this->fecha);
            $this->fail('Debía lanzar');
        } catch (ChecklistException $e) {
            $this->assertSame('HABITACION_NO_ASIGNADA', $e->codigo);
        }
    }

    public function testMarcarItemActualizaProgreso(): void
    {
        $ejec = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        $items = $this->svc->itemsDelTemplate($ejec->templateId);
        $primero = (int) $items[0]['id'];

        $progreso = $this->svc->marcarItem($ejec->id, $primero, true, $this->usuarioId);

        $this->assertSame(1, $progreso['marcados']);
        $this->assertSame(10, $progreso['total']);
        $this->assertSame(10, $progreso['porcentaje']);
    }

    public function testMarcarItemDeOtroUsuarioLanza(): void
    {
        $ejec = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        [$otro] = TestDatabase::crearUsuario('22222222-2', 'Bea', 'Trabajador');
        $items = $this->svc->itemsDelTemplate($ejec->templateId);

        try {
            $this->svc->marcarItem($ejec->id, (int) $items[0]['id'], true, $otro);
            $this->fail('Debía lanzar');
        } catch (ChecklistException $e) {
            $this->assertSame('EJECUCION_AJENA', $e->codigo);
        }
    }

    public function testCompletarRequiereTodosObligatorios(): void
    {
        $ejec = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);

        try {
            $this->svc->completar($ejec->id, $this->usuarioId);
            $this->fail('Debía lanzar');
        } catch (ChecklistException $e) {
            $this->assertSame('CHECKLIST_INCOMPLETO', $e->codigo);
        }
    }

    public function testCompletarExitoso(): void
    {
        $ejec = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        $items = $this->svc->itemsDelTemplate($ejec->templateId);

        foreach ($items as $item) {
            if ((int) $item['obligatorio'] === 1) {
                $this->svc->marcarItem($ejec->id, (int) $item['id'], true, $this->usuarioId);
            }
        }

        $this->svc->completar($ejec->id, $this->usuarioId);

        $ejecActualizada = Database::fetchOne('SELECT * FROM ejecuciones_checklist WHERE id = ?', [$ejec->id]);
        $this->assertSame('completada', $ejecActualizada['estado']);
        $this->assertNotNull($ejecActualizada['timestamp_fin']);

        $hab = Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$this->habitacionId]);
        $this->assertSame(Habitacion::ESTADO_COMPLETADA_PENDIENTE_AUDITORIA, $hab['estado']);
    }

    public function testPersistenciaTapATapReanuda(): void
    {
        $ejec = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        $items = $this->svc->itemsDelTemplate($ejec->templateId);
        $this->svc->marcarItem($ejec->id, (int) $items[0]['id'], true, $this->usuarioId);
        $this->svc->marcarItem($ejec->id, (int) $items[1]['id'], true, $this->usuarioId);

        $estado = $this->svc->estadoEjecucion($ejec->id);

        $marcadosEnResp = array_filter($estado['items'], fn($it) => (int) $it['marcado'] === 1);
        $this->assertCount(2, $marcadosEnResp);
        $this->assertSame(2, $estado['progreso']['marcados']);
    }

    public function testItemDesmarcadoAfectaProgreso(): void
    {
        $ejec = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        $items = $this->svc->itemsDelTemplate($ejec->templateId);
        $this->svc->marcarItem($ejec->id, (int) $items[0]['id'], true, $this->usuarioId);
        $p = $this->svc->marcarItem($ejec->id, (int) $items[0]['id'], false, $this->usuarioId);

        $this->assertSame(0, $p['marcados']);
    }
}
