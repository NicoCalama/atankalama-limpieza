<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\ReportesController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Models\Usuario;
use Atankalama\Limpieza\Services\BonoAseoService;
use Atankalama\Limpieza\Services\HorasAseoService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * v7.18 «KPIs por hora»: desde noviembre de 2026 el bono y la Productividad se miden por hora; octubre y
 * antes quedan como estaban. Ver docs/kpis-sueldos.md.
 *
 * Las fechas de noviembre son del 07/11 o antes, para que el recorte de los días futuros no las saque.
 */
final class ReportesKpisPorHoraTest extends TestCase
{
    private ReportesService $rep;
    private BonoAseoService $bono;
    private int $sur;
    private int $inn;
    private int $tipo;
    private int $ana;
    private int $berta;
    private int $pedro;
    private int $admin;
    private int $manana;
    private int $template;
    private int $area;
    /** @var array<string, int> */
    private array $hab = [];

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur'), ('inn', 'Inn')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();
        $this->sur  = (int) Database::fetchColumn("SELECT id FROM hoteles WHERE codigo = '1_sur'");
        $this->inn  = (int) Database::fetchColumn("SELECT id FROM hoteles WHERE codigo = 'inn'");
        $this->tipo = (int) Database::fetchColumn('SELECT id FROM tipos_habitacion LIMIT 1');
        $this->template = (int) Database::fetchColumn('SELECT id FROM checklists_template WHERE habitacion_id IS NULL ORDER BY id LIMIT 1');

        foreach (['101', '102', '103', '104'] as $n) {
            $this->crearHabitacion($n, $this->sur, false);
        }
        $this->crearHabitacion('301', $this->inn, false);
        $this->crearHabitacion('Piscina', $this->sur, true);

        [$this->ana]   = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');
        [$this->berta] = TestDatabase::crearUsuario('22222222-2', 'Berta', 'Aseo');
        [$this->pedro] = TestDatabase::crearUsuario('33333333-3', 'Pedro', 'Apoyo');
        [$this->admin] = TestDatabase::crearUsuario('44444444-4', 'Admin', 'Admin');
        Database::execute("UPDATE usuarios SET jornada = 'completa', hotel_default = '1_sur' WHERE id IN (?, ?, ?)", [$this->ana, $this->berta, $this->pedro]);

        Database::execute("INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES ('mañana', '08:00', '16:00')");
        $this->manana = Database::lastInsertId();
        $this->area = TestDatabase::opcionId('area_apoyo', 'Lavandería');

        $this->rep  = new ReportesService();
        $this->bono = new BonoAseoService();
    }

    // ── Bono ───────────────────────────────────────────────────────────────

    public function testElBonoDeNoviembreUsaTurnosMasExtrasMenosOtrasAreas(): void
    {
        $this->jornadaDeAna('2026-11');

        $ana = $this->filaMensual('Ana', 2026, 11);
        $this->assertSame(2, $ana['dias_trabajados']);
        $this->assertSame(15.0, $ana['horas_aseo']['turno'], '2 días de 7,5 h');
        $this->assertSame(3.0, $ana['horas_aseo']['extras']);
        $this->assertSame(2.0, $ana['horas_aseo']['otras_areas']);
        $this->assertSame(16.0, $ana['horas_aseo']['aseo']);
        $this->assertSame(4, $ana['habitaciones']);
        $this->assertSame(0.25, $ana['bono']['act_hora'], '4 piezas ÷ 16 h');
        $this->assertArrayNotHasKey('act_dia', $ana['bono']);
        $this->assertSame(BonoAseoService::calcularPorHora(4, 960, 0, 2.4), $ana['bono'], 'corte derivado de 18 ÷ 7,5');
    }

    public function testLosMismosDatosEnOctubreSiguenPorDia(): void
    {
        $this->jornadaDeAna('2026-10');

        $ana = $this->filaMensual('Ana', 2026, 10);
        $this->assertNull($ana['horas_aseo'], 'octubre no tiene horas');
        $this->assertSame(2, $ana['dias_trabajados']);
        $this->assertSame(2.0, $ana['bono']['act_dia'], '4 piezas ÷ 2 días');
        $this->assertSame(BonoAseoService::calcular(4, 2, 'completa', 0, 18.0), $ana['bono']);
    }

    public function testQuienLimpioSinTurnoSeCalculaConLaJornadaEstandar(): void
    {
        $this->limpieza('101', $this->ana, '2026-11-03');

        $ana = $this->filaMensual('Ana', 2026, 11);
        $this->assertSame(HorasAseoService::ESTADO_OK, $ana['horas_aseo']['estado']);
        $this->assertSame(7.5, $ana['horas_aseo']['aseo']);
        $this->assertSame(1, $ana['horas_aseo']['dias_estimados']);
        $this->assertSame(0.13, $ana['bono']['act_hora'], '1 pieza ÷ 7,5 h');
    }

    public function testSinJornadaElBonoQuedaVacioYElEstadoLoDice(): void
    {
        Database::execute('UPDATE usuarios SET jornada = NULL WHERE id = ?', [$this->ana]);
        $this->limpieza('101', $this->ana, '2026-11-03');

        $ana = $this->filaMensual('Ana', 2026, 11);
        $this->assertSame(HorasAseoService::ESTADO_SIN_JORNADA, $ana['horas_aseo']['estado']);
        $this->assertNull($ana['bono']['act_hora']);
        $this->assertNull($ana['bono']['resultado_pct']);
        $this->assertSame(1, $ana['habitaciones'], 'la pieza se sigue contando');
    }

    // ── Días trabajados ────────────────────────────────────────────────────

    public function testUnDiaDeSoloAreaComunCuentaDesdeNoviembre(): void
    {
        $this->turno($this->ana, '2026-11-05');
        $this->limpieza('101', $this->ana, '2026-11-03');
        $this->turno($this->ana, '2026-11-03');
        $this->limpieza('Piscina', $this->ana, '2026-11-05');

        $ana = $this->filaFicha('Ana', '2026-11-01', '2026-11-07');
        $this->assertSame(2, $ana['dias_trabajados']);
        $this->assertSame(1, $ana['esperado_hab'], 'el área común no pasa a ser una pieza asignada');
        $this->assertSame(15.0, $ana['horas_aseo']['turno']);
    }

    public function testEnOctubreElDiaDeSoloAreaComunSigueSinContar(): void
    {
        $this->limpieza('101', $this->ana, '2026-10-13');
        $this->limpieza('Piscina', $this->ana, '2026-10-15');

        $this->assertSame(1, $this->filaFicha('Ana', '2026-10-01', '2026-10-31')['dias_trabajados']);
    }

    public function testUnAreaQueNadieEmpezoNoHaceDiaTrabajado(): void
    {
        $this->limpieza('101', $this->ana, '2026-11-03');
        $this->asignar('Piscina', $this->ana, '2026-11-05');

        $this->assertSame(1, $this->filaFicha('Ana', '2026-11-01', '2026-11-07')['dias_trabajados']);
    }

    public function testElAreaQueSoloTuvoElAtajoMarcarLimpiaNoHaceDiaTrabajado(): void
    {
        $this->limpieza('101', $this->ana, '2026-11-03');
        $asig = $this->asignar('Piscina', $this->ana, '2026-11-05');
        Database::execute(
            "INSERT INTO ejecuciones_checklist (habitacion_id, asignacion_id, usuario_id, template_id, estado, timestamp_inicio, timestamp_fin)
             VALUES (?, ?, ?, ?, 'completada', '2026-11-05T15:00:00.000Z', '2026-11-05T15:00:00.000Z')",
            [$this->hab['Piscina'], $asig, $this->ana, $this->template]
        );

        $this->assertSame(1, $this->filaFicha('Ana', '2026-11-01', '2026-11-07')['dias_trabajados']);
    }

    public function testUnDiaConSoloHorasAnotadasCuentaYLaPersonaAparece(): void
    {
        $this->turno($this->berta, '2026-11-04');
        $this->otraArea($this->berta, '2026-11-04', 240);

        $berta = $this->filaFicha('Berta', '2026-11-01', '2026-11-07');
        $this->assertSame(1, $berta['dias_trabajados']);
        $this->assertSame(0, $berta['habitaciones']);
        $this->assertSame(3.5, $berta['horas_aseo']['aseo'], '7,5 h de turno − 4 h en otra área');

        $mensual = $this->filaMensual('Berta', 2026, 11);
        $this->assertNull($mensual['bono']['resultado_pct'], 'sin piezas el bono queda vacío');
    }

    public function testElPersonalExcluidoConHorasAnotadasNoAparece(): void
    {
        $this->otraArea($this->pedro, '2026-11-04', 240);

        $this->assertSame([], array_column($this->rep->fichaKpis('2026-11-01', '2026-11-07', 'ambos', false)['trabajadores'], 'nombre'));
    }

    public function testTeniaTurnoYPiezasNoVinoYNadieLasMovioNoSumaDesdeNoviembre(): void
    {
        $this->turno($this->ana, '2026-11-03');
        $this->turno($this->ana, '2026-11-05');
        $this->limpieza('101', $this->ana, '2026-11-03');
        $this->asignar('102', $this->ana, '2026-11-05');

        $ana = $this->filaFicha('Ana', '2026-11-01', '2026-11-07');
        $this->assertSame(1, $ana['dias_trabajados'], 'si no empezó ninguna, no vino');
        $this->assertSame(7.5, $ana['horas_aseo']['turno']);
        $this->assertSame(2, $ana['esperado_hab'], 'la pieza sin hacer le sigue contando como asignada');
    }

    public function testEnOctubreLaPiezaSinEmpezarSigueHaciendoDiaTrabajado(): void
    {
        $this->limpieza('101', $this->ana, '2026-10-13');
        $this->asignar('102', $this->ana, '2026-10-15');

        $this->assertSame(2, $this->filaFicha('Ana', '2026-10-01', '2026-10-31')['dias_trabajados']);
    }

    public function testUnaLimpiezaEmpezadaSinTerminarHaceDiaTrabajado(): void
    {
        $asig = $this->asignar('101', $this->ana, '2026-11-05');
        Database::execute(
            "INSERT INTO ejecuciones_checklist (habitacion_id, asignacion_id, usuario_id, template_id, estado, timestamp_inicio)
             VALUES (?, ?, ?, ?, 'en_progreso', '2026-11-05T15:00:00.000Z')",
            [$this->hab['101'], $asig, $this->ana, $this->template]
        );

        $this->assertSame(1, $this->filaFicha('Ana', '2026-11-01', '2026-11-07')['dias_trabajados']);
    }

    public function testUnaPreasignacionFuturaNoEsDiaTrabajado(): void
    {
        $hoy = date('Y-m-d');
        $pasado = date('Y-m-d', strtotime('+2 days'));
        $this->turno($this->ana, $hoy);
        $this->turno($this->ana, $pasado);
        $this->limpieza('101', $this->ana, $hoy);
        $this->asignar('102', $this->ana, $pasado);

        $ana = $this->filaFicha('Ana', $hoy, $pasado);
        $this->assertSame(1, $ana['dias_trabajados']);
        $this->assertSame(7.5, $ana['horas_aseo']['turno']);
    }

    public function testElFiltroDeHotelDejaExtrasEnterasYOtrasAreasDeSusDias(): void
    {
        $this->turno($this->ana, '2026-11-03');
        $this->turno($this->ana, '2026-11-04');
        $this->limpieza('101', $this->ana, '2026-11-03');
        $this->limpieza('301', $this->ana, '2026-11-04');
        $this->otraArea($this->ana, '2026-11-04', 120);
        $this->extras($this->ana, '2026-11', 300);

        $ambos = $this->filaMensual('Ana', 2026, 11, 'ambos');
        $this->assertSame(2, $ambos['dias_trabajados']);
        $this->assertSame(2.0, $ambos['horas_aseo']['otras_areas']);
        $this->assertSame(18.0, $ambos['horas_aseo']['aseo'], '15 + 5 − 2');

        $sur = $this->filaMensual('Ana', 2026, 11, '1_sur');
        $this->assertSame(1, $sur['habitaciones']);
        $this->assertSame(2, $sur['dias_trabajados'], 'el 04 entra por sus horas anotadas: su hotel_default es 1_sur');

        $inn = $this->filaMensual('Ana', 2026, 11, 'inn');
        $this->assertSame(1, $inn['dias_trabajados']);
        $this->assertSame(5.0, $inn['horas_aseo']['extras'], 'las extras son de la persona, van enteras');
        $this->assertSame(2.0, $inn['horas_aseo']['otras_areas']);
    }

    // ── Productividad ──────────────────────────────────────────────────────

    public function testLaProductividadPorHoraEsElMismoNumeroQueElBono(): void
    {
        $this->jornadaDeAna('2026-11');

        $reporte = $this->rep->reporteKpis('2026-11-01', '2026-11-30', 'ambos');
        $this->assertTrue($reporte['por_hora']);
        $this->assertSame(0.25, $reporte['kpis']['productividad']['valor']);
        $this->assertSame('hab/hora', $reporte['kpis']['productividad']['unidad']);
        $this->assertSame('4 hab · 16 h · 1 trabaj.', $reporte['kpis']['productividad']['contexto']);
        $this->assertSame(16.0, $reporte['por_trabajadora'][0]['horas_aseo']['aseo']);
    }

    public function testEnUnPeriodoParcialNoSumaLasExtrasYLoDice(): void
    {
        $this->jornadaDeAna('2026-11');

        $k = $this->rep->kpis('2026-11-01', '2026-11-07', 'ambos')['productividad'];
        $this->assertSame(0.31, $k['valor'], '4 piezas ÷ (15 h − 2 h), sin las 3 h extras');
        $this->assertStringContainsString('sin extras del mes', $k['contexto']);
    }

    public function testUnPeriodoQueEmpiezaEnOctubreSiguePorDia(): void
    {
        $this->jornadaDeAna('2026-11');

        $reporte = $this->rep->reporteKpis('2026-10-31', '2026-11-07', 'ambos');
        $this->assertFalse($reporte['por_hora']);
        $this->assertSame('hab/día', $reporte['kpis']['productividad']['unidad']);
        $this->assertNull($reporte['por_trabajadora'][0]['horas_aseo']);
    }

    public function testQuienNoTieneHorasQuedaFueraDeLaCuentaDelEquipo(): void
    {
        $this->jornadaDeAna('2026-11');
        Database::execute('UPDATE usuarios SET jornada = NULL WHERE id = ?', [$this->berta]);
        $this->limpieza('301', $this->berta, '2026-11-05'); // sin jornada definida: no se pueden calcular sus horas

        $k = $this->rep->kpis('2026-11-01', '2026-11-30', 'ambos')['productividad'];
        $this->assertSame(0.25, $k['valor'], 'su pieza sin horas inflaría el número');
        $this->assertStringContainsString('1 sin horas', $k['contexto']);

        $suya = $this->rep->kpis('2026-11-01', '2026-11-30', 'ambos', $this->berta)['productividad'];
        $this->assertNull($suya['valor']);
        $this->assertSame('sin_datos', $suya['estado']);
    }

    // ── Corte ──────────────────────────────────────────────────────────────

    public function testElCorteDeNoviembreVaPorHoraYRechazaElNumeroPorDia(): void
    {
        try {
            $this->bono->guardarCorte(2026, 11, 18, $this->admin);
            $this->fail('18 no es un corte por hora');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('por hora', $e->getMessage());
        }

        $this->assertSame(18.0, $this->bono->guardarCorte(2026, 10, 18, $this->admin)['valor'], 'octubre sigue por día');
        $this->assertSame(2.35, $this->bono->guardarCorte(2026, 11, 2.354, $this->admin)['valor'], 'dos decimales');
    }

    public function testElCorteSinGuardarSeDerivaDelCortePorDia(): void
    {
        $this->assertSame(
            ['valor' => 2.4, 'mes_origen' => null, 'propio' => false, 'unidad' => 'hab_hora', 'derivado' => true],
            $this->bono->corteDelMes(2026, 11)
        );

        $this->bono->guardarCorte(2026, 10, 15, $this->admin);
        $this->assertSame(2.0, $this->bono->corteDelMes(2026, 11)['valor'], '15 ÷ 7,5');
    }

    public function testElCortePorHoraSeHeredaSinMezclarseConElPorDia(): void
    {
        $this->bono->guardarCorte(2026, 10, 15, $this->admin);
        $this->bono->guardarCorte(2026, 11, 2.6, $this->admin);

        $this->assertSame(
            ['valor' => 2.6, 'mes_origen' => '2026-11', 'propio' => true, 'unidad' => 'hab_hora', 'derivado' => false],
            $this->bono->corteDelMes(2026, 11)
        );
        $this->assertSame(
            ['valor' => 2.6, 'mes_origen' => '2026-11', 'propio' => false, 'unidad' => 'hab_hora', 'derivado' => false],
            $this->bono->corteDelMes(2026, 12)
        );
        $this->assertSame(15.0, $this->bono->corte(2026, 11)['valor'], 'el corte por día no se toca');
        $this->assertSame(['valor' => 15.0, 'mes_origen' => '2026-10', 'propio' => true], $this->bono->corteDelMes(2026, 10), 'octubre, igual que siempre');
    }

    // ── Excel, CSV y API ───────────────────────────────────────────────────

    public function testElExcelDeNoviembreLlevaLasHorasYLaActividadPorHora(): void
    {
        $this->jornadaDeAna('2026-11');

        $filas = $this->rep->hojasMensual(2026, 11, 'ambos')['Aseo'];
        $this->assertEquals(['Corte hab./hora', 2.4], $filas[2]);
        $this->assertSame(
            ['RUT', 'Trabajador', 'Jornada', 'Días trabajados', 'Hab. limpiadas', 'Hab. hechas', 'Horas de turno', 'Horas extras', 'Horas en otras áreas', 'Horas en Aseo', 'Act. por hora', 'Observaciones'],
            array_slice($filas[5], 0, 12)
        );
        $this->assertEquals(['11111111-1', 'Ana', 'Tiempo completo', 2, 4, 4, 15, 3, 2, 16, 0.25, 0], array_slice($filas[6], 0, 12));
        $total = $filas[count($filas) - 1];
        $this->assertSame('TOTAL', $total[1]);
        $this->assertEquals([15, 3, 2, 16], array_slice($total, 6, 4));
        $this->assertCount(count($filas[5]), $total, 'el total no se corre de columna');
    }

    public function testElExcelDiceSiFaltanLasHoras(): void
    {
        Database::execute('UPDATE usuarios SET jornada = NULL WHERE id = ?', [$this->ana]);
        $this->limpieza('101', $this->ana, '2026-11-03');

        $this->assertSame('Sin jornada', $this->rep->hojasMensual(2026, 11, 'ambos')['Aseo'][6][9]);
    }

    public function testElExcelDeOctubreNoCambia(): void
    {
        $this->jornadaDeAna('2026-10');

        $filas = $this->rep->hojasMensual(2026, 10, 'ambos')['Aseo'];
        $this->assertEquals(['Corte hab./día', 18, 'Jornada parcial', 9], $filas[2]);
        $this->assertSame('Act. por día', $filas[5][6]);
        $this->assertNotContains('Horas en Aseo', $filas[5]);
        $this->assertEquals(2, $filas[6][6]);
    }

    public function testElCsvVaEnLaUnidadDelPeriodo(): void
    {
        $this->jornadaDeAna('2026-11');
        $this->jornadaDeAna('2026-10');

        $noviembre = $this->rep->exportarCsv('2026-11-01', '2026-11-07', 'ambos');
        $this->assertStringContainsString('"Productiv. (hab/hora)";"Horas en Aseo";"Desmarcados (%)"', $noviembre);

        $octubre = $this->rep->exportarCsv('2026-10-01', '2026-10-31', 'ambos');
        $this->assertStringContainsString('"Productiv. (hab/día)";"Desmarcados (%)"', $octubre);
        $this->assertStringNotContainsString('Horas en Aseo', $octubre);
    }

    public function testLaApiInformaLaUnidadDelMes(): void
    {
        $ctl = new ReportesController();

        $noviembre = json_decode($ctl->resumenMensual($this->get(2026, 11))->cuerpo, true)['data'];
        $this->assertTrue($noviembre['por_hora']);
        $this->assertSame('hab_hora', $noviembre['corte']['unidad']);

        $julio = json_decode($ctl->resumenMensual($this->get(2026, 7))->cuerpo, true)['data'];
        $this->assertFalse($julio['por_hora']);
        $this->assertSame(['valor' => 18, 'mes_origen' => null, 'propio' => false], $julio['corte'], 'los meses viejos no cambian');
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /** Ana: 2 días con turno de 7,5 h, 4 piezas, 3 h extras y 2 h en otra área. */
    private function jornadaDeAna(string $mes): void
    {
        $dias = [$mes . '-03', $mes . '-04'];
        foreach ($dias as $d) {
            $this->turno($this->ana, $d);
        }
        $this->limpieza('101', $this->ana, $dias[0]);
        $this->limpieza('102', $this->ana, $dias[0]);
        $this->limpieza('103', $this->ana, $dias[1]);
        $this->limpieza('104', $this->ana, $dias[1]);
        if ($mes >= BonoAseoService::POR_HORA_DESDE) {
            $this->extras($this->ana, $mes, 180);
            $this->otraArea($this->ana, $dias[1], 120);
        }
    }

    private function crearHabitacion(string $numero, int $hotel, bool $area): void
    {
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado, es_espacio_comun) VALUES (?, ?, ?, 'sucia', ?)",
            [$hotel, $numero, $this->tipo, $area ? 1 : 0]
        );
        $this->hab[$numero] = Database::lastInsertId();
    }

    private function asignar(string $numero, int $usuario, string $fecha): int
    {
        Database::execute('INSERT INTO asignaciones (habitacion_id, usuario_id, fecha, activa) VALUES (?, ?, ?, 1)', [$this->hab[$numero], $usuario, $fecha]);
        return Database::lastInsertId();
    }

    /** Una limpieza terminada por la persona y sin inspeccionar: cuenta como pieza hecha. */
    private function limpieza(string $numero, int $usuario, string $fecha): void
    {
        $asig = $this->asignar($numero, $usuario, $fecha);
        Database::execute(
            "INSERT INTO ejecuciones_checklist (habitacion_id, asignacion_id, usuario_id, template_id, estado, timestamp_inicio, timestamp_fin)
             VALUES (?, ?, ?, ?, 'completada', ?, ?)",
            [$this->hab[$numero], $asig, $usuario, $this->template, $fecha . 'T15:00:00.000Z', $fecha . 'T15:30:00.000Z']
        );
        $ejecucion = Database::lastInsertId();
        foreach (Database::fetchAll('SELECT id FROM items_checklist WHERE template_id = ? AND obligatorio = 1', [$this->template]) as $item) {
            Database::execute('INSERT INTO ejecuciones_items (ejecucion_id, item_id, marcado, marcado_por) VALUES (?, ?, 1, ?)', [$ejecucion, (int) $item['id'], $usuario]);
        }
    }

    private function turno(int $usuario, string $fecha): void
    {
        Database::execute('INSERT INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)', [$usuario, $this->manana, $fecha]);
    }

    private function extras(int $usuario, string $mes, int $minutos): void
    {
        Database::execute('INSERT INTO horas_extras (usuario_id, mes, minutos) VALUES (?, ?, ?)', [$usuario, $mes, $minutos]);
    }

    private function otraArea(int $usuario, string $fecha, int $minutos): void
    {
        Database::execute('INSERT INTO horas_otras_areas (usuario_id, fecha, area_id, minutos) VALUES (?, ?, ?, ?)', [$usuario, $fecha, $this->area, $minutos]);
    }

    /** @return array<string, mixed> */
    private function filaFicha(string $nombre, string $desde, string $hasta): array
    {
        foreach ($this->rep->fichaKpis($desde, $hasta, 'ambos', false)['trabajadores'] as $t) {
            if ($t['nombre'] === $nombre) {
                return $t;
            }
        }
        $this->fail("{$nombre} no aparece en la ficha");
    }

    /** @return array<string, mixed> */
    private function filaMensual(string $nombre, int $anio, int $mes, string $hotel = 'ambos'): array
    {
        foreach ($this->rep->resumenMensual($anio, $mes, $hotel) as $t) {
            if ($t['nombre'] === $nombre) {
                return $t;
            }
        }
        $this->fail("{$nombre} no aparece en el resumen mensual");
    }

    private function get(int $anio, int $mes): Request
    {
        $r = new Request(metodo: 'GET', path: '/api/reportes/resumen-mensual', query: ['anio' => (string) $anio, 'mes' => (string) $mes]);
        $r->usuario = new Usuario(
            id: $this->admin, rut: '44444444-4', nombre: 'Admin', email: null, activo: true,
            requiereCambioPwd: false, hotelDefault: null, temaPreferido: 'claro',
            permisos: ['reportes.ver'], roles: ['Admin'],
        );
        return $r;
    }
}
