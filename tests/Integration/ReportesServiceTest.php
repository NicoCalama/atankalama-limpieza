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
 * Créditos: Ana 7 (los heredados que marcó bien), Berta 2. Desde la v6.15 la tarjeta de créditos,
 * el resumen mensual y la ficha dan el MISMO número; el error de Ana se ve en su eficiencia
 * (créditos ÷ lo asignado) y en que la pieza le queda rechazada, ya no en un «% sobre intentos».
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

        // Hoy: las limpiezas quedan con la hora real, y las Asignadas (asignaciones.fecha) tienen que caer
        // en el mismo período para que la eficiencia y el resumen mensual las vean.
        $fecha = date('Y-m-d');
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

    public function testKpiCreditosSeRepartePorPersonaSinDobleConteo(): void
    {
        // Fecha LOCAL: es lo que la supervisora elige en el filtro y lo que espera la API
        // (antes acá iba gmdate() para compensar que los KPIs filtraban por día UTC).
        $hoy = date('Y-m-d');
        $creditosAna = $this->totalOblig - 2; // 7

        // Ana: los 7 ítems heredados que marcó bien (inspeccionados en la re-limpieza aprobada).
        $kAna = $this->rep->kpis($hoy, $hoy, 'ambos', $this->ana);
        $this->assertSame($creditosAna, $kAna['creditos']['valor']);
        $this->assertSame("{$creditosAna} inspeccionados · 0 sin inspección", $kAna['creditos']['contexto']);
        // Su error se ve en la eficiencia (7 de 9 asignados) y en la pieza rechazada.
        $this->assertSame(round($creditosAna / $this->totalOblig * 100, 1), $kAna['eficiencia']['valor']);
        $this->assertSame(100.0, $kAna['tasa_rechazo']['valor']);

        // Berta: los 2 que rehízo.
        $kBerta = $this->rep->kpis($hoy, $hoy, 'ambos', $this->berta);
        $this->assertSame(2, $kBerta['creditos']['valor']);

        // Global: 9 créditos (no hay doble conteo de los heredados), igual que la suma de la ficha.
        $kGlobal = $this->rep->kpis($hoy, $hoy, 'ambos', null)['creditos'];
        $this->assertSame($creditosAna + 2, $kGlobal['valor']);
        $ficha = array_sum(array_column($this->rep->fichaKpis($hoy, $hoy, 'ambos')['trabajadores'], 'creditos'));
        $this->assertSame($ficha, $kGlobal['valor']);
    }

    /**
     * Con peso configurable por ítem, los créditos SUMAN ic.creditos en vez de contar ítems.
     * El fixture sube a 5 el peso de los 2 ítems que Berta re-limpió con un UPDATE DIRECTO a la
     * tabla (no por el editor) solo para ejercitar la suma ponderada: Berta acredita 10, Ana 7, y
     * la pieza asignada a cada una vale 17.
     *
     * OJO: por la app esto ya no puede pasar. Desde el versionado copy-on-write, editar un
     * checklist crea ítems nuevos y deja los viejos intactos, así que los reportes de días
     * cerrados siguen leyendo los pesos que estaban vigentes cuando se limpió
     * (ver ChecklistServiceTest::testEditarTemplateNoMueveLosCreditosYaAcreditados).
     */
    public function testCreditosPonderadosPorPesoDeItem(): void
    {
        $ph = implode(',', array_fill(0, count($this->falla), '?'));
        Database::execute("UPDATE items_checklist SET creditos = 5 WHERE id IN ({$ph})", $this->falla);

        $hoy   = date('Y-m-d');
        $kept  = $this->totalOblig - 2;   // 7 ítems que Ana conservó (peso 1)
        $fall  = 2 * 5;                    // 2 ítems fallidos * peso 5 = 10

        // Ana: 7 créditos (peso 1); eficiencia 7 de los 17 que vale la pieza.
        $kAna = $this->rep->kpis($hoy, $hoy, 'ambos', $this->ana);
        $this->assertSame($kept, $kAna['creditos']['valor']);
        $this->assertSame("{$kept} de " . ($kept + $fall) . ' créditos asignados', $kAna['eficiencia']['contexto']);

        // Berta: 2 ítems * peso 5 = 10 créditos.
        $kBerta = $this->rep->kpis($hoy, $hoy, 'ambos', $this->berta);
        $this->assertSame($fall, $kBerta['creditos']['valor']);

        // Resumen mensual: los mismos números.
        $filas = $this->rep->resumenMensual((int) date('Y'), (int) date('n'), 'ambos');
        $porNombre = [];
        foreach ($filas as $f) {
            $porNombre[$f['nombre']] = $f;
        }
        $this->assertSame($kept, $porNombre['Ana']['creditos']);
        $this->assertSame($kept + $fall, $porNombre['Ana']['creditos_asignados']);
        $this->assertSame($fall, $porNombre['Berta']['creditos']);
        $this->assertSame($kept + $fall, $porNombre['Berta']['creditos_asignados']);
    }

    public function testResumenMensualRepartido(): void
    {
        $filas = $this->rep->resumenMensual((int) date('Y'), (int) date('n'), 'ambos');
        $porNombre = [];
        foreach ($filas as $f) {
            $porNombre[$f['nombre']] = $f;
        }

        $this->assertArrayHasKey('Ana', $porNombre);
        $this->assertArrayHasKey('Berta', $porNombre);
        // El auditor no marcó ítems: no aparece en el reparto de créditos.
        $this->assertArrayNotHasKey('Sofia', $porNombre);

        // Ana: 7 créditos; la pieza le quedó RECHAZADA (la rehízo otra persona): 0 limpias, 1 rechazada.
        // Antes el resumen le contaba la pieza como limpiada (docs/kpis-sueldos.md: «tras un rechazo la
        // pieza sigue contando como rechazada para esa persona, la rehaga quien la rehaga»).
        $this->assertSame($this->totalOblig - 2, $porNombre['Ana']['creditos']);
        $this->assertSame(0, $porNombre['Ana']['habitaciones']);
        $this->assertSame(1, $porNombre['Ana']['rechazadas']);

        // Berta: 2 créditos, 1 habitación.
        $this->assertSame(2, $porNombre['Berta']['creditos']);
        $this->assertSame(1, $porNombre['Berta']['habitaciones']);
        $this->assertSame(0, $porNombre['Berta']['rechazadas']);
    }
}
