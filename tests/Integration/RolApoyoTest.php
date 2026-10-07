<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\HomeService;
use Atankalama\Limpieza\Services\RbacService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Services\UsuarioService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Rol «Apoyo» (01/10/2026): personal de otras áreas que limpia de vez en cuando. Trabaja igual que un
 * Trabajador, pero no suma créditos, no entra en KPIs ni en el bono, sus limpiezas no mueven los
 * promedios del equipo y no recibe piezas del reparto automático. Va por permisos (kpis.excluido,
 * asignaciones.excluir_auto), nunca por el nombre del rol.
 */
final class RolApoyoTest extends TestCase
{
    private ReportesService $rep;
    private AsignacionService $asig;
    private ChecklistService $chk;
    private AuditoriaService $aud;
    private int $ana;
    private int $pedro;
    private int $sofia;
    private int $hotelId;
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
        $tipoId = (int) Database::fetchColumn('SELECT id FROM tipos_habitacion LIMIT 1');
        foreach (['101', '102', '103', '104'] as $numero) {
            Database::execute(
                "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado, es_espacio_comun) VALUES (?, ?, ?, 'sucia', 0)",
                [$this->hotelId, $numero, $tipoId]
            );
            $this->hab[$numero] = Database::lastInsertId();
        }
        [$this->ana]   = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        [$this->pedro] = TestDatabase::crearUsuario('22222222-2', 'Pedro', 'Apoyo');
        [$this->sofia] = TestDatabase::crearUsuario('33333333-3', 'Sofia', 'Supervisora');

        $this->hoy  = date('Y-m-d');
        $this->rep  = new ReportesService();
        $this->asig = new AsignacionService();
        $this->chk  = new ChecklistService();
        $this->aud  = new AuditoriaService();
    }

    public function testElRolApoyoTrabajaComoTrabajadorPeroRestaKpisYRepartoYSoloTrabajadorNoLosTiene(): void
    {
        $pedro = (new UsuarioService())->buscarPorId($this->pedro);
        $this->assertNotNull($pedro);
        $this->assertTrue($pedro->tienePermiso('habitaciones.marcar_completada'));
        $this->assertTrue($pedro->tienePermiso(RbacService::PERMISO_EXCLUIDO_KPIS));
        $this->assertTrue($pedro->tienePermiso(RbacService::PERMISO_EXCLUIDO_AUTO_ASIGNAR));
        $this->assertFalse($pedro->tienePermiso('kpis.ver_propios'));

        // Los permisos de marca los lleva todo rol que no es de aseo (04/10/2026); Trabajador no.
        foreach (['Admin' => true, 'Supervisora' => true, 'Recepción' => true, 'Trabajador' => false] as $rol => $marcado) {
            $codigos = array_column(Database::fetchAll(
                'SELECT rp.permiso_codigo FROM rol_permisos rp JOIN roles r ON r.id = rp.rol_id WHERE r.nombre = ?',
                [$rol]
            ), 'permiso_codigo');
            foreach ([RbacService::PERMISO_EXCLUIDO_KPIS, RbacService::PERMISO_EXCLUIDO_AUTO_ASIGNAR] as $marca) {
                $this->assertSame($marcado, in_array($marca, $codigos, true), "{$rol} / {$marca}");
            }
        }
    }

    public function testApoyoNoApareceEnReportesNiEnElResumenMensualYNoMueveLasTarjetasDelEquipo(): void
    {
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $this->limpiar('101', $this->ana);
        $this->aud->emitirVeredicto($this->hab['101'], $this->sofia, Auditoria::VEREDICTO_APROBADO);
        $creditosAna = $this->filaDe('Ana')['creditos'];

        // Pedro limpia dos piezas: una se aprueba y la otra se rechaza.
        $this->asig->asignarManual($this->hab['102'], $this->pedro, $this->hoy);
        $this->limpiar('102', $this->pedro);
        $this->aud->emitirVeredicto($this->hab['102'], $this->sofia, Auditoria::VEREDICTO_APROBADO);
        $this->asig->asignarManual($this->hab['103'], $this->pedro, $this->hoy);
        $this->limpiar('103', $this->pedro);
        $this->aud->emitirVeredicto($this->hab['103'], $this->sofia, Auditoria::VEREDICTO_RECHAZADO, 'Rehacer estos ítems.', $this->obligatorios('103', 1));

        $nombres = array_column($this->rep->trabajadoras($this->hoy, $this->hoy, 'ambos'), 'nombre');
        $this->assertSame(['Ana'], $nombres, 'Pedro no aparece en el selector ni en el detalle');
        $this->assertSame(['Ana'], array_column($this->rep->fichaKpis($this->hoy, $this->hoy, 'ambos')['trabajadores'], 'nombre'));
        $this->assertSame(
            ['Ana'],
            array_column($this->rep->resumenMensual((int) date('Y'), (int) date('n'), 'ambos'), 'nombre'),
            'ni en el resumen mensual ni en el bono de RRHH'
        );

        $equipo = $this->rep->kpis($this->hoy, $this->hoy, 'ambos');
        $this->assertSame($creditosAna, $equipo['creditos']['valor'], 'los créditos del equipo son solo los de Ana');
        $this->assertSame(0.0, $equipo['tasa_rechazo']['valor'], 'el rechazo de Pedro no ensucia el del equipo');
        $this->assertSame('0 de 1 inspeccionadas', $equipo['tasa_rechazo']['contexto']);
        $this->assertSame('1 hab · 1 trabaj. · 1 día(s) · 1 jornada(s)', $equipo['productividad']['contexto']);

        // Pedirle a Reportes los KPIs de Pedro no inventa números.
        $suyos = $this->rep->kpis($this->hoy, $this->hoy, 'ambos', $this->pedro);
        $this->assertNull($suyos['creditos']['valor']);
        $this->assertNull($suyos['productividad']['valor']);
    }

    public function testLaPiezaQueLeRechazaronAApoyoYRehaceUnaTrabajadoraLeCuentaAElla(): void
    {
        $this->asig->asignarManual($this->hab['101'], $this->pedro, $this->hoy);
        $this->limpiar('101', $this->pedro);
        $this->aud->emitirVeredicto($this->hab['101'], $this->sofia, Auditoria::VEREDICTO_RECHAZADO, 'Rehacer estos ítems.', $this->obligatorios('101', 2));

        // Ana la rehace: hereda lo que quedó bien (a nombre de Pedro) y marca lo que falta.
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        foreach (Database::fetchAll(
            'SELECT ic.id FROM items_checklist ic
               LEFT JOIN ejecuciones_items ei ON ei.item_id = ic.id AND ei.ejecucion_id = ?
              WHERE ic.template_id = ? AND ic.obligatorio = 1 AND ic.activo = 1 AND COALESCE(ei.marcado, 0) = 0',
            [$e->id, $e->templateId]
        ) as $it) {
            $this->chk->marcarItem($e->id, (int) $it['id'], true, $this->ana);
        }
        $this->chk->completar($e->id, $this->ana);
        $this->aud->emitirVeredicto($this->hab['101'], $this->sofia, Auditoria::VEREDICTO_APROBADO);

        $ana = $this->filaDe('Ana');
        $this->assertSame(1, $ana['habitaciones'], 'la pieza le cuenta a Ana');
        $this->assertSame(0, $ana['rechazadas_hab'], 'el rechazo era de Pedro');
        $this->assertGreaterThan(0, $ana['creditos'], 'lo que marcó ella vale');
        $this->assertSame(['Ana'], array_column($this->rep->trabajadoras($this->hoy, $this->hoy, 'ambos'), 'nombre'));
    }

    public function testElRepartoAutomaticoSaltaAApoyoAunqueTengaTurno(): void
    {
        Database::execute("INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES ('mañana', '08:00', '16:00')");
        $turnoId = (int) Database::fetchColumn('SELECT id FROM turnos LIMIT 1');
        foreach ([$this->ana, $this->pedro] as $uid) {
            Database::execute('INSERT INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)', [$uid, $turnoId, $this->hoy]);
        }

        $r = $this->asig->autoAsignar('1_sur', $this->hoy);

        $this->assertSame(1, $r['trabajadores']);
        $this->assertCount(4, $this->asig->colaDelTrabajador($this->ana, $this->hoy));
        $this->assertCount(0, $this->asig->colaDelTrabajador($this->pedro, $this->hoy));

        // A mano sí se le puede asignar.
        $this->asig->asignarManual($this->hab['101'], $this->pedro, $this->hoy);
        $this->assertCount(1, $this->asig->colaDelTrabajador($this->pedro, $this->hoy));
    }

    public function testElInicioDelAdminCuentaLasInspeccionesDeApoyoPeroNoEnLaTasaDeRechazo(): void
    {
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $this->limpiar('101', $this->ana);
        $this->aud->emitirVeredicto($this->hab['101'], $this->sofia, Auditoria::VEREDICTO_APROBADO);
        $this->asig->asignarManual($this->hab['102'], $this->pedro, $this->hoy);
        $this->limpiar('102', $this->pedro);
        $this->aud->emitirVeredicto($this->hab['102'], $this->sofia, Auditoria::VEREDICTO_RECHAZADO, 'Rehacer estos ítems.', $this->obligatorios('102', 1));

        $home = new HomeService();
        $m = $home->metricasOperativasHotel($this->hotelId, $this->hoy);
        $this->assertSame(2, $m['auditorias']['total'], 'las inspecciones del día son operación: cuentan todas');
        $this->assertSame(1, $m['auditorias']['rechazadas']);
        $this->assertSame(['rechazadas' => 0, 'total' => 1], $m['auditorias_kpi']);

        $kpis = $home->kpisHotel($this->hotelId, $this->hoy, $m);
        $this->assertSame(0.0, $kpis['tasa_rechazo']['valor']);
        $consolidado = $home->kpisDesdeMetricasConsolidadas($home->consolidarMetricas(['1_sur' => $m]));
        $this->assertSame(0.0, $consolidado['tasa_rechazo']['valor']);
    }

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
