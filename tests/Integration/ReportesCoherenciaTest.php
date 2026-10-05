<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Services\AlertasService;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\HabitacionService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * v6.15 (30/09/2026): una sola definición en toda la pestaña Reportes. Las tarjetas de arriba, el
 * detalle por trabajadora y el resumen mensual (el «CRÉDITOS TOTAL» de sueldos) salen de la ficha;
 * la 2ª limpieza del día sobre la misma asignación (nochero, turnover) es otra pieza asignada; el
 * atajo «Marcar limpia» no es trabajo de nadie; el cierre automático no es una inspección.
 */
final class ReportesCoherenciaTest extends TestCase
{
    private ReportesService $rep;
    private AsignacionService $asig;
    private ChecklistService $chk;
    private AuditoriaService $aud;
    private int $ana;
    private int $berta;
    private int $sofia;
    private int $sistema;
    private int $hotelId;
    private int $tipoId;
    private string $hoy;
    /** @var array<string, int> */
    private array $hab = [];

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();
        $this->hotelId = (int) Database::fetchColumn("SELECT id FROM hoteles WHERE codigo = '1_sur'");
        $this->tipoId  = (int) Database::fetchColumn('SELECT id FROM tipos_habitacion LIMIT 1');
        foreach (['101', '102', '103', '104'] as $numero) {
            Database::execute(
                "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado, es_espacio_comun) VALUES (?, ?, ?, 'sucia', 0)",
                [$this->hotelId, $numero, $this->tipoId]
            );
            $this->hab[$numero] = Database::lastInsertId();
        }
        [$this->ana]     = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        [$this->berta]   = TestDatabase::crearUsuario('22222222-2', 'Berta', 'Trabajador');
        [$this->sofia]   = TestDatabase::crearUsuario('33333333-3', 'Sofia', 'Supervisora');
        [$this->sistema] = TestDatabase::crearUsuario('SISTEMA-CRON', 'Sistema', 'Admin');

        $this->hoy  = date('Y-m-d');
        $this->rep  = new ReportesService();
        $this->asig = new AsignacionService();
        $this->chk  = new ChecklistService();
        $this->aud  = new AuditoriaService();
    }

    public function testElNocheroDeLaTardeEsOtraPiezaAsignadaYLaEficienciaNoPasaDe100(): void
    {
        // Mañana: Ana limpia la 101 y Sofía la aprueba.
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $this->limpiar('101', $this->ana);
        $this->aud->emitirVeredicto($this->hab['101'], $this->sofia, Auditoria::VEREDICTO_APROBADO);

        // 16:00: el barrido de nocheros la vuelve a sucia SIN crear otra asignación y Ana la limpia de nuevo.
        (new HabitacionService())->cambiarEstado($this->hab['101'], 'sucia', null, 'cron', true);
        $this->limpiar('101', $this->ana);
        $this->aud->emitirVeredicto($this->hab['101'], $this->sofia, Auditoria::VEREDICTO_APROBADO);
        $this->assertSame(2, (int) Database::fetchColumn('SELECT COUNT(*) FROM ejecuciones_checklist'));
        $this->assertSame(1, (int) Database::fetchColumn('SELECT COUNT(DISTINCT asignacion_id) FROM ejecuciones_checklist'), 'las dos limpiezas cuelgan de la misma asignación');

        $ana = $this->filaDe('Ana');
        $this->assertSame(2, $ana['esperado_hab'], 'la limpieza de la tarde es otra pieza asignada');
        $this->assertSame(2, $ana['habitaciones']);
        $this->assertSame(100.0, $ana['eficiencia_pct'], 'antes daba 200 %: dos limpiezas de créditos contra una sola Asignada');
    }

    public function testTurnoverSinTerminarLaOtraPiezaNoTapaLoQueFalto(): void
    {
        // Ana tiene la 101 y la 102. Limpia la 101, se aprueba, entra otro huésped y la limpia otra vez; la 102 nunca la empieza.
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $this->asig->asignarManual($this->hab['102'], $this->ana, $this->hoy);
        $this->limpiar('101', $this->ana);
        $this->aud->emitirVeredicto($this->hab['101'], $this->sofia, Auditoria::VEREDICTO_APROBADO);
        (new HabitacionService())->cambiarEstado($this->hab['101'], 'sucia', null, 'cron', true);
        $this->limpiar('101', $this->ana);

        $ana = $this->filaDe('Ana');
        $this->assertSame(3, $ana['esperado_hab'], '101 dos veces + 102');
        $this->assertSame(2, $ana['habitaciones']);
        $this->assertSame(66.7, $ana['eficiencia_pct'], 'antes daba 100 %: los créditos dobles de la 101 tapaban la 102');
    }

    public function testMarcarLimpiaAManoNoEsTrabajoDeNadie(): void
    {
        // Ana limpia la 101 (30 min). La 103 era de Berta, pero Sofía la da por limpia con el atajo.
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $e = $this->limpiar('101', $this->ana);
        Database::execute(
            'UPDATE ejecuciones_checklist SET timestamp_fin = ? WHERE id = ?',
            [(new \DateTimeImmutable((string) Database::fetchColumn('SELECT timestamp_inicio FROM ejecuciones_checklist WHERE id = ?', [$e]), new \DateTimeZone('UTC')))->modify('+30 minutes')->format('Y-m-d\TH:i:s.v\Z'), $e]
        );
        $this->asig->asignarManual($this->hab['103'], $this->berta, $this->hoy);
        $this->chk->marcarLimpiaManual($this->hab['103'], $this->sofia);

        $nombres = array_column($this->rep->trabajadoras($this->hoy, $this->hoy, 'ambos'), 'nombre');
        $this->assertContains('Ana', $nombres);
        $this->assertNotContains('Sofia', $nombres, 'la supervisora no aparece como trabajadora');
        $this->assertNotContains('Berta', $nombres, 'a Berta se la resolvieron: no le queda nada asignado');

        $k = $this->rep->kpis($this->hoy, $this->hoy, 'ambos');
        $this->assertEquals(30.0, $k['tiempo_promedio']['valor'], 'la limpieza de 0 min del atajo no baja el promedio');
        $this->assertSame('1 limpiezas', $k['tiempo_promedio']['contexto']);
        $this->assertStringContainsString('1 trabaj.', $k['productividad']['contexto']);
    }

    public function testResumenMensualYTarjetasDanLosMismosCreditosQueLaFicha(): void
    {
        // Ana: la 101 se la rechazan y la rehace ella misma (2° intento = 50 %); la 102 sale a la primera.
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $this->limpiar('101', $this->ana);
        $this->aud->emitirVeredicto($this->hab['101'], $this->sofia, Auditoria::VEREDICTO_RECHAZADO, 'Rehacer estos ítems.', $this->obligatorios('101', 2));
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $this->limpiar('101', $this->ana);
        $this->aud->emitirVeredicto($this->hab['101'], $this->sofia, Auditoria::VEREDICTO_APROBADO);
        $this->asig->asignarManual($this->hab['102'], $this->ana, $this->hoy);
        $this->limpiar('102', $this->ana);

        $ficha   = $this->filaDe('Ana');
        $resumen = $this->rep->resumenMensual((int) date('Y'), (int) date('n'), 'ambos')[0];
        $tarjeta = $this->rep->kpis($this->hoy, $this->hoy, 'ambos', $this->ana);

        $this->assertSame($ficha['creditos'], $resumen['creditos'], 'el «CRÉDITOS TOTAL» de sueldos aplica la escalera 100/50/0');
        $this->assertSame($ficha['creditos'], $tarjeta['creditos']['valor']);
        $this->assertSame(1, $resumen['habitaciones'], 'la 101 sigue rechazada para ella: cuenta solo la 102');
        $this->assertSame(1, $resumen['rechazadas']);
        $this->assertSame($ficha['eficiencia_pct'], $resumen['eficiencia_pct']);
        $this->assertSame($ficha['eficiencia_pct'], $tarjeta['eficiencia']['valor']);
        $this->assertSame($ficha['rechazo_pct'], $tarjeta['tasa_rechazo']['valor']);
    }

    public function testElRechazoDelEquipoNoCuentaElCierreAutomaticoComoInspeccion(): void
    {
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $this->limpiar('101', $this->ana);
        $this->aud->emitirVeredicto($this->hab['101'], $this->sofia, Auditoria::VEREDICTO_RECHAZADO, 'Rehacer estos ítems.', $this->obligatorios('101', 1));
        $this->asig->asignarManual($this->hab['102'], $this->ana, $this->hoy);
        $this->limpiar('102', $this->ana);
        $this->aud->emitirVeredicto($this->hab['102'], $this->sistema, Auditoria::VEREDICTO_APROBADO_AUTOMATICO, 'Cierre de día');

        $equipo = $this->rep->kpis($this->hoy, $this->hoy, 'ambos')['tasa_rechazo'];
        $this->assertSame(100.0, $equipo['valor'], 'antes daba 50 %: la automática diluía el rechazo');
        $this->assertSame('1 de 1 inspeccionadas', $equipo['contexto']);
        $seccion = $this->rep->fichaKpis($this->hoy, $this->hoy, 'ambos')['supervisoras']['seccion']['rechazo']['valor'];
        $this->assertSame($seccion, $equipo['valor'], 'la tarjeta y la sección de inspección dicen lo mismo');

        // Para la trabajadora, la automática cuenta como aprobada (decisión de Gerencia del 16/09).
        $ana = $this->rep->kpis($this->hoy, $this->hoy, 'ambos', $this->ana)['tasa_rechazo'];
        $this->assertSame(50.0, $ana['valor']);
    }

    public function testPendientesAlCorteNoDanPorInspeccionadaLaQueAproboElSistema(): void
    {
        // La aprueba el cierre de las 15:50: antes del corte de las 23:50 → antes no salía en el reporte (R5).
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $this->limpiar('101', $this->ana);
        $this->aud->emitirVeredicto($this->hab['101'], $this->sistema, Auditoria::VEREDICTO_APROBADO_AUTOMATICO, 'Cierre de día');
        // Una inspección humana a tiempo sí queda fuera del reporte.
        $this->asig->asignarManual($this->hab['102'], $this->ana, $this->hoy);
        $this->limpiar('102', $this->ana);
        $this->aud->emitirVeredicto($this->hab['102'], $this->sofia, Auditoria::VEREDICTO_APROBADO);
        // Horas fijas del día (no las del reloj): si la suite corre pasadas las 23:50, la inspección
        // humana saldría «fuera de plazo» y el test fallaría por la hora, no por el código.
        Database::execute('UPDATE auditorias SET created_at = ?', [\Atankalama\Limpieza\Helpers\Fechas::instanteLocalUtc($this->hoy, '12:00')]);
        Database::execute('UPDATE ejecuciones_checklist SET timestamp_inicio = ?, timestamp_fin = ?', [
            \Atankalama\Limpieza\Helpers\Fechas::instanteLocalUtc($this->hoy, '10:00'),
            \Atankalama\Limpieza\Helpers\Fechas::instanteLocalUtc($this->hoy, '10:30'),
        ]);

        $reporte = $this->rep->auditoriasPendientes($this->hoy, 'ambos');
        $pendientes = array_merge($reporte['turnos']['mañana']['pendientes'], $reporte['turnos']['tarde']['pendientes']);
        $this->assertCount(1, $pendientes);
        $this->assertSame('101', $pendientes[0]['numero']);
        $this->assertSame('aprobada_automatica', $pendientes[0]['estado_auditoria']);
        $this->assertStringContainsString(
            ReportesService::ESTADOS_PENDIENTE['aprobada_automatica'],
            $this->rep->exportarCsvAuditoriasPendientes($this->hoy, 'ambos')
        );
    }

    public function testLaRechazadaQueRehaceOtraPersonaOtroDiaSigueRechazadaParaLaPrimera(): void
    {
        // Ayer: Ana limpia la 101 y se la rechazan. Hoy: Berta la rehace y se aprueba. Desde la v6.17
        // (R4) la herencia de ítems no cruza de día: hoy es otro aseo, Berta parte de cero y lo que
        // Ana marcó ayer no se traslada a hoy (antes le daba créditos a Ana en el día de Berta).
        $ayer = date('Y-m-d', strtotime($this->hoy . ' -1 day'));
        $this->asig->asignarManual($this->hab['101'], $this->ana, $ayer);
        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $ayer);
        foreach ($this->chk->itemsDelTemplate($e->templateId) as $it) {
            if ((int) $it['obligatorio'] === 1) {
                $this->chk->marcarItem($e->id, (int) $it['id'], true, $this->ana);
            }
        }
        $this->chk->completar($e->id, $this->ana);
        Database::execute('UPDATE ejecuciones_checklist SET timestamp_inicio = ?, timestamp_fin = ? WHERE id = ?', [
            \Atankalama\Limpieza\Helpers\Fechas::instanteLocalUtc($ayer, '10:00'),
            \Atankalama\Limpieza\Helpers\Fechas::instanteLocalUtc($ayer, '10:30'),
            $e->id,
        ]);
        $this->aud->emitirVeredicto($this->hab['101'], $this->sofia, Auditoria::VEREDICTO_RECHAZADO, 'Rehacer estos ítems.', $this->obligatorios('101', 2));
        $this->asig->asignarManual($this->hab['101'], $this->berta, $this->hoy);
        // Como en la UI: Berta abre la pieza (hereda lo que quedó bien, a nombre de Ana) y marca solo lo pendiente.
        $e2 = $this->chk->iniciarEjecucion($this->hab['101'], $this->berta, $this->hoy);
        foreach (Database::fetchAll(
            'SELECT ic.id FROM items_checklist ic
               LEFT JOIN ejecuciones_items ei ON ei.item_id = ic.id AND ei.ejecucion_id = ?
              WHERE ic.template_id = ? AND ic.obligatorio = 1 AND ic.activo = 1 AND COALESCE(ei.marcado, 0) = 0',
            [$e2->id, $e2->templateId]
        ) as $it) {
            $this->chk->marcarItem($e2->id, (int) $it['id'], true, $this->berta);
        }
        $this->chk->completar($e2->id, $this->berta);
        $this->aud->emitirVeredicto($this->hab['101'], $this->sofia, Auditoria::VEREDICTO_APROBADO);

        $mes =$this->rep->fichaKpis($ayer, $this->hoy, 'ambos')['trabajadores'];
        $ana = array_values(array_filter($mes, static fn (array $t): bool => $t['nombre'] === 'Ana'))[0];
        $this->assertSame(0, $ana['habitaciones'], 'antes le quedaba una pieza aprobada por los ítems heredados');
        $this->assertSame(1, $ana['rechazadas_hab']);
        $this->assertSame(0, $ana['creditos'], 'la rechazada pierde sus créditos: nadie la rehízo ese día, no hay herencia');
        $berta = array_values(array_filter($mes, static fn (array $t): bool => $t['nombre'] === 'Berta'))[0];
        $this->assertSame(1, $berta['habitaciones']);
        $this->assertSame(0, (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM ejecuciones_items WHERE ejecucion_id = ? AND marcado_por = ?',
            [$e2->id, $this->ana]
        ), 'la limpieza de hoy no trae ítems de ayer');
    }

    public function testElMinimoDeDatosCuentaLasPiezasRechazadas(): void
    {
        (new AlertasService())->actualizarConfig('reportes_min_datos', '2', $this->sofia);
        // Ana: 2 piezas, las 2 rechazadas (0 aprobadas). Antes quedaba en «pocos datos», sin semáforo.
        foreach (['101', '102'] as $numero) {
            $this->asig->asignarManual($this->hab[$numero], $this->ana, $this->hoy);
            $this->limpiar($numero, $this->ana);
            $this->aud->emitirVeredicto($this->hab[$numero], $this->sofia, Auditoria::VEREDICTO_RECHAZADO, 'Rehacer estos ítems.', $this->obligatorios($numero, 1));
        }

        $this->assertTrue($this->filaDe('Ana')['datos_suficientes'], '2 piezas trabajadas alcanzan el mínimo de 2');
    }

    // ─── helpers ───────────────────────────────────────────────────────────

    /** Inicia, marca todos los obligatorios y termina: devuelve el id de la ejecución. */
    private function limpiar(string $numero, int $usuario): int
    {
        $e = $this->chk->iniciarEjecucion($this->hab[$numero], $usuario, $this->hoy);
        foreach ($this->chk->itemsDelTemplate($e->templateId) as $it) {
            if ((int) $it['obligatorio'] === 1) {
                $this->chk->marcarItem($e->id, (int) $it['id'], true, $usuario);
            }
        }
        $this->chk->completar($e->id, $usuario);
        return $e->id;
    }

    /** @return list<int> los primeros $n ítems obligatorios del checklist de la última ejecución de la pieza */
    private function obligatorios(string $numero, int $n): array
    {
        $templateId = (int) Database::fetchColumn(
            'SELECT template_id FROM ejecuciones_checklist WHERE habitacion_id = ? ORDER BY id DESC LIMIT 1',
            [$this->hab[$numero]]
        );
        $ids = [];
        foreach ($this->chk->itemsDelTemplate($templateId) as $it) {
            if ((int) $it['obligatorio'] === 1 && count($ids) < $n) {
                $ids[] = (int) $it['id'];
            }
        }
        return $ids;
    }

    /** @return array<string, mixed> */
    private function filaDe(string $nombre): array
    {
        foreach ($this->rep->fichaKpis($this->hoy, $this->hoy, 'ambos')['trabajadores'] as $t) {
            if ($t['nombre'] === $nombre) {
                return $t;
            }
        }
        $this->fail("No aparece {$nombre} en la ficha");
    }
}
