<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Models\EjecucionChecklist;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\ChecklistException;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Checklist adaptativo al cambio de sábanas (v7.7, decisión de Nicolás del 15/10/2026).
 *
 * Al empezar la limpieza se decide si lleva cambio de sábanas (huésped que sigue cada N noches,
 * huésped que se va hoy o turnover; ante la duda, sí). Si no lleva, el ítem «Sábanas» del checklist
 * no se muestra, no se exige para terminar, no se puede marcar ni desmarcar en la inspección, y la
 * pieza vale sus créditos sin él.
 *
 * Piezas del escenario (N = 4, ocupación leída hoy):
 *   201  sigue, lleva 3 noches → NO lleva sábanas
 *   202  se va hoy            → lleva
 *   203  sin datos de Cloudbeds → lleva (ante la duda)
 */
final class ChecklistSabanasAdaptativoTest extends TestCase
{
    private ChecklistService $chk;
    private AsignacionService $asig;
    private int $ana;
    private int $berta;
    private int $sofia;
    private string $hoy;
    /** @var array<int|string, int> número → id (PHP guarda «201» como clave entera) */
    private array $hab = [];

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();

        $hotelId = (int) Database::fetchColumn("SELECT id FROM hoteles WHERE codigo = '1_sur'");
        $tipoId  = (int) Database::fetchColumn('SELECT id FROM tipos_habitacion LIMIT 1');
        $this->hoy = date('Y-m-d');
        $hace = fn(int $d): string => date('Y-m-d', strtotime("{$this->hoy} -{$d} days"));

        foreach ([
            '201' => ['stayover', $hace(3)],
            '202' => ['check-out', $hace(2)],
            '203' => [null, null],
        ] as $numero => [$frontdesk, $llegada]) {
            Database::execute(
                "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado, cb_frontdesk_status, cb_arrival_date, cb_ocupacion_sync_at)
                 VALUES (?, ?, ?, 'sucia', ?, ?, " . ($frontdesk === null ? 'NULL' : "strftime('%Y-%m-%dT%H:%M:%fZ', 'now')") . ')',
                [$hotelId, (string) $numero, $tipoId, $frontdesk, $llegada]
            );
            $this->hab[(string) $numero] = Database::lastInsertId();
        }

        [$this->ana]   = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        [$this->berta] = TestDatabase::crearUsuario('22222222-2', 'Berta', 'Trabajador');
        [$this->sofia] = TestDatabase::crearUsuario('33333333-3', 'Sofia', 'Supervisora');

        $this->asig = new AsignacionService();
        $this->chk  = new ChecklistService();
    }

    public function testSigueSinTocarOcultaElItemDeSabanasYSeTerminaSinEl(): void
    {
        $e = $this->empezar('201', $this->ana);
        $this->assertFalse($e->conCambioSabanas);

        $estado = $this->chk->estadoEjecucion($e->id);
        $this->assertFalse($estado['ejecucion']['con_cambio_sabanas']);
        $this->assertCount(9, $estado['items'], 'los 10 del checklist menos el de sábanas');
        $this->assertSame([], array_filter($estado['items'], static fn(array $i) => (int) $i['es_cambio_sabanas'] === 1));
        $this->assertSame(8, $estado['progreso']['obligatorios_total'], '9 obligatorios menos el de sábanas');

        $this->marcarVisibles($e, $this->ana);
        $this->chk->completar($e->id, $this->ana);
        $this->assertSame('completada', Database::fetchColumn('SELECT estado FROM ejecuciones_checklist WHERE id = ?', [$e->id]));
    }

    public function testSeVaHoyMuestraElItemYLoExige(): void
    {
        $e = $this->empezar('202', $this->ana);
        $this->assertTrue($e->conCambioSabanas);

        $estado = $this->chk->estadoEjecucion($e->id);
        $this->assertCount(10, $estado['items']);
        $this->assertSame(9, $estado['progreso']['obligatorios_total']);

        // Todo marcado menos el de sábanas: no se puede terminar.
        foreach ($estado['items'] as $it) {
            if ((int) $it['obligatorio'] === 1 && (int) $it['es_cambio_sabanas'] === 0) {
                $this->chk->marcarItem($e->id, (int) $it['id'], true, $this->ana);
            }
        }
        try {
            $this->chk->completar($e->id, $this->ana);
            $this->fail('Debía lanzar CHECKLIST_INCOMPLETO');
        } catch (ChecklistException $ex) {
            $this->assertSame('CHECKLIST_INCOMPLETO', $ex->codigo);
        }
    }

    public function testSinDatosDeCloudbedsLlevaSabanas(): void
    {
        $this->assertTrue($this->empezar('203', $this->ana)->conCambioSabanas);
    }

    public function testElItemOcultoNoSePuedeMarcar(): void
    {
        $e = $this->empezar('201', $this->ana);
        try {
            $this->chk->marcarItem($e->id, $this->itemSabanas($e), true, $this->ana);
            $this->fail('Debía lanzar ITEM_NO_APLICA');
        } catch (ChecklistException $ex) {
            $this->assertSame('ITEM_NO_APLICA', $ex->codigo);
        }
        $this->assertNull(Database::fetchOne('SELECT id FROM ejecuciones_items WHERE ejecucion_id = ?', [$e->id]));
    }

    /** La ocupación puede cambiar a mitad de la limpieza: el checklist queda como empezó. */
    public function testLaDecisionQuedaFijaAunqueCambieLaOcupacion(): void
    {
        $e = $this->empezar('201', $this->ana);
        Database::execute("UPDATE habitaciones SET cb_frontdesk_status = 'check-out' WHERE id = ?", [$this->hab['201']]);

        $this->assertCount(9, $this->chk->estadoEjecucion($e->id)['items']);
        // Reanudar devuelve la misma ejecución, con la decisión original.
        $this->assertFalse($this->empezar('201', $this->ana, false)->conCambioSabanas);
    }

    public function testLaInspeccionNoAceptaDesmarcarElItemOculto(): void
    {
        $e = $this->empezar('201', $this->ana);
        $this->marcarVisibles($e, $this->ana);
        $this->chk->completar($e->id, $this->ana);

        try {
            (new AuditoriaService())->emitirVeredicto(
                $this->hab['201'],
                $this->sofia,
                Auditoria::VEREDICTO_APROBADO_CON_OBSERVACION,
                null,
                [$this->itemSabanas($e)]
            );
            $this->fail('Debía lanzar ITEMS_DESMARCADOS_INVALIDO');
        } catch (ChecklistException $ex) {
            $this->assertSame('ITEMS_DESMARCADOS_INVALIDO', $ex->codigo);
        }
        $this->assertNull(Database::fetchOne('SELECT id FROM auditorias WHERE ejecucion_id = ?', [$e->id]));
    }

    /**
     * Re-limpieza el mismo día: si la nueva limpieza ya no lleva sábanas, el ítem de sábanas que
     * marcó la primera trabajadora no se hereda (quedaría oculto y daría créditos).
     */
    public function testLaRelimpiezaSinSabanasNoHeredaElItemDeSabanas(): void
    {
        $e = $this->empezar('202', $this->ana);
        $this->marcarVisibles($e, $this->ana);
        $this->chk->completar($e->id, $this->ana);
        $otro = $this->primerObligatorioNoSabanas($e);
        (new AuditoriaService())->emitirVeredicto(
            $this->hab['202'],
            $this->sofia,
            Auditoria::VEREDICTO_RECHAZADO,
            'Rehacer.',
            [$otro]
        );

        // Ahora Cloudbeds la da como huésped que sigue (p. ej. extendió la estadía), sin tocar sábanas.
        Database::execute(
            "UPDATE habitaciones SET cb_frontdesk_status = 'stayover', cb_arrival_date = ? WHERE id = ?",
            [date('Y-m-d', strtotime("{$this->hoy} -2 days")), $this->hab['202']]
        );
        $this->asig->reasignar($this->hab['202'], $this->berta, $this->hoy, 're-limpieza');
        $e2 = $this->chk->iniciarEjecucion($this->hab['202'], $this->berta, $this->hoy);
        $this->assertFalse($e2->conCambioSabanas);

        $heredados = array_map(
            static fn(array $f) => (int) $f['item_id'],
            Database::fetchAll('SELECT item_id FROM ejecuciones_items WHERE ejecucion_id = ?', [$e2->id])
        );
        $this->assertNotContains($this->itemSabanas($e2), $heredados);
        $this->assertNotContains($otro, $heredados, 'el desmarcado tampoco, como siempre');
        $this->assertCount(7, $heredados, '9 obligatorios − sábanas − el desmarcado');
    }

    /** Créditos: la limpieza sin sábanas vale solo lo que se hizo, y el esperado también baja. */
    public function testReportesCuentanLaPiezaSinElItemDeSabanas(): void
    {
        $e = $this->empezar('201', $this->ana);
        $this->marcarVisibles($e, $this->ana);
        $this->chk->completar($e->id, $this->ana);

        $completo = 0;
        $sinSabanas = 0;
        foreach ($this->chk->itemsDelTemplate($e->templateId) as $it) {
            if ((int) $it['obligatorio'] === 1) {
                $completo += (int) $it['creditos'];
                $sinSabanas += (int) $it['es_cambio_sabanas'] === 1 ? 0 : (int) $it['creditos'];
            }
        }
        $this->assertLessThan($completo, $sinSabanas);

        $fila = null;
        foreach ((new ReportesService())->fichaKpis($this->hoy, $this->hoy, 'ambos')['trabajadores'] as $t) {
            if ($t['nombre'] === 'Ana') {
                $fila = $t;
            }
        }
        $this->assertNotNull($fila);
        $this->assertSame($sinSabanas, $fila['creditos']);
        $this->assertSame($sinSabanas, $fila['esperado_creditos']);
        $this->assertSame(100.0, $fila['eficiencia_pct'], 'una limpieza perfecta sin sábanas no queda incompleta');
    }

    private function empezar(string $numero, int $usuario, bool $asignar = true): EjecucionChecklist
    {
        if ($asignar) {
            $this->asig->asignarManual($this->hab[$numero], $usuario, $this->hoy);
        }
        return $this->chk->iniciarEjecucion($this->hab[$numero], $usuario, $this->hoy);
    }

    /** Marca los obligatorios que la pantalla muestra (sin el de sábanas si está oculto). */
    private function marcarVisibles(EjecucionChecklist $e, int $usuario): void
    {
        foreach ($this->chk->estadoEjecucion($e->id)['items'] as $it) {
            if ((int) $it['obligatorio'] === 1) {
                $this->chk->marcarItem($e->id, (int) $it['id'], true, $usuario);
            }
        }
    }

    private function itemSabanas(EjecucionChecklist $e): int
    {
        foreach ($this->chk->itemsDelTemplate($e->templateId) as $it) {
            if ((int) $it['es_cambio_sabanas'] === 1) {
                return (int) $it['id'];
            }
        }
        $this->fail('El checklist de prueba no tiene ítem de sábanas');
    }

    private function primerObligatorioNoSabanas(EjecucionChecklist $e): int
    {
        foreach ($this->chk->itemsDelTemplate($e->templateId) as $it) {
            if ((int) $it['obligatorio'] === 1 && (int) $it['es_cambio_sabanas'] === 0) {
                return (int) $it['id'];
            }
        }
        $this->fail('El checklist de prueba no tiene ítems obligatorios');
    }
}
