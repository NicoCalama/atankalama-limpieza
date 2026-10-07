<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * KPIs del equipo corregidos en la auditoría de código (07/10/2026): productividad por
 * jornada trabajada y tasa de ítems desmarcados sobre lo que de verdad se inspeccionó.
 */
final class ReportesKpisEquipoTest extends TestCase
{
    private int $hotelId;
    private int $tipoId;
    private int $anaId;
    private int $bertaId;
    private int $evaId;
    private string $hoy;
    private string $ayer;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();
        $this->hotelId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $this->tipoId = (int) Database::fetchOne('SELECT id FROM tipos_habitacion LIMIT 1')['id'];
        [$this->anaId] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        [$this->bertaId] = TestDatabase::crearUsuario('22222222-2', 'Berta', 'Trabajador');
        [$this->evaId] = TestDatabase::crearUsuario('33333333-3', 'Eva', 'Supervisora');
        $this->hoy = date('Y-m-d');
        $this->ayer = date('Y-m-d', strtotime('-1 day'));
    }

    public function testProductividadDividePorLasJornadasQueCadaUnaTrabajo(): void
    {
        // Ana: 2 piezas ayer. Berta: 1 pieza hoy. 3 piezas en 2 jornadas = 1,5 hab/día.
        // Antes: 3 / (2 trabajadoras × 2 días del equipo) = 0,8.
        $this->terminar('101', $this->anaId, $this->ayer);
        $this->terminar('102', $this->anaId, $this->ayer);
        $this->terminar('103', $this->bertaId, $this->hoy);

        $kpi = (new ReportesService())->kpis($this->ayer, $this->hoy, 'ambos')['productividad'];
        $this->assertSame(1.5, $kpi['valor']);
    }

    public function testTasaDeDesmarcadosCuentaLoDesmarcadoYSoloInspeccionesHumanas(): void
    {
        $e1 = $this->terminar('101', $this->anaId, $this->hoy);
        $this->terminar('102', $this->anaId, $this->hoy);
        $obligatorios = (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM ejecuciones_items WHERE ejecucion_id = ? AND marcado = 1',
            [$e1]
        );
        $item = (int) Database::fetchColumn('SELECT item_id FROM ejecuciones_items WHERE ejecucion_id = ? LIMIT 1', [$e1]);

        $aud = new AuditoriaService();
        // 101: inspección humana con 1 ítem desmarcado. 102: cierre automático (nadie la miró).
        $aud->emitirVeredicto($this->hab('101'), $this->evaId, Auditoria::VEREDICTO_APROBADO_CON_OBSERVACION, 'Faltó el velador', [$item]);
        $aud->emitirVeredicto($this->hab('102'), $this->evaId, Auditoria::VEREDICTO_APROBADO_AUTOMATICO, 'Cierre de día');

        $kpi = (new ReportesService())->kpis($this->hoy, $this->hoy, 'ambos')['tasa_desmarcados'];
        // 1 de los ítems inspeccionados de la 101 (los que quedaron marcados + el desmarcado).
        $this->assertSame(round(1 / $obligatorios * 100, 1), $kpi['valor']);
        $this->assertSame("1 desmarcados de {$obligatorios}", $kpi['contexto']);
    }

    // --- Helpers ---------------------------------------------------------------------------

    private function hab(string $numero): int
    {
        $id = Database::fetchColumn('SELECT id FROM habitaciones WHERE numero = ?', [$numero]);
        if ($id === false) {
            Database::execute(
                "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, ?, ?, 'sucia')",
                [$this->hotelId, $numero, $this->tipoId]
            );
            return Database::lastInsertId();
        }
        return (int) $id;
    }

    /** Limpia la pieza completa y la fecha en $fecha (a media mañana, hora de Chile). */
    private function terminar(string $numero, int $usuarioId, string $fecha): int
    {
        $habId = $this->hab($numero);
        $asig = new AsignacionService();
        $chk = new ChecklistService();
        $asig->asignarManual($habId, $usuarioId, $fecha);
        $e = $chk->iniciarEjecucion($habId, $usuarioId, $fecha);
        foreach ($chk->itemsDelTemplate($e->templateId) as $it) {
            if ((int) $it['obligatorio'] === 1) {
                $chk->marcarItem($e->id, (int) $it['id'], true, $usuarioId);
            }
        }
        $chk->completar($e->id, $usuarioId);
        $inicio = gmdate('Y-m-d\TH:i:s.000\Z', strtotime("{$fecha} 10:00:00"));
        Database::execute(
            'UPDATE ejecuciones_checklist SET timestamp_inicio = ?, created_at = ? WHERE id = ?',
            [$inicio, $inicio, $e->id]
        );
        return $e->id;
    }
}
