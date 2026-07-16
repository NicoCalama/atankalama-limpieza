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
 * Verifica el reparto de créditos por persona tras el rework (docs/creditos-rework.md):
 * Ana limpia 9 obligatorios, el auditor rechaza 2, Berta re-limpia esos 2 y se aprueba.
 * Créditos esperados: Ana 7 de 9 intentos (castiga los 2 fallidos), Berta 2 de 2.
 */
final class ReportesServiceTest extends TestCase
{
    private ReportesService $rep;
    private int $ana;
    private int $berta;
    private int $totalOblig;
    /** @var list<int> ids de los 2 ítems obligatorios que el auditor rechazó */
    private array $falla;

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
        $habId = Database::lastInsertId();

        [$this->ana]   = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        [$this->berta] = TestDatabase::crearUsuario('22222222-2', 'Berta', 'Trabajador');
        [$sofia]       = TestDatabase::crearUsuario('33333333-3', 'Sofia', 'Supervisora');

        $fecha = '2026-04-14';
        $asig  = new AsignacionService();
        $chk   = new ChecklistService();
        $aud   = new AuditoriaService();

        // 1) Ana limpia todos los obligatorios y completa.
        $asig->asignarManual($habId, $this->ana, $fecha);
        $e1 = $chk->iniciarEjecucion($habId, $this->ana, $fecha);
        $oblig = array_values(array_filter(
            $chk->itemsDelTemplate($e1->templateId),
            static fn(array $i) => (int) $i['obligatorio'] === 1
        ));
        $this->totalOblig = count($oblig);
        foreach ($oblig as $it) {
            $chk->marcarItem($e1->id, (int) $it['id'], true, $this->ana);
        }
        $chk->completar($e1->id, $this->ana);

        // 2) El auditor rechaza 2 ítems (fallidos de Ana).
        $falla = [(int) $oblig[0]['id'], (int) $oblig[1]['id']];
        $this->falla = $falla;
        $aud->emitirVeredicto($habId, $sofia, Auditoria::VEREDICTO_RECHAZADO, 'Rehacer estos dos ítems.', $falla);

        // 3) Berta re-limpia (hereda los 7 buenos, completa los 2 fallidos) y se aprueba.
        $asig->reasignar($habId, $this->berta, $fecha, 're-limpieza');
        $e2 = $chk->iniciarEjecucion($habId, $this->berta, $fecha);
        foreach ($falla as $itemId) {
            $chk->marcarItem($e2->id, $itemId, true, $this->berta);
        }
        $chk->completar($e2->id, $this->berta);
        $aud->emitirVeredicto($habId, $sofia, Auditoria::VEREDICTO_APROBADO);

        $this->rep = new ReportesService();
    }

    public function testKpiCreditosSeRepartePorPersonaYCastigaElError(): void
    {
        // Fecha LOCAL: es lo que la supervisora elige en el filtro y lo que espera la API
        // (antes acá iba gmdate() para compensar que los KPIs filtraban por día UTC).
        $hoy = date('Y-m-d');
        $creditosAna = $this->totalOblig - 2; // 7

        // Ana: 7 créditos de 9 intentos (los 2 desmarcados cuentan en su denominador).
        $kAna = $this->rep->kpis($hoy, $hoy, 'ambos', $this->ana)['creditos'];
        $this->assertSame("{$creditosAna} / {$this->totalOblig} créditos", $kAna['contexto']);
        $this->assertSame(round($creditosAna / $this->totalOblig * 100, 1), $kAna['valor']);

        // Berta: 2 créditos de 2 intentos = 100%.
        $kBerta = $this->rep->kpis($hoy, $hoy, 'ambos', $this->berta)['creditos'];
        $this->assertSame('2 / 2 créditos', $kBerta['contexto']);
        $this->assertSame(100.0, $kBerta['valor']);

        // Global: 9 créditos de 11 intentos (no hay doble conteo de los heredados).
        $kGlobal = $this->rep->kpis($hoy, $hoy, 'ambos', null)['creditos'];
        $this->assertSame(($creditosAna + 2) . ' / ' . ($this->totalOblig + 2) . ' créditos', $kGlobal['contexto']);
    }

    /**
     * Con peso configurable por ítem, los créditos SUMAN ic.creditos en vez de contar ítems.
     * Subimos a 5 el peso de los 2 ítems que Berta re-limpió: se re-valúan en vivo (la query
     * lee items_checklist al momento, no un snapshot) → Berta acredita 10 y el denominador de
     * Ana se infla por lo que arruinó (7 / 17).
     */
    public function testCreditosPonderadosPorPesoDeItem(): void
    {
        $ph = implode(',', array_fill(0, count($this->falla), '?'));
        Database::execute("UPDATE items_checklist SET creditos = 5 WHERE id IN ({$ph})", $this->falla);

        $hoy   = date('Y-m-d');
        $kept  = $this->totalOblig - 2;   // 7 ítems que Ana conservó (peso 1)
        $fall  = 2 * 5;                    // 2 ítems fallidos * peso 5 = 10

        // Ana: 7 créditos (peso 1) / (7 + 10) intentos.
        $kAna = $this->rep->kpis($hoy, $hoy, 'ambos', $this->ana)['creditos'];
        $this->assertSame("{$kept} / " . ($kept + $fall) . ' créditos', $kAna['contexto']);
        $this->assertSame(round($kept / ($kept + $fall) * 100, 1), $kAna['valor']);

        // Berta: 2 ítems fallidos * peso 5 = 10 créditos / 10 = 100%.
        $kBerta = $this->rep->kpis($hoy, $hoy, 'ambos', $this->berta)['creditos'];
        $this->assertSame("{$fall} / {$fall} créditos", $kBerta['contexto']);
        $this->assertSame(100.0, $kBerta['valor']);

        // Resumen mensual: Ana 7 (máx 17), Berta 10 (máx 10).
        $filas = $this->rep->resumenMensual((int) gmdate('Y'), (int) gmdate('n'), 'ambos');
        $porNombre = [];
        foreach ($filas as $f) {
            $porNombre[$f['nombre']] = $f;
        }
        $this->assertSame($kept, (int) $porNombre['Ana']['creditos']);
        $this->assertSame($kept + $fall, (int) $porNombre['Ana']['creditos_maximos']);
        $this->assertSame($fall, (int) $porNombre['Berta']['creditos']);
        $this->assertSame($fall, (int) $porNombre['Berta']['creditos_maximos']);
    }

    public function testResumenMensualRepartido(): void
    {
        $filas = $this->rep->resumenMensual((int) gmdate('Y'), (int) gmdate('n'), 'ambos');
        $porNombre = [];
        foreach ($filas as $f) {
            $porNombre[$f['nombre']] = $f;
        }

        $this->assertArrayHasKey('Ana', $porNombre);
        $this->assertArrayHasKey('Berta', $porNombre);
        // El auditor no marcó ítems: no aparece en el reparto de créditos.
        $this->assertArrayNotHasKey('Sofia', $porNombre);

        // Ana: 7 créditos, máximo 9 (7 + 2 fallidos), 1 habitación.
        $this->assertSame($this->totalOblig - 2, (int) $porNombre['Ana']['creditos']);
        $this->assertSame($this->totalOblig, (int) $porNombre['Ana']['creditos_maximos']);
        $this->assertSame(1, (int) $porNombre['Ana']['habitaciones']);

        // Berta: 2 créditos, máximo 2, 1 habitación.
        $this->assertSame(2, (int) $porNombre['Berta']['creditos']);
        $this->assertSame(2, (int) $porNombre['Berta']['creditos_maximos']);
        $this->assertSame(1, (int) $porNombre['Berta']['habitaciones']);
    }
}
