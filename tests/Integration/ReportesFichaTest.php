<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Services\AlertasService;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Ficha de KPIs (docs/kpis-sueldos.md) sobre un día con cinco piezas:
 *
 *   101  Ana    limpia y Sofía APRUEBA                 → A (auditada) para Ana
 *   102  Ana    limpia y nadie audita                  → B (no auditada) para Ana
 *   103  Berta  limpia y el cierre la aprueba SOLO     → B para Berta (aprobada, pero NO es auditoría humana)
 *   104  Berta  limpia y Sofía RECHAZA 2 ítems         → R para Berta (pierde los créditos de la pieza)
 *   105  Ana    asignada, nunca la empieza             → solo suma al Esperado de Ana
 *
 * Con N = créditos obligatorios del checklist (todas las piezas son del mismo tipo).
 */
final class ReportesFichaTest extends TestCase
{
    private ReportesService $rep;
    private int $ana;
    private int $berta;
    private int $sofia;
    private int $n;
    private int $hotelId;
    private int $tipoId;
    /** @var array<string,int> número de pieza → id */
    private array $hab = [];

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();

        $this->hotelId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $this->tipoId  = (int) Database::fetchOne('SELECT id FROM tipos_habitacion LIMIT 1')['id'];
        foreach (['101', '102', '103', '104', '105'] as $numero) {
            $this->crearPieza($numero);
        }

        [$this->ana]   = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        [$this->berta] = TestDatabase::crearUsuario('22222222-2', 'Berta', 'Trabajador');
        [$this->sofia] = TestDatabase::crearUsuario('33333333-3', 'Sofia', 'Supervisora');
        // Usuario técnico que firma el cierre automático (mismo RUT que crea la migración).
        [$sistema]     = TestDatabase::crearUsuario('SISTEMA-CRON', 'Sistema', 'Admin');

        $fecha = date('Y-m-d');
        $asig  = new AsignacionService();
        $chk   = new ChecklistService();
        $aud   = new AuditoriaService();

        // 101 — Ana, aprobada por Sofía.
        $e = $this->limpiarCompleta($asig, $chk, '101', $this->ana, $fecha);
        $this->n = $this->creditosObligatorios($chk, $e->templateId);
        $aud->emitirVeredicto($this->hab['101'], $this->sofia, Auditoria::VEREDICTO_APROBADO);

        // 102 — Ana, queda sin auditar.
        $this->limpiarCompleta($asig, $chk, '102', $this->ana, $fecha);

        // 103 — Berta, la aprueba el cierre automático (no es auditoría humana).
        $this->limpiarCompleta($asig, $chk, '103', $this->berta, $fecha);
        $aud->emitirVeredicto($this->hab['103'], $sistema, Auditoria::VEREDICTO_APROBADO_AUTOMATICO);

        // 104 — Berta, Sofía rechaza dos ítems.
        $e4 = $this->limpiarCompleta($asig, $chk, '104', $this->berta, $fecha);
        $oblig = array_values(array_filter(
            $chk->itemsDelTemplate($e4->templateId),
            static fn(array $i) => (int) $i['obligatorio'] === 1
        ));
        $aud->emitirVeredicto(
            $this->hab['104'],
            $this->sofia,
            Auditoria::VEREDICTO_RECHAZADO,
            'Rehacer estos dos ítems.',
            [(int) $oblig[0]['id'], (int) $oblig[1]['id']]
        );

        // 105 — Ana, asignada pero nunca iniciada (solo Esperado).
        $asig->asignarManual($this->hab['105'], $this->ana, $fecha);

        // Tiempos deterministas: cada limpieza duró 30 min (fin = inicio + 30). Se fija por SQL
        // para no depender del reloj; la ventana sigue siendo "hoy" porque el inicio no cambia.
        foreach (Database::fetchAll('SELECT id, timestamp_inicio FROM ejecuciones_checklist') as $f) {
            $fin = (new \DateTimeImmutable((string) $f['timestamp_inicio'], new \DateTimeZone('UTC')))
                ->modify('+30 minutes')->format('Y-m-d\TH:i:s.v\Z');
            Database::execute('UPDATE ejecuciones_checklist SET timestamp_fin = ? WHERE id = ?', [$fin, (int) $f['id']]);
        }

        // Tiempo por auditación de la 101: Sofía la abrió 5 minutos antes de aprobar.
        $a101 = Database::fetchOne(
            'SELECT a.created_at, a.ejecucion_id FROM auditorias a WHERE a.habitacion_id = ?',
            [$this->hab['101']]
        );
        $abierta = (new \DateTimeImmutable((string) $a101['created_at'], new \DateTimeZone('UTC')))
            ->modify('-5 minutes')->format('Y-m-d\TH:i:s.v\Z');
        Database::execute(
            'UPDATE ejecuciones_checklist SET auditoria_iniciada_at = ? WHERE id = ?',
            [$abierta, (int) $a101['ejecucion_id']]
        );

        $this->rep = new ReportesService();
    }

    // ─── Trabajador ────────────────────────────────────────────────────────

    public function testCreditosYHabitacionesEnDosEtapas(): void
    {
        $t = $this->filaDe('Ana');
        $this->assertSame($this->n, $t['creditos_auditados'], '101 aprobada por Sofía');
        $this->assertSame($this->n, $t['creditos_no_auditados'], '102 sin auditar cuenta como aprobada');
        $this->assertSame(2 * $this->n, $t['creditos']);
        $this->assertSame(1, $t['hab_auditadas']);
        $this->assertSame(1, $t['hab_no_auditadas']);
        $this->assertSame(2, $t['habitaciones']);
        $this->assertSame(50.0, $t['cobertura_pct']);

        $b = $this->filaDe('Berta');
        // 103 aprobada automáticamente: para el trabajador es aprobada, pero va en B (no humana).
        $this->assertSame(0, $b['creditos_auditados']);
        $this->assertSame($this->n, $b['creditos_no_auditados']);
        $this->assertSame(1, $b['hab_no_auditadas']);
        // 104 rechazada: sus créditos no suman.
        $this->assertSame($this->n, $b['creditos']);
        $this->assertSame(0.0, $b['cobertura_pct']);
    }

    public function testEsperadoRechazadasYPorcentajes(): void
    {
        $t = $this->filaDe('Ana');
        // Esperado: 101, 102 y la 105 que nunca empezó (créditos del checklist vigente).
        $this->assertSame(3, $t['esperado_hab']);
        $this->assertSame(3 * $this->n, $t['esperado_creditos']);
        $this->assertSame(0, $t['rechazadas_hab']);
        $this->assertSame(66.7, $t['realizacion_pct'], '(2 aprobadas + 0 rechazadas) / 3 esperadas');
        $this->assertSame(66.7, $t['cumplimiento_pct']);
        $this->assertSame(100.0, $t['calidad_pct']);
        $this->assertSame(0.0, $t['rechazo_pct']);
        $this->assertSame(66.7, $t['eficiencia_pct'], '2N créditos aprobados / 3N esperados');
        $this->assertSame((float) $this->n, $t['creditos_por_hab']);

        $b = $this->filaDe('Berta');
        $this->assertSame(2, $b['esperado_hab']);
        $this->assertSame(2 * $this->n, $b['esperado_creditos']);
        $this->assertSame(1, $b['rechazadas_hab']);
        $this->assertSame($this->n, $b['rechazadas_creditos'], 'una pieza rechazada pierde todos sus créditos obligatorios');
        $this->assertSame(100.0, $b['realizacion_pct'], '(1 aprobada + 1 rechazada) / 2 esperadas');
        $this->assertSame(50.0, $b['cumplimiento_pct']);
        $this->assertSame(50.0, $b['calidad_pct']);
        $this->assertSame(50.0, $b['rechazo_pct']);
        $this->assertSame(50.0, $b['eficiencia_pct']);
    }

    public function testTiempoYRitmo(): void
    {
        $t = $this->filaDe('Ana');
        $this->assertSame(30.0, $t['tiempo_promedio']);
        $this->assertSame(1.0, $t['horas'], '2 limpiezas de 30 min');
        // Ritmo = créditos por hora trabajada.
        $this->assertSame(round(2 * $this->n / 1.0, 1), $t['ritmo']);
    }

    public function testComparacionConElGrupoRespetaElMinimoDeDatos(): void
    {
        // Default: mínimo 10 piezas → nadie compara (sin semáforo, promedio sin población).
        $ficha = $this->rep->fichaKpis(date('Y-m-d'), date('Y-m-d'), 'ambos');
        foreach ($ficha['trabajadores'] as $t) {
            $this->assertFalse($t['datos_suficientes']);
            $this->assertSame('sin_datos', $t['cmp']['rechazo_pct']['estado']);
        }
        $this->assertSame(0, $ficha['comparativa']['rechazo_pct']['n']);
    }

    public function testSemaforoPorDesviacionEstandar(): void
    {
        // Con mínimo 1 pieza ambas entran al promedio: rechazo Ana 0 %, Berta 50 % → σ = 25.
        (new AlertasService())->actualizarConfig('reportes_min_datos', '1', $this->sofia);
        $ficha = $this->rep->fichaKpis(date('Y-m-d'), date('Y-m-d'), 'ambos');

        $cmp = $ficha['comparativa']['rechazo_pct'];
        $this->assertSame(2, $cmp['n']);
        $this->assertSame(25.0, $cmp['promedio']);
        $this->assertSame(25.0, $cmp['sigma']);

        $por = [];
        foreach ($ficha['trabajadores'] as $t) {
            $por[$t['nombre']] = $t;
        }
        // Rechazo: menos = mejor. Berta está 1σ POR ENCIMA → amarillo; Ana por debajo → verde.
        $this->assertSame('alerta', $por['Berta']['cmp']['rechazo_pct']['estado']);
        $this->assertSame(25.0, $por['Berta']['cmp']['rechazo_pct']['delta']);
        $this->assertSame('ok', $por['Ana']['cmp']['rechazo_pct']['estado']);
        // Calidad: más = mejor. Berta 50 vs promedio 75 → 1σ por debajo → amarillo.
        $this->assertSame('alerta', $por['Berta']['cmp']['calidad_pct']['estado']);
        $this->assertSame('ok', $por['Ana']['cmp']['calidad_pct']['estado']);
        // Créditos por pieza: informativo, nunca rojo.
        $this->assertSame('informativo', $por['Ana']['cmp']['creditos_por_hab']['estado']);
    }

    // ─── Supervisora ───────────────────────────────────────────────────────

    public function testCoberturaDeLaSeccionExcluyeElCierreAutomatico(): void
    {
        $s = $this->rep->fichaKpis(date('Y-m-d'), date('Y-m-d'), 'ambos')['supervisoras']['seccion'];
        // 4 limpiadas (101, 102, 103, 104); solo 2 con veredicto humano (101 aprobada, 104 rechazada).
        $this->assertSame(4, $s['completadas']);
        $this->assertSame(2, $s['auditadas_humanas']);
        $this->assertSame(50.0, $s['cobertura']['valor']);
        $this->assertSame(90.0, $s['cobertura']['meta']);
        $this->assertSame('critico', $s['cobertura']['estado'], '50 % está muy por debajo de la meta del 90 %');
        $this->assertSame(50.0, $s['rechazo']['valor'], '1 rechazada de 2 inspeccionadas');
        $this->assertSame(50.0, $s['aprobacion_primera']['valor']);
        $this->assertSame('sin_datos', $s['cobertura']['tendencia'], 'ayer no hubo actividad');
    }

    public function testCoberturaDeLaSeccionPorTurnoSegunElCalendario(): void
    {
        // Calendario de Turnos: hoy Ana es de mañana y Berta de tarde (opción A, 17/09).
        Database::execute("INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES ('mañana', '08:00', '16:00'), ('tarde', '14:00', '22:00')");
        $manana = (int) Database::fetchColumn("SELECT id FROM turnos WHERE nombre = 'mañana'");
        $tarde  = (int) Database::fetchColumn("SELECT id FROM turnos WHERE nombre = 'tarde'");
        Database::execute(
            'INSERT INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?), (?, ?, ?)',
            [$this->ana, $manana, date('Y-m-d'), $this->berta, $tarde, date('Y-m-d')]
        );
        // Ayer Ana fue de tarde: no debe contaminar la clasificación de hoy (el join es por la fecha del turno).
        Database::execute(
            'INSERT INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)',
            [$this->ana, $tarde, date('Y-m-d', strtotime('-1 day'))]
        );

        $s = $this->rep->fichaKpis(date('Y-m-d'), date('Y-m-d'), 'ambos')['supervisoras']['seccion'];
        $porTurno = array_column($s['por_turno'], null, 'turno');
        $this->assertSame(['mañana', 'tarde'], array_keys($porTurno), 'ordenados por hora de inicio, sin fila «Sin turno»');
        $this->assertSame('Mañana', $porTurno['mañana']['nombre']);
        // Mañana (Ana): 101 y 102 limpiadas, solo la 101 inspeccionada.
        $this->assertSame(2, $porTurno['mañana']['completadas']);
        $this->assertSame(1, $porTurno['mañana']['auditadas_humanas']);
        $this->assertSame(50.0, $porTurno['mañana']['cobertura_pct']);
        $this->assertSame('critico', $porTurno['mañana']['cobertura_estado'], '50 % contra una meta del 90 %');
        $this->assertSame(0.0, $porTurno['mañana']['rechazo_pct']);
        // Tarde (Berta): 103 (cierre automático: no cuenta como inspeccionada) y 104 rechazada.
        $this->assertSame(2, $porTurno['tarde']['completadas']);
        $this->assertSame(1, $porTurno['tarde']['auditadas_humanas']);
        $this->assertSame(100.0, $porTurno['tarde']['rechazo_pct']);
        $this->assertSame(0.0, $porTurno['tarde']['aprobacion_pct']);
        // Los turnos suman exactamente el total de la sección.
        $this->assertSame($s['completadas'], $porTurno['mañana']['completadas'] + $porTurno['tarde']['completadas']);
        $this->assertSame(50.0, $s['cobertura']['valor']);

        // Sin calendario para Berta ese día → sus piezas caen en «Sin turno», al final.
        Database::execute('DELETE FROM usuarios_turnos WHERE usuario_id = ?', [$this->berta]);
        $s = $this->rep->fichaKpis(date('Y-m-d'), date('Y-m-d'), 'ambos')['supervisoras']['seccion'];
        $this->assertSame(['mañana', '__sin_turno'], array_column($s['por_turno'], 'turno'));
        $this->assertSame('Sin turno', $s['por_turno'][1]['nombre']);
        $this->assertSame(2, $s['por_turno'][1]['completadas']);
    }

    public function testInspectorasConTiempoPorAuditacionYAporte(): void
    {
        $sup = $this->rep->fichaKpis(date('Y-m-d'), date('Y-m-d'), 'ambos')['supervisoras'];
        $this->assertCount(1, $sup['inspectoras'], 'el usuario Sistema no es inspectora');
        $sofia = $sup['inspectoras'][0];
        $this->assertSame('Sofia', $sofia['nombre']);
        $this->assertSame(2, $sofia['total']);
        $this->assertSame(1, $sofia['aprobadas']);
        $this->assertSame(1, $sofia['rechazadas']);
        // Solo la 101 tiene apertura registrada: 5 minutos hasta el veredicto.
        $this->assertSame(1, $sofia['n_tiempo']);
        $this->assertSame(5.0, $sofia['tiempo_auditacion']);
        // Aporte: 2 inspeccionadas por ella sobre 4 limpiadas en la sección.
        $this->assertSame(50.0, $sofia['aporte_cobertura_pct']);
        // Promedio simple entre inspectoras (una sola → Δ 0).
        $this->assertSame(0.0, $sofia['cmp']['total']);
    }

    public function testRegistrarInicioSoloEnPiezasPendientes(): void
    {
        $aud = new AuditoriaService();
        // 102 está pendiente de inspección → se registra la apertura.
        $this->assertTrue($aud->registrarInicio($this->hab['102']));
        $abierta = Database::fetchColumn(
            'SELECT auditoria_iniciada_at FROM ejecuciones_checklist WHERE habitacion_id = ? ORDER BY id DESC LIMIT 1',
            [$this->hab['102']]
        );
        $this->assertNotNull($abierta);
        // 101 ya fue inspeccionada (ejecución 'auditada') → no hay nada que registrar.
        $this->assertFalse($aud->registrarInicio($this->hab['101']));
    }

    // ─── Unidad por turno, re-limpiezas, espacios comunes, metas (revisión 16/09) ────────

    public function testRangoDeVariosDiasCuentaCadaTurnoUnaVez(): void
    {
        // Ana también limpió la 105 AYER y Sofía la aprobó. Hoy la 105 sigue asignada y sin empezar.
        $ayer = date('Y-m-d', strtotime('-1 day'));
        $this->limpiarEnFecha('105', $this->ana, $ayer);
        (new AuditoriaService())->emitirVeredicto($this->hab['105'], $this->sofia, Auditoria::VEREDICTO_APROBADO);

        // Solo hoy: nada cambia (la limpieza de ayer queda fuera de la ventana).
        $hoy = $this->filaDe('Ana');
        $this->assertSame(3, $hoy['esperado_hab']);
        $this->assertSame(2, $hoy['habitaciones']);

        // Ayer + hoy: la 105 es un turno ayer (hecho) y otro hoy (pendiente) → E = 4, A = 3.
        $t = $this->filaDe('Ana', $ayer, date('Y-m-d'));
        $this->assertSame(4, $t['esperado_hab']);
        $this->assertSame(4 * $this->n, $t['esperado_creditos']);
        $this->assertSame(3, $t['habitaciones']);
        $this->assertSame(3 * $this->n, $t['creditos']);
        $this->assertSame(75.0, $t['eficiencia_pct'], '3N aprobados / 4N esperados: repetir pieza no infla nada');
        $this->assertSame(75.0, $t['cumplimiento_pct']);
        $this->assertSame((float) $this->n, $t['creditos_por_hab']);
    }

    public function testRehacerLaPropiaPiezaRechazadaRecuperaLaMitadYLuegoNada(): void
    {
        // Regla de jefatura: aprobada a la 1ª = 100 %, a la 2ª = 50 %, a la 3ª o más = 0 %.
        // Berta rehace hoy su 104 (rechazada una vez) y Sofía la aprueba → recupera la mitad.
        $this->limpiarEnFecha('104', $this->berta, date('Y-m-d'));
        (new AuditoriaService())->emitirVeredicto($this->hab['104'], $this->sofia, Auditoria::VEREDICTO_APROBADO);

        $mitad = (int) round($this->n * 0.5);
        $b = $this->filaDe('Berta');
        $this->assertSame(2, $b['esperado_hab'], 'reasignarse la misma pieza el mismo turno no suma asignadas');
        $this->assertSame(1, $b['rechazadas_hab'], 'la pieza sigue contando como rechazada');
        $this->assertSame(1, $b['habitaciones'], 'solo la 103: la rehecha no cuenta como hecha');
        $this->assertSame($this->n + $mitad, $b['creditos'], '103 completa + la mitad de la 104');
        $this->assertSame(round(($this->n + $mitad) / (2 * $this->n) * 100, 1), $b['eficiencia_pct']);
        $this->assertSame(100.0, $b['realizacion_pct']);

        // Pieza nueva 107: rechazada dos veces y aprobada al tercer intento → 0 % de esa pieza.
        $this->crearPieza('107');
        $this->limpiarEnFecha('107', $this->berta, date('Y-m-d'));
        $this->rechazarConDosItems('107');
        $this->limpiarEnFecha('107', $this->berta, date('Y-m-d'));
        $this->rechazarConDosItems('107');
        $this->limpiarEnFecha('107', $this->berta, date('Y-m-d'));
        (new AuditoriaService())->emitirVeredicto($this->hab['107'], $this->sofia, Auditoria::VEREDICTO_APROBADO);

        $b = $this->filaDe('Berta');
        $this->assertSame(3, $b['esperado_hab']);
        $this->assertSame(2, $b['rechazadas_hab'], '104 y 107: cada pieza se cuenta rechazada una sola vez por turno');
        $this->assertSame($this->n + $mitad, $b['creditos'], 'la 107 no aporta nada al tercer intento');
        $this->assertSame(1, $b['habitaciones']);
    }

    public function testSiOtraPersonaRehaceLaPiezaEllaSumaEsperadoYCreditos(): void
    {
        // Ana rehace la 104 de Berta (marca todos los ítems ella) y Sofía la aprueba:
        // Ana E+1, A+1 y +N créditos; Berta queda igual (pegajoso: la 104 sigue en su esperado y como R).
        $this->limpiarEnFecha('104', $this->ana, date('Y-m-d'));
        (new AuditoriaService())->emitirVeredicto($this->hab['104'], $this->sofia, Auditoria::VEREDICTO_APROBADO);

        $a = $this->filaDe('Ana');
        $this->assertSame(4, $a['esperado_hab']);
        $this->assertSame(3, $a['habitaciones']);
        $this->assertSame(3 * $this->n, $a['creditos']);

        $b = $this->filaDe('Berta');
        $this->assertSame(2, $b['esperado_hab']);
        $this->assertSame(1, $b['rechazadas_hab']);
        $this->assertSame($this->n, $b['creditos'], 'solo la 103');
    }

    public function testAprobadaConObservacionNoChocaConLaRecuperacionGradual(): void
    {
        // (1) A la primera, aprobada CON OBSERVACIÓN: no es rechazo → cuenta como hecha (A, auditada)
        //     y solo pierde los créditos de los ítems que el auditor observó.
        $this->crearPieza('108');
        $this->limpiarEnFecha('108', $this->ana, date('Y-m-d'));
        $obs = $this->itemsObligatorios('108', 2);
        (new AuditoriaService())->emitirVeredicto(
            $this->hab['108'], $this->sofia, Auditoria::VEREDICTO_APROBADO_CON_OBSERVACION,
            'Faltó repasar dos detalles.', $obs['ids']
        );
        $a = $this->filaDe('Ana');
        $this->assertSame(0, $a['rechazadas_hab'], 'con observación no es rechazo');
        $this->assertSame(3, $a['habitaciones'], '101, 102 y 108');
        $this->assertSame(2, $a['hab_auditadas'], '101 y 108 tienen veredicto humano');
        $this->assertSame(3 * $this->n - $obs['creditos'], $a['creditos'], 'solo se descuentan los ítems observados');
        $this->assertSame(100.0, $a['calidad_pct']);

        // (2) Rechazada, rehecha por la MISMA persona y aprobada CON OBSERVACIÓN al 2º intento:
        //     las dos reglas se componen — (créditos − ítems observados) × 50 % — y la pieza sigue como rechazada.
        $this->crearPieza('109');
        $this->limpiarEnFecha('109', $this->berta, date('Y-m-d'));
        $this->rechazarConDosItems('109');
        $this->limpiarEnFecha('109', $this->berta, date('Y-m-d'));
        $obs2 = $this->itemsObligatorios('109', 1);
        (new AuditoriaService())->emitirVeredicto(
            $this->hab['109'], $this->sofia, Auditoria::VEREDICTO_APROBADO_CON_OBSERVACION,
            'Quedó un detalle pendiente.', $obs2['ids']
        );
        $b = $this->filaDe('Berta');
        $this->assertSame(2, $b['rechazadas_hab'], '104 y 109');
        $this->assertSame(1, $b['habitaciones'], 'solo la 103');
        $this->assertSame(
            $this->n + (int) round(($this->n - $obs2['creditos']) * 0.5),
            $b['creditos'],
            '103 completa + la mitad de lo que quedó válido en la 109'
        );
    }

    public function testSiOtraPersonaRehaceSoloLoPendienteLaRechazadaNoSumaPieza(): void
    {
        // Ana rehace la 104 de Berta como en la UI real: hereda los ítems que quedaron bien (a nombre
        // de Berta) y marca solo los dos que Sofía desmarcó. Sofía aprueba.
        $pendientes = $this->itemsObligatorios('104', 2);
        $this->rehacerSoloPendientes('104', $this->ana, date('Y-m-d'));
        (new AuditoriaService())->emitirVeredicto($this->hab['104'], $this->sofia, Auditoria::VEREDICTO_APROBADO);

        $a = $this->filaDe('Ana');
        $this->assertSame(3, $a['habitaciones'], 'para Ana la 104 sí es una pieza hecha');
        $this->assertSame(2 * $this->n + $pendientes['creditos'], $a['creditos'], 'Ana gana lo que marcó');

        $b = $this->filaDe('Berta');
        $this->assertSame(1, $b['rechazadas_hab']);
        $this->assertSame(1, $b['habitaciones'], 'la 104 sigue siendo rechazada para Berta: no cuenta como hecha');
        $this->assertSame(
            $this->n + ($this->n - $pendientes['creditos']),
            $b['creditos'],
            'sus ítems heredados siguen a su nombre (regla de rework vigente, decisión pendiente en la ficha)'
        );
    }

    public function testBandaDeAlertaAlrededorDeLaMeta(): void
    {
        // Sección: cobertura 50 %, rechazo 50 %, aprobación 50 %. Metas 55 / 49 / 55 → todo en «alerta».
        $alertas = new AlertasService();
        $alertas->actualizarConfig('reportes_meta_cobertura', '55', $this->sofia);
        $alertas->actualizarConfig('reportes_meta_rechazo', '49', $this->sofia);
        $alertas->actualizarConfig('reportes_meta_aprobacion', '55', $this->sofia);
        $s = $this->rep->fichaKpis(date('Y-m-d'), date('Y-m-d'), 'ambos')['supervisoras']['seccion'];
        $this->assertSame('alerta', $s['cobertura']['estado'], 'hasta 10 puntos bajo la meta');
        $this->assertSame('alerta', $s['rechazo']['estado'], 'hasta 2 puntos sobre la meta');
        $this->assertSame('alerta', $s['aprobacion_primera']['estado']);
        // 11 puntos por debajo ya es crítico.
        $alertas->actualizarConfig('reportes_meta_cobertura', '61', $this->sofia);
        $s = $this->rep->fichaKpis(date('Y-m-d'), date('Y-m-d'), 'ambos')['supervisoras']['seccion'];
        $this->assertSame('critico', $s['cobertura']['estado']);
    }

    public function testReasignarHeredaLaFranjaYMantieneElCiclo(): void
    {
        // 108 asignada a Berta en la franja 'tarde' y rechazada; la supervisora la reasigna (flujo real de la app).
        $this->crearPieza('108');
        $this->limpiarEnFecha('108', $this->berta, date('Y-m-d'), 'tarde');
        $this->rechazarConDosItems('108');
        (new AsignacionService())->reasignar($this->hab['108'], $this->berta, date('Y-m-d'), 'Rehacer', $this->sofia);
        $franja = Database::fetchColumn(
            'SELECT franja FROM asignaciones WHERE habitacion_id = ? ORDER BY id DESC LIMIT 1',
            [$this->hab['108']]
        );
        $this->assertSame('tarde', $franja, 'la reasignación hereda la franja: es el mismo ciclo');

        // Al rehacerla ella misma aplica la gradualidad (50 %) en vez de contar como una pieza nueva.
        $chk = new ChecklistService();
        $e = $chk->iniciarEjecucion($this->hab['108'], $this->berta, date('Y-m-d'));
        foreach ($chk->itemsDelTemplate($e->templateId) as $it) {
            if ((int) $it['obligatorio'] === 1) {
                $chk->marcarItem($e->id, (int) $it['id'], true, $this->berta);
            }
        }
        $chk->completar($e->id, $this->berta);
        $this->llevarAFecha($e->id, date('Y-m-d'));
        (new AuditoriaService())->emitirVeredicto($this->hab['108'], $this->sofia, Auditoria::VEREDICTO_APROBADO);

        $b = $this->filaDe('Berta');
        $this->assertSame(3, $b['esperado_hab'], '103, 104 y 108: la reasignación no suma otra asignada');
        $this->assertSame(2, $b['rechazadas_hab']);
        $this->assertSame($this->n + (int) round($this->n * 0.5), $b['creditos'], '103 completa + la mitad de la 108');
    }

    public function testEsperadoIgnoraAsignacionesRetiradasSinTrabajo(): void
    {
        $this->crearPieza('106');
        $asig = new AsignacionService();
        $asig->asignarManual($this->hab['106'], $this->berta, date('Y-m-d'));
        // La retiran sin que nadie la trabaje (p. ej. ya estaba limpia al llegar el día): no era trabajo esperado.
        Database::execute('UPDATE asignaciones SET activa = 0 WHERE habitacion_id = ?', [$this->hab['106']]);
        $this->assertSame(2, $this->filaDe('Berta')['esperado_hab']);

        // Pero si después la pieza pasa a otra persona, lo de Berta fue una reasignación: pegajoso, cuenta.
        $asig->asignarManual($this->hab['106'], $this->ana, date('Y-m-d'));
        $this->assertSame(3, $this->filaDe('Berta')['esperado_hab']);
        $this->assertSame(4, $this->filaDe('Ana')['esperado_hab']);
    }

    public function testEspaciosComunesSumanCreditosPeroNoEntranEnLosRatios(): void
    {
        // Ana limpia además el lobby (área común, sin inspección): sus créditos suman al total del N1,
        // pero Eficiencia, Créd./pieza y Ritmo se miden solo sobre piezas de huésped.
        $this->crearPieza('Lobby', true);
        $this->limpiarEnFecha('Lobby', $this->ana, date('Y-m-d'));

        $t = $this->filaDe('Ana');
        $this->assertSame(3 * $this->n, $t['creditos'], 'N1: 101 + 102 + lobby');
        $this->assertSame(2 * $this->n, $t['creditos_hab']);
        $this->assertSame(2, $t['habitaciones'], 'el lobby no es pieza de huésped');
        $this->assertSame(3, $t['esperado_hab'], 'el lobby tampoco entra al esperado');
        $this->assertSame(66.7, $t['eficiencia_pct'], '2N / 3N: el lobby no infla la eficiencia');
        $this->assertSame((float) $this->n, $t['creditos_por_hab']);
        $this->assertSame(1.0, $t['horas'], 'horas solo de piezas de huésped');
        $this->assertSame(round(2 * $this->n / 1.0, 1), $t['ritmo']);
    }

    public function testMetasDeLaSeccionSonConfigurables(): void
    {
        $alertas = new AlertasService();
        $alertas->actualizarConfig('reportes_meta_rechazo', '60', $this->sofia);
        $alertas->actualizarConfig('reportes_meta_aprobacion', '40', $this->sofia);
        $s = $this->rep->fichaKpis(date('Y-m-d'), date('Y-m-d'), 'ambos')['supervisoras']['seccion'];
        $this->assertSame(60.0, $s['rechazo']['meta']);
        $this->assertSame('ok', $s['rechazo']['estado'], '50 % de rechazo cabe en una meta del 60 %');
        $this->assertSame(40.0, $s['aprobacion_primera']['meta']);
        $this->assertSame('ok', $s['aprobacion_primera']['estado']);
    }

    // ─── helpers ───────────────────────────────────────────────────────────

    private function limpiarCompleta(AsignacionService $asig, ChecklistService $chk, string $numero, int $usuario, string $fecha, ?string $franja = null): \Atankalama\Limpieza\Models\EjecucionChecklist
    {
        $habId = $this->hab[$numero];
        $asig->asignarManual($habId, $usuario, $fecha, null, $franja);
        $e = $chk->iniciarEjecucion($habId, $usuario, $fecha);
        foreach ($chk->itemsDelTemplate($e->templateId) as $it) {
            if ((int) $it['obligatorio'] === 1) {
                $chk->marcarItem($e->id, (int) $it['id'], true, $usuario);
            }
        }
        $chk->completar($e->id, $usuario);
        return $e;
    }

    private function creditosObligatorios(ChecklistService $chk, int $templateId): int
    {
        $total = 0;
        foreach ($chk->itemsDelTemplate($templateId) as $it) {
            if ((int) $it['obligatorio'] === 1) {
                $total += (int) $it['creditos'];
            }
        }
        return $total;
    }

    /** @return array<string, mixed> */
    private function filaDe(string $nombre, ?string $desde = null, ?string $hasta = null): array
    {
        $desde ??= date('Y-m-d');
        $hasta ??= date('Y-m-d');
        foreach ($this->rep->fichaKpis($desde, $hasta, 'ambos')['trabajadores'] as $t) {
            if ($t['nombre'] === $nombre) {
                return $t;
            }
        }
        $this->fail("No aparece {$nombre} en la ficha");
    }

    /**
     * Los primeros $cuantos ítems obligatorios del checklist de la última ejecución de la pieza,
     * con la suma de sus créditos (para calcular lo que descuenta una observación o un rechazo).
     *
     * @return array{ids: list<int>, creditos: int}
     */
    private function itemsObligatorios(string $numero, int $cuantos): array
    {
        $templateId = (int) Database::fetchColumn(
            'SELECT template_id FROM ejecuciones_checklist WHERE habitacion_id = ? ORDER BY id DESC LIMIT 1',
            [$this->hab[$numero]]
        );
        $oblig = array_values(array_filter(
            (new ChecklistService())->itemsDelTemplate($templateId),
            static fn(array $i) => (int) $i['obligatorio'] === 1
        ));
        $elegidos = array_slice($oblig, 0, $cuantos);
        return [
            'ids'      => array_map(static fn(array $i): int => (int) $i['id'], $elegidos),
            'creditos' => array_sum(array_map(static fn(array $i): int => (int) $i['creditos'], $elegidos)),
        ];
    }

    /** Sofía rechaza la última ejecución completada de la pieza desmarcando dos ítems obligatorios. */
    private function rechazarConDosItems(string $numero): void
    {
        (new AuditoriaService())->emitirVeredicto(
            $this->hab[$numero],
            $this->sofia,
            Auditoria::VEREDICTO_RECHAZADO,
            'Rehacer estos dos ítems.',
            $this->itemsObligatorios($numero, 2)['ids']
        );
    }

    private function crearPieza(string $numero, bool $espacioComun = false): int
    {
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado, es_espacio_comun) VALUES (?, ?, ?, 'sucia', ?)",
            [$this->hotelId, $numero, $this->tipoId, $espacioComun ? 1 : 0]
        );
        return $this->hab[$numero] = Database::lastInsertId();
    }

    /**
     * Asigna, limpia completa y termina la pieza como trabajo del turno $fecha: los timestamps de la
     * ejecución se llevan a ese día (misma hora, 30 min de trabajo) para que la ventana los ubique ahí.
     */
    private function limpiarEnFecha(string $numero, int $usuario, string $fecha, ?string $franja = null): void
    {
        $e = $this->limpiarCompleta(new AsignacionService(), new ChecklistService(), $numero, $usuario, $fecha, $franja);
        $this->llevarAFecha($e->id, $fecha);
    }

    /**
     * Rehace la pieza como en la UI real: se la asignan, abre la ejecución (que HEREDA los ítems
     * que quedaron bien, a nombre de quien los marcó) y marca SOLO los que faltan; luego termina.
     */
    private function rehacerSoloPendientes(string $numero, int $usuario, string $fecha): void
    {
        $habId = $this->hab[$numero];
        $chk = new ChecklistService();
        (new AsignacionService())->asignarManual($habId, $usuario, $fecha);
        $e = $chk->iniciarEjecucion($habId, $usuario, $fecha);
        $pendientes = Database::fetchAll(
            'SELECT ic.id FROM items_checklist ic
              LEFT JOIN ejecuciones_items ei ON ei.item_id = ic.id AND ei.ejecucion_id = ?
             WHERE ic.template_id = ? AND ic.obligatorio = 1 AND ic.activo = 1 AND COALESCE(ei.marcado, 0) = 0',
            [$e->id, $e->templateId]
        );
        foreach ($pendientes as $it) {
            $chk->marcarItem($e->id, (int) $it['id'], true, $usuario);
        }
        $chk->completar($e->id, $usuario);
        $this->llevarAFecha($e->id, $fecha);
    }

    /** Lleva los timestamps de la ejecución al día $fecha (misma hora, 30 min de trabajo). */
    private function llevarAFecha(int $ejecucionId, string $fecha): void
    {
        $dias = (int) round((strtotime($fecha) - strtotime(date('Y-m-d'))) / 86400);
        $inicioActual = (string) Database::fetchColumn('SELECT timestamp_inicio FROM ejecuciones_checklist WHERE id = ?', [$ejecucionId]);
        $inicio = (new \DateTimeImmutable($inicioActual, new \DateTimeZone('UTC')))->modify("{$dias} days");
        Database::execute(
            'UPDATE ejecuciones_checklist SET timestamp_inicio = ?, timestamp_fin = ? WHERE id = ?',
            [$inicio->format('Y-m-d\TH:i:s.v\Z'), $inicio->modify('+30 minutes')->format('Y-m-d\TH:i:s.v\Z'), $ejecucionId]
        );
    }
}
