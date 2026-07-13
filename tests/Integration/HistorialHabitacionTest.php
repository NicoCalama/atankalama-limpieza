<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Historial de limpiezas por habitación (ChecklistService::historialDeHabitacion),
 * detrás de GET /api/habitaciones/{id}/historial (permiso habitaciones.ver_historial).
 */
final class HistorialHabitacionTest extends TestCase
{
    private ChecklistService $checklist;
    private int $habitacionId;
    private int $anaId;
    private int $auditorId;
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
        [$this->auditorId] = TestDatabase::crearUsuario('22222222-2', 'Eva', 'Supervisora');

        $this->checklist = new ChecklistService();
    }

    private function limpiarCompleto(int $trabajadorId): int
    {
        $ejec = $this->checklist->iniciarEjecucion($this->habitacionId, $trabajadorId, $this->fecha);
        foreach ($this->checklist->itemsDelTemplate($ejec->templateId) as $item) {
            if ((int) $item['obligatorio'] === 1) {
                $this->checklist->marcarItem($ejec->id, (int) $item['id'], true, $trabajadorId);
            }
        }
        $this->checklist->completar($ejec->id, $trabajadorId);
        return $ejec->id;
    }

    public function testHistorialVacioParaHabitacionSinLimpiezas(): void
    {
        $this->assertSame([], $this->checklist->historialDeHabitacion($this->habitacionId));
    }

    public function testHistorialListaEjecucionesConVeredictoYAuditor(): void
    {
        $asig = new AsignacionService();
        $auditorias = new AuditoriaService();

        // 1ª limpieza de Ana → rechazada por Eva
        $asig->asignarManual($this->habitacionId, $this->anaId, $this->fecha);
        $ejec1 = $this->limpiarCompleto($this->anaId);
        $itemFallido = (int) Database::fetchColumn(
            'SELECT item_id FROM ejecuciones_items WHERE ejecucion_id = ? LIMIT 1',
            [$ejec1]
        );
        $auditorias->emitirVeredicto(
            $this->habitacionId,
            $this->auditorId,
            Auditoria::VEREDICTO_RECHAZADO,
            'Faltó el baño.',
            [$itemFallido]
        );

        // Re-limpieza de Berta, completada y aún sin auditar
        [$bertaId] = TestDatabase::crearUsuario('33333333-3', 'Berta', 'Trabajador');
        $asig->reasignar($this->habitacionId, $bertaId, $this->fecha, 're-limpieza');
        $ejec2 = $this->limpiarCompleto($bertaId);

        $historial = $this->checklist->historialDeHabitacion($this->habitacionId);
        $this->assertCount(2, $historial);

        // Más reciente primero: la re-limpieza de Berta, sin veredicto todavía
        $this->assertSame($ejec2, (int) $historial[0]['id']);
        $this->assertSame('Berta', $historial[0]['trabajador_nombre']);
        $this->assertNull($historial[0]['veredicto']);
        $this->assertNotEmpty($historial[0]['timestamp_inicio']);

        // La vieja de Ana con su rechazo y su auditora
        $this->assertSame($ejec1, (int) $historial[1]['id']);
        $this->assertSame('Ana', $historial[1]['trabajador_nombre']);
        $this->assertSame('rechazado', $historial[1]['veredicto']);
        $this->assertSame('Eva', $historial[1]['auditor_nombre']);
        $this->assertSame('Faltó el baño.', $historial[1]['auditoria_comentario']);
    }

    public function testHistorialRespetaElLimite(): void
    {
        $asig = new AsignacionService();
        // 3 limpiezas de la misma pieza (asignar de nuevo la re-abre: terminal → sucia)
        for ($i = 0; $i < 3; $i++) {
            $asig->asignarManual($this->habitacionId, $this->anaId, $this->fecha);
            Database::execute("UPDATE habitaciones SET estado = 'sucia' WHERE id = ?", [$this->habitacionId]);
            $this->limpiarCompleto($this->anaId);
        }

        $this->assertCount(3, $this->checklist->historialDeHabitacion($this->habitacionId));
        $this->assertCount(2, $this->checklist->historialDeHabitacion($this->habitacionId, 2));
    }
}
