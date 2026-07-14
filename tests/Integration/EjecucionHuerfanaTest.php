<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\ChecklistException;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Blindaje de la ejecución HUÉRFANA: cuando una habitación se desasigna (o reasigna),
 * su asignación pasa a activa=0 y la ejecución en curso del trabajador queda huérfana.
 * Ese trabajador NO debe poder seguir marcando ni completar (pisaría el estado que
 * dejó el desasignar/reasignar). Ver ChecklistService::exigirAsignacionActiva.
 */
final class EjecucionHuerfanaTest extends TestCase
{
    private ChecklistService $checklist;
    private AsignacionService $asig;
    private int $habitacionId;
    private int $anaId;
    private int $ejecId;
    private string $fecha = '2026-04-14';

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();

        $hotelId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $tipoId = (int) Database::fetchOne('SELECT id FROM tipos_habitacion LIMIT 1')['id'];
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, '101', ?, 'sucia')",
            [$hotelId, $tipoId]
        );
        $this->habitacionId = Database::lastInsertId();

        [$this->anaId] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');

        $this->checklist = new ChecklistService();
        $this->asig = new AsignacionService();

        $this->asig->asignarManual($this->habitacionId, $this->anaId, $this->fecha);
        $ejec = $this->checklist->iniciarEjecucion($this->habitacionId, $this->anaId, $this->fecha);
        // Marca todos los obligatorios para que 'completar' solo pueda fallar por el guard.
        foreach ($this->checklist->itemsDelTemplate($ejec->templateId) as $item) {
            if ((int) $item['obligatorio'] === 1) {
                $this->checklist->marcarItem($ejec->id, (int) $item['id'], true, $this->anaId);
            }
        }
        $this->ejecId = $ejec->id;
    }

    public function testTrasDesasignarNoSePuedeCompletarLaEjecucionHuerfana(): void
    {
        $this->asig->desasignar($this->habitacionId, $this->fecha);

        try {
            $this->checklist->completar($this->ejecId, $this->anaId);
            $this->fail('Debía lanzar ASIGNACION_INACTIVA');
        } catch (ChecklistException $e) {
            $this->assertSame('ASIGNACION_INACTIVA', $e->codigo);
            $this->assertSame(409, $e->httpStatus);
        }

        // La habitación sigue 'sucia' (no la pisó a completada_pendiente_auditoria)
        $estado = (string) Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$this->habitacionId])['estado'];
        $this->assertSame('sucia', $estado);
    }

    public function testTrasDesasignarNoSePuedeMarcarItem(): void
    {
        $item = Database::fetchOne(
            'SELECT ic.id FROM items_checklist ic
               JOIN ejecuciones_checklist ec ON ec.template_id = ic.template_id
              WHERE ec.id = ? AND ic.activo = 1 LIMIT 1',
            [$this->ejecId]
        );
        $this->asig->desasignar($this->habitacionId, $this->fecha);

        try {
            $this->checklist->marcarItem($this->ejecId, (int) $item['id'], false, $this->anaId);
            $this->fail('Debía lanzar ASIGNACION_INACTIVA');
        } catch (ChecklistException $e) {
            $this->assertSame('ASIGNACION_INACTIVA', $e->codigo);
        }
    }

    public function testTrasReasignarElTrabajadorViejoNoPuedeCompletar(): void
    {
        [$bertaId] = TestDatabase::crearUsuario('22222222-2', 'Berta', 'Trabajador');
        // Reasignar desactiva la asignación de Ana → su ejecución queda huérfana.
        $this->asig->reasignar($this->habitacionId, $bertaId, $this->fecha, 're-limpieza');

        try {
            $this->checklist->completar($this->ejecId, $this->anaId);
            $this->fail('Debía lanzar ASIGNACION_INACTIVA');
        } catch (ChecklistException $e) {
            $this->assertSame('ASIGNACION_INACTIVA', $e->codigo);
        }
    }

    public function testCompletarNormalSigueFuncionando(): void
    {
        // Sin desasignar: el flujo normal completa sin problemas (no regresión).
        $this->checklist->completar($this->ejecId, $this->anaId);
        $estado = (string) Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$this->habitacionId])['estado'];
        $this->assertSame('completada_pendiente_auditoria', $estado);
    }
}
