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
 * «Aprobación a la primera» con el 4° veredicto aprobado_automatico (cierre de día 23:55).
 * Regla del 15/09 (docs/kpis-sueldos.md): aprobada para el TRABAJADOR, no auditada para la
 * supervisora → por trabajador cuenta como aprobada; a nivel de sección queda fuera.
 */
final class ReportesAprobacionPrimeraTest extends TestCase
{
    private int $ana;
    private string $hoy;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();
        $hotelId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $tipoId  = (int) Database::fetchOne('SELECT id FROM tipos_habitacion LIMIT 1')['id'];

        [$this->ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        [$sofia]     = TestDatabase::crearUsuario('33333333-3', 'Sofia', 'Supervisora');
        [$sistema]   = TestDatabase::crearUsuario('44444444-4', 'Sistema', 'Admin');
        $this->hoy   = date('Y-m-d');

        $asig = new AsignacionService();
        $chk  = new ChecklistService();
        $aud  = new AuditoriaService();

        $limpiar = function (string $numero) use ($hotelId, $tipoId, $asig, $chk): array {
            Database::execute(
                "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, ?, ?, 'sucia')",
                [$hotelId, $numero, $tipoId]
            );
            $habId = Database::lastInsertId();
            $asig->asignarManual($habId, $this->ana, $this->hoy);
            $ejec = $chk->iniciarEjecucion($habId, $this->ana, $this->hoy);
            $items = $chk->itemsDelTemplate($ejec->templateId);
            foreach ($items as $it) {
                $chk->marcarItem($ejec->id, (int) $it['id'], true, $this->ana);
            }
            $chk->completar($ejec->id, $this->ana);
            return [$habId, (int) $items[0]['id']];
        };

        // 101: nadie alcanzó a inspeccionarla → la aprueba el cierre automático de las 23:55.
        [$hab101] = $limpiar('101');
        $aud->emitirVeredicto($hab101, $sistema, Auditoria::VEREDICTO_APROBADO_AUTOMATICO, 'Cierre de día');

        // 102: inspección real, rechazada.
        [$hab102, $item102] = $limpiar('102');
        $aud->emitirVeredicto($hab102, $sofia, Auditoria::VEREDICTO_RECHAZADO, 'Rehacer el baño completo.', [$item102]);
    }

    public function testDeLaSeccionExcluyeLasAprobadasAutomaticas(): void
    {
        $kpi = (new ReportesService())->kpis($this->hoy, $this->hoy, 'ambos')['aprobacion_primera'];
        // Solo cuenta la inspección real (102, rechazada): 0 de 1.
        $this->assertSame(0.0, $kpi['valor']);
        $this->assertStringContainsString('0 de 1', $kpi['contexto']);
    }

    public function testDelTrabajadorCuentaLaAprobadaAutomaticaComoAprobada(): void
    {
        $kpi = (new ReportesService())->kpis($this->hoy, $this->hoy, 'ambos', $this->ana)['aprobacion_primera'];
        // 101 (automática) cuenta como aprobada para Ana; 102 rechazada: 1 de 2.
        $this->assertSame(50.0, $kpi['valor']);
        $this->assertStringContainsString('1 de 2', $kpi['contexto']);
    }
}
