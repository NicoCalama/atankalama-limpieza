<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Los reportes filtran por día LOCAL (Santiago) sobre timestamps guardados en UTC.
 *
 * El turno de tarde termina a las 22:00, hora a la que en UTC ya es el día siguiente:
 * antes de `Helpers\Fechas` esas limpiezas se acreditaban al día equivocado. Estos
 * casos clavan timestamps explícitos, así que valen igual a cualquier hora del día en
 * que se corra la suite.
 */
final class ReportesZonaHorariaTest extends TestCase
{
    /** 21:00 del 15/07/2026 en Santiago (invierno, UTC-4) = 01:00Z del 16/07. */
    private const NOCHE_UTC = '2026-07-16T01:00:00.000Z';
    private const NOCHE_FIN_UTC = '2026-07-16T01:40:00.000Z';
    private const DIA_LOCAL = '2026-07-15';
    private const DIA_SIGUIENTE_LOCAL = '2026-07-16';

    private ReportesService $rep;
    private int $ana;
    private int $habId;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();

        $hotelId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $tipoId  = (int) Database::fetchOne('SELECT id FROM tipos_habitacion LIMIT 1')['id'];
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, '101', ?, 'sucia')",
            [$hotelId, $tipoId]
        );
        $this->habId = Database::lastInsertId();
        [$this->ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        $this->rep = new ReportesService();
    }

    /** Limpieza completa de Ana, con sus timestamps clavados a las 21:00 locales. */
    private function limpiezaDeLaNoche(): void
    {
        $asig = new AsignacionService();
        $chk  = new ChecklistService();

        $asig->asignarManual($this->habId, $this->ana, self::DIA_LOCAL);
        $ejec = $chk->iniciarEjecucion($this->habId, $this->ana, self::DIA_LOCAL);
        foreach ($chk->itemsDelTemplate($ejec->templateId) as $it) {
            $chk->marcarItem($ejec->id, (int) $it['id'], true, $this->ana);
        }
        $chk->completar($ejec->id, $this->ana);

        // Los timestamps los pone la BD con la hora real; los movemos a la noche del 15/07.
        Database::execute(
            'UPDATE ejecuciones_checklist SET timestamp_inicio = ?, timestamp_fin = ? WHERE id = ?',
            [self::NOCHE_UTC, self::NOCHE_FIN_UTC, $ejec->id]
        );
    }

    public function testLaLimpiezaDeLas21CuentaParaElDiaLocalEnQueSeHizo(): void
    {
        $this->limpiezaDeLaNoche();

        $kpis = $this->rep->kpis(self::DIA_LOCAL, self::DIA_LOCAL, 'ambos');

        $this->assertNotNull(
            $kpis['tiempo_promedio']['valor'],
            'Una limpieza de las 21:00 del 15/07 debe aparecer en el reporte del 15/07.'
        );
        $this->assertSame(100.0, $kpis['creditos']['valor']);
    }

    public function testEsaMismaLimpiezaNoApareceEnElDiaSiguiente(): void
    {
        $this->limpiezaDeLaNoche();

        // El bug hacía justo esto: el trabajo de anoche engordaba el reporte de hoy.
        $kpis = $this->rep->kpis(self::DIA_SIGUIENTE_LOCAL, self::DIA_SIGUIENTE_LOCAL, 'ambos');

        $this->assertNull($kpis['tiempo_promedio']['valor'], 'El 16/07 no se limpió nada.');
        $this->assertSame('sin_datos', $kpis['creditos']['estado']);
    }

    public function testLaTrabajadoraApareceEnElListadoDelDiaCorrecto(): void
    {
        $this->limpiezaDeLaNoche();

        $delDia = $this->rep->trabajadoras(self::DIA_LOCAL, self::DIA_LOCAL, 'ambos');
        $delDiaSiguiente = $this->rep->trabajadoras(self::DIA_SIGUIENTE_LOCAL, self::DIA_SIGUIENTE_LOCAL, 'ambos');

        $this->assertCount(1, $delDia);
        $this->assertSame('Ana', $delDia[0]['nombre']);
        $this->assertSame([], $delDiaSiguiente);
    }

    public function testDosLimpiezasDelMismoDiaLocalNoCuentanComoDosDias(): void
    {
        // 18:00 (22:00Z del 15) y 21:00 (01:00Z del 16) son el MISMO día local. Contando
        // días UTC daban 2 y la productividad salía a la mitad.
        $this->limpiezaDeLaNoche();

        $chk = new ChecklistService();
        $asig = new AsignacionService();
        Database::execute("INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado)
                           SELECT hotel_id, '102', tipo_habitacion_id, 'sucia' FROM habitaciones WHERE id = ?", [$this->habId]);
        $hab2 = Database::lastInsertId();
        $asig->asignarManual($hab2, $this->ana, self::DIA_LOCAL);
        $e2 = $chk->iniciarEjecucion($hab2, $this->ana, self::DIA_LOCAL);
        foreach ($chk->itemsDelTemplate($e2->templateId) as $it) {
            $chk->marcarItem($e2->id, (int) $it['id'], true, $this->ana);
        }
        $chk->completar($e2->id, $this->ana);
        Database::execute(
            'UPDATE ejecuciones_checklist SET timestamp_inicio = ?, timestamp_fin = ? WHERE id = ?',
            ['2026-07-15T22:00:00.000Z', '2026-07-15T22:40:00.000Z', $e2->id]
        );

        $prod = $this->rep->kpis(self::DIA_LOCAL, self::DIA_LOCAL, 'ambos')['productividad'];

        $this->assertStringContainsString('1 día(s)', $prod['contexto'], 'Las dos limpiezas son del mismo día local.');
        $this->assertSame(2.0, $prod['valor'], '2 habitaciones / (1 trabajadora × 1 día)');
    }
}
