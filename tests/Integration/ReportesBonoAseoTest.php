<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\ReportesController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Models\Usuario;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\BonoAseoService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Bono de aseo de RRHH en el resumen mensual (docs/kpis-sueldos.md):
 *
 *   101  Ana (parcial)  limpia, Sofía aprueba                  → hab. hecha
 *   102  Ana            limpia, Sofía aprueba CON OBSERVACIÓN  → hab. hecha + 1 casilla desmarcada
 *   103  Ana            limpia, Sofía RECHAZA                   → no es hab. hecha, + 2 casillas desmarcadas
 *   104  Ana            limpia, el cierre automático la aprueba → hab. hecha, sin observación
 *
 * Observaciones = casillas del checklist desmarcadas por el auditor (no piezas): 1 + 2 = 3.
 *
 * Y el corte de habitaciones diarias, guardado por mes y editable solo con reportes.editar_corte.
 */
final class ReportesBonoAseoTest extends TestCase
{
    private int $ana;
    private int $sofia;
    private int $admin;
    /** @var array<string,int> */
    private array $hab = [];

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();
        $hotel = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $tipo  = (int) Database::fetchOne('SELECT id FROM tipos_habitacion LIMIT 1')['id'];
        foreach (['101', '102', '103', '104'] as $n) {
            Database::execute("INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, ?, ?, 'sucia')", [$hotel, $n, $tipo]);
            $this->hab[$n] = Database::lastInsertId();
        }
        [$this->ana]   = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        [$this->sofia] = TestDatabase::crearUsuario('33333333-3', 'Sofia', 'Supervisora');
        [$this->admin] = TestDatabase::crearUsuario('44444444-4', 'Admin', 'Admin');
        [$sistema]     = TestDatabase::crearUsuario('SISTEMA-CRON', 'Sistema', 'Admin');
        Database::execute("UPDATE usuarios SET jornada = 'parcial' WHERE id = ?", [$this->ana]);

        $aud = new AuditoriaService();
        foreach (['101', '102', '103', '104'] as $n) {
            $this->limpiar($n);
        }
        $aud->emitirVeredicto($this->hab['101'], $this->sofia, Auditoria::VEREDICTO_APROBADO);
        $aud->emitirVeredicto($this->hab['102'], $this->sofia, Auditoria::VEREDICTO_APROBADO_CON_OBSERVACION, 'Faltó limpiar el espejo.', [$this->primerItem('102')]);
        $aud->emitirVeredicto($this->hab['103'], $this->sofia, Auditoria::VEREDICTO_RECHAZADO, 'Rehacer el baño completo.', $this->itemsObligatorios('103', 2));
        $aud->emitirVeredicto($this->hab['104'], $sistema, Auditoria::VEREDICTO_APROBADO_AUTOMATICO);
    }

    public function testResumenMensualTraeHabHechasObservacionesYBono(): void
    {
        $ana = $this->filaAna();
        $this->assertSame('11111111-1', $ana['rut']);
        $this->assertSame(3, $ana['habitaciones'], '101, 102 y 104 quedaron bien; la 103 fue rechazada');
        $this->assertSame(1, $ana['rechazadas']);
        $this->assertSame(4, $ana['limpiadas'], 'hab. limpiadas = 3 hechas + 1 rechazada');
        $this->assertSame(3, $ana['observaciones'], 'casillas desmarcadas: 1 en la 102 + 2 en la 103; el cierre automático no desmarca');
        $this->assertSame(1, $ana['dias_trabajados']);

        // Con el corte por defecto (18): parcial → base 9; 3 hab. en 1 día.
        $this->assertSame(
            BonoAseoService::calcular(3, 1, 'parcial', 3, BonoAseoService::CORTE_DEFAULT),
            $ana['bono']
        );
        $this->assertSame(9.0, $ana['bono']['base']);
        $this->assertSame(33.3, $ana['bono']['eficacia_pct'], '3 ÷ 9');
        $this->assertSame(100.0, $ana['bono']['observadas_pct'], '3 casillas ÷ 3 hab. hechas');
    }

    public function testCorteSeGuardaPorMesYLosMesesSiguientesLoHeredan(): void
    {
        $svc = new BonoAseoService();
        $this->assertSame(['valor' => 18.0, 'mes_origen' => null, 'propio' => false], $svc->corte(2026, 7));

        $svc->guardarCorte(2026, 7, 15.5, $this->admin);
        $this->assertSame(['valor' => 15.5, 'mes_origen' => '2026-07', 'propio' => true], $svc->corte(2026, 7));
        // Agosto no tiene valor propio: hereda el de julio. Junio (anterior) sigue con el default.
        $this->assertSame(['valor' => 15.5, 'mes_origen' => '2026-07', 'propio' => false], $svc->corte(2026, 8));
        $this->assertSame(18.0, $svc->corte(2026, 6)['valor']);

        // Cambiar septiembre no toca julio.
        $svc->guardarCorte(2026, 9, 12, $this->admin);
        $this->assertSame(15.5, $svc->corte(2026, 7)['valor']);
        $this->assertSame(12.0, $svc->corte(2026, 10)['valor']);
    }

    public function testElCorteDelMesCambiaElCalculo(): void
    {
        (new BonoAseoService())->guardarCorte((int) date('Y'), (int) date('n'), 4, $this->admin);
        $ana = $this->filaAna();
        $this->assertSame(2.0, $ana['bono']['base'], 'parcial = la mitad de 4');
        $this->assertSame(100.0, $ana['bono']['eficacia_pct']);
        $this->assertSame(1.0, $ana['bono']['extras'], '3 − 2 × 1');

        $filas = (new ReportesService())->hojasMensual((int) date('Y'), (int) date('n'), 'ambos')['Trabajadores'];
        $this->assertEquals(['Corte hab./día', 4, 'Jornada parcial', 2], $filas[2]);
        $this->assertSame(
            ['RUT', 'Trabajador', 'Jornada', 'Días trabajados', 'Hab. limpiadas', 'Hab. hechas', 'Act. por día', 'Observaciones'],
            array_slice($filas[5], 0, 8)
        );
        $this->assertSame('KPIs Calidad (%)', $filas[5][12], '«Resultado» pasó a llamarse «KPIs Calidad»');
        $this->assertEquals(['11111111-1', 'Ana', 'Tiempo parcial', 1, 4, 3, 3, 3], array_slice($filas[6], 0, 8));
    }

    public function testTarjetaDeLimpiadasEsElMismoNumeroQueLaSeccionSupervisora(): void
    {
        $rep = new ReportesService();
        $hoy = date('Y-m-d');

        $kpi = $rep->reporteKpis($hoy, $hoy, 'ambos')['kpis']['limpiadas'];
        $this->assertSame(4, $kpi['valor'], 'aprobada, con observación, rechazada y la del cierre automático');
        $this->assertSame('3 inspeccionadas por una persona', $kpi['contexto'], 'el cierre automático no es una inspección');
        $this->assertSame(
            $rep->fichaKpis($hoy, $hoy, 'ambos', true)['supervisoras']['seccion']['completadas'],
            $kpi['valor']
        );

        // Con la trabajadora filtrada, las suyas (aquí todas son de Ana).
        $this->assertSame(4, $rep->reporteKpis($hoy, $hoy, 'ambos', $this->ana)['kpis']['limpiadas']['valor']);
        $this->assertNull($rep->reporteKpis($hoy, $hoy, 'ambos', $this->sofia)['kpis']['limpiadas']['valor']);

        // Respeta el período: un rango que no incluye hoy no tiene limpiezas.
        $semanaPasada = date('Y-m-d', strtotime('-8 days'));
        $antier       = date('Y-m-d', strtotime('-2 days'));
        $vacio = $rep->reporteKpis($semanaPasada, $antier, 'ambos')['kpis']['limpiadas'];
        $this->assertNull($vacio['valor']);
        $this->assertSame('sin_datos', $vacio['estado']);
    }

    public function testInspectoresTraenLasCasillasQueDesmarcaron(): void
    {
        $porNombre = array_column(
            (new ReportesService())->resumenMensualAuditores((int) date('Y'), (int) date('n'), 'ambos'),
            null,
            'nombre'
        );
        $this->assertSame(3, $porNombre['Sofia']['total']);
        $this->assertSame(3, $porNombre['Sofia']['observaciones'], '1 casilla en la 102 + 2 en la 103');
        $this->assertSame(0, $porNombre['Sistema']['observaciones'], 'el cierre automático no desmarca');
    }

    public function testCorteFueraDeRangoSeRechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BonoAseoService())->guardarCorte(2026, 7, 0, $this->admin);
    }

    public function testControladorExigePermisoYValidaElValor(): void
    {
        $ctl = new ReportesController();

        $sinPermiso = $ctl->guardarCorte($this->put(['anio' => 2026, 'mes' => 7, 'valor' => 16], ['reportes.ver']));
        $this->assertSame(403, $sinPermiso->status);
        $this->assertSame(18.0, (new BonoAseoService())->corte(2026, 7)['valor'], 'sin permiso no se guarda nada');

        $invalido = $ctl->guardarCorte($this->put(['anio' => 2026, 'mes' => 7, 'valor' => 'muchas'], ['reportes.editar_corte']));
        $this->assertSame(400, $invalido->status);

        $ok = $ctl->guardarCorte($this->put(['anio' => 2026, 'mes' => 7, 'valor' => 16], ['reportes.editar_corte']));
        $this->assertSame(200, $ok->status, $ok->cuerpo);
        $this->assertSame(16.0, (new BonoAseoService())->corte(2026, 7)['valor']);

        // El resumen mensual informa el corte del mes que muestra.
        $get = new Request(metodo: 'GET', path: '/api/reportes/resumen-mensual', query: ['anio' => '2026', 'mes' => '7']);
        $get->usuario = $this->usuario(['reportes.ver']);
        $data = json_decode($ctl->resumenMensual($get)->cuerpo, true)['data'];
        $this->assertSame(['valor' => 16, 'mes_origen' => '2026-07', 'propio' => true], $data['corte']);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function filaAna(): array
    {
        foreach ((new ReportesService())->resumenMensual((int) date('Y'), (int) date('n'), 'ambos') as $f) {
            if ($f['usuario_id'] === $this->ana) {
                return $f;
            }
        }
        $this->fail('Ana no aparece en el resumen mensual');
    }

    private function limpiar(string $numero): void
    {
        $asig = new AsignacionService();
        $chk  = new ChecklistService();
        $asig->asignarManual($this->hab[$numero], $this->ana, date('Y-m-d'));
        $e = $chk->iniciarEjecucion($this->hab[$numero], $this->ana, date('Y-m-d'));
        foreach ($chk->itemsDelTemplate($e->templateId) as $it) {
            if ((int) $it['obligatorio'] === 1) {
                $chk->marcarItem($e->id, (int) $it['id'], true, $this->ana);
            }
        }
        // Antifraude de 3 min: el inicio se lleva 20 min atrás (mismo día) para poder completar.
        Database::execute(
            'UPDATE ejecuciones_checklist SET timestamp_inicio = ? WHERE id = ?',
            [gmdate('Y-m-d\TH:i:s.000\Z', max(strtotime('today'), time() - 1200)), $e->id]
        );
        $chk->completar($e->id, $this->ana);
    }

    private function primerItem(string $numero): int
    {
        return $this->itemsObligatorios($numero, 1)[0];
    }

    /** @return list<int> los primeros $cuantos ítems obligatorios del checklist de la pieza */
    private function itemsObligatorios(string $numero, int $cuantos): array
    {
        $templateId = (int) Database::fetchColumn(
            'SELECT template_id FROM ejecuciones_checklist WHERE habitacion_id = ? ORDER BY id DESC LIMIT 1',
            [$this->hab[$numero]]
        );
        $ids = [];
        foreach ((new ChecklistService())->itemsDelTemplate($templateId) as $it) {
            if ((int) $it['obligatorio'] === 1 && count($ids) < $cuantos) {
                $ids[] = (int) $it['id'];
            }
        }
        $this->assertCount($cuantos, $ids, 'el checklist no tiene suficientes ítems obligatorios');
        return $ids;
    }

    /**
     * @param array<string, mixed> $cuerpo
     * @param list<string> $permisos
     */
    private function put(array $cuerpo, array $permisos): Request
    {
        $r = new Request(metodo: 'PUT', path: '/api/reportes/corte-hab-dia', cuerpo: $cuerpo);
        $r->usuario = $this->usuario($permisos);
        return $r;
    }

    /** @param list<string> $permisos */
    private function usuario(array $permisos): Usuario
    {
        return new Usuario(
            id: $this->admin, rut: '44444444-4', nombre: 'Admin', email: null, activo: true,
            requiereCambioPwd: false, hotelDefault: null, temaPreferido: 'claro',
            permisos: $permisos, roles: ['Admin'],
        );
    }
}
