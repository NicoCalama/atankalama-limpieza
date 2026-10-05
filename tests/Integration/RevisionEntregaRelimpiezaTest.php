<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\CloudbedsSyncService;
use Atankalama\Limpieza\Services\HabitacionService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Services\RevisionEntregaException;
use Atankalama\Limpieza\Services\RevisionEntregaService;
use Atankalama\Limpieza\Tests\Support\FakeHttpTransport;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * «Re-limpiar» de la inspección pre-entrega (v6.18, decisiones de Nicolás del 05/10/2026):
 *  - la supervisora asigna la re-limpieza de una pieza aprobada que Recepción no aprobó, con prioridad;
 *  - la re-limpieza no suma ni resta en los KPIs de aseo ni de inspección (tampoco con el interruptor);
 *  - el NO le cuenta a la supervisora que aprobó la pieza (columna «Recepción» de Reportes).
 */
final class RevisionEntregaRelimpiezaTest extends TestCase
{
    private FakeHttpTransport $cb;
    private RevisionEntregaService $svc;
    private int $ana;
    private int $berta;
    private int $sofia;
    private int $carla;
    private int $motivo;
    private string $hoy;
    /** @var array<string, int> */
    private array $hab = [];

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre, cloudbeds_property_id) VALUES ('1_sur', '1 Sur', 'CB_1SUR')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();
        $hotel = (int) Database::fetchColumn("SELECT id FROM hoteles WHERE codigo = '1_sur'");
        $tipo = (int) Database::fetchColumn('SELECT id FROM tipos_habitacion LIMIT 1');
        foreach (['101', '102', '103'] as $numero) {
            Database::execute(
                "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, cloudbeds_room_id, estado, es_espacio_comun) VALUES (?, ?, ?, ?, 'sucia', 0)",
                [$hotel, $numero, $tipo, 'CB_' . $numero]
            );
            $this->hab[$numero] = Database::lastInsertId();
        }
        [$this->ana]   = TestDatabase::crearUsuario('11111111-1', 'Ana Díaz', 'Trabajador');
        [$this->berta] = TestDatabase::crearUsuario('44444444-4', 'Berta Soto', 'Trabajador');
        [$this->sofia] = TestDatabase::crearUsuario('22222222-2', 'Sofía', 'Supervisora');
        [$this->carla] = TestDatabase::crearUsuario('33333333-3', 'Carla', 'Recepción');
        Database::execute("INSERT INTO motivos_revision_entrega (nombre) VALUES ('Faltan toallas')");
        $this->motivo = Database::lastInsertId();
        $this->hoy = date('Y-m-d');

        $this->cb = new FakeHttpTransport();
        $sync = new CloudbedsSyncService(new CloudbedsClient(
            transport: $this->cb,
            baseUrl: 'https://cb.test',
            apiKey: 'k',
            backoffs: [0, 0, 0],
            dormir: static fn(int $s) => null,
            dryRun: false,
        ));
        $this->svc = new RevisionEntregaService(cloudbeds: $sync, asignaciones: new AsignacionService(cloudbeds: $sync));
    }

    /** $quien limpia la pieza (todo lo obligatorio) y queda pendiente de inspección. */
    private function limpiar(string $numero, int $quien, bool $asignar = true): void
    {
        if ($asignar) {
            (new AsignacionService())->asignarManual($this->hab[$numero], $quien, $this->hoy);
        }
        $chk = new ChecklistService();
        $e = $chk->iniciarEjecucion($this->hab[$numero], $quien, $this->hoy);
        foreach ($chk->itemsDelTemplate($e->templateId) as $it) {
            if ((int) $it['obligatorio'] === 1) {
                $chk->marcarItem($e->id, (int) $it['id'], true, $quien);
            }
        }
        $chk->completar($e->id, $quien);
    }

    private function aprobar(string $numero): void
    {
        (new AuditoriaService())->emitirVeredicto($this->hab[$numero], $this->sofia, Auditoria::VEREDICTO_APROBADO);
    }

    private function no(string $numero): int
    {
        return $this->svc->registrar($this->hab[$numero], 'no', $this->motivo, null, null, $this->carla)['revision']['id'];
    }

    private function estado(string $numero): string
    {
        return (string) Database::fetchColumn('SELECT estado FROM habitaciones WHERE id = ?', [$this->hab[$numero]]);
    }

    /** @return array<string, mixed> fila de la ficha de esa persona (o ceros si no aparece) */
    private function ficha(int $uid): array
    {
        foreach ((new ReportesService())->fichaKpis($this->hoy, $this->hoy, 'ambos', false)['trabajadores'] as $t) {
            if ((int) $t['usuario_id'] === $uid) {
                return $t;
            }
        }
        return ['habitaciones' => 0, 'esperado_hab' => 0, 'creditos' => 0, 'rechazadas_hab' => 0, 'ejecuciones' => 0];
    }

    private function esperarError(string $codigo, callable $fn): void
    {
        try {
            $fn();
            $this->fail("se esperaba {$codigo}");
        } catch (RevisionEntregaException $e) {
            $this->assertSame($codigo, $e->codigo);
        }
    }

    public function testReLimpiarLaAsignaPrimeraEnLaColaYAvisaALaTrabajadora(): void
    {
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        (new AsignacionService())->asignarManual($this->hab['102'], $this->berta, $this->hoy); // Berta ya tenía la 102
        $rev = $this->no('101');
        $this->assertSame('aprobada', $this->estado('101'), 'interruptor apagado: el NO solo avisa');

        $this->cb->peticiones = [];
        $this->cb->encolarOk(200, ['success' => true]);
        $r = $this->svc->pedirRelimpieza($rev, $this->berta, true, $this->sofia);

        $this->assertSame('sucia', $this->estado('101'));
        $this->assertSame('Berta Soto', $r['relimpieza_trabajador']);
        $this->assertFalse($r['relimpieza_iniciada']);
        $this->assertCount(1, $this->cb->peticiones, 'avisa dirty a Cloudbeds');
        $this->assertSame('dirty', $this->cb->peticiones[0]['cuerpo']['roomCondition'] ?? null);

        $asig = new AsignacionService();
        $this->assertSame(['101', '102'], array_column($asig->colaDelTrabajador($this->berta, $this->hoy), 'numero'), 'primera de su cola');
        $this->assertSame('101', $asig->habitacionActualDeCola($this->berta, $this->hoy)['numero'] ?? null);
        $this->assertSame($this->berta, $asig->obtenerActivaDeHabitacion($this->hab['101'], $this->hoy)?->usuarioId, 'pasó de la cola de Ana a la de Berta');
        $this->assertSame('Berta Soto', (new HabitacionService())->obtenerDetalle($this->hab['101'])['asignado_a_nombre'] ?? null, 'el detalle dice a quién está asignada');

        $aviso = (string) Database::fetchColumn(
            "SELECT cuerpo FROM notificaciones WHERE usuario_id = ? AND titulo = 'Nueva habitación asignada' ORDER BY id DESC LIMIT 1",
            [$this->berta]
        );
        $this->assertStringContainsString('#101', $aviso);
        $this->assertStringContainsString('re-limpieza', $aviso);
        $this->assertStringContainsString('Faltan toallas', $aviso);
        $this->assertSame(1, (int) Database::fetchColumn("SELECT COUNT(*) FROM audit_log WHERE accion = 'revision_entrega.pedir_relimpieza'"));

        // Pedida la re-limpieza, la franja sigue mostrando el NO hasta que empiecen a limpiar.
        $this->assertSame($rev, $this->svc->revisionesVigentes()[$this->hab['101']]['id'] ?? null);
        (new ChecklistService())->iniciarEjecucion($this->hab['101'], $this->berta, $this->hoy);
        $this->assertArrayNotHasKey($this->hab['101'], $this->svc->revisionesVigentes());
        $this->assertTrue($this->svc->obtener($rev)['relimpieza_iniciada'], 'la limpieza quedó vinculada como re-limpieza');
    }

    public function testSinPrioridadQuedaAlFinalDeLaCola(): void
    {
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        (new AsignacionService())->asignarManual($this->hab['102'], $this->berta, $this->hoy);
        $this->cb->encolarOk(200, ['success' => true]);

        $this->svc->pedirRelimpieza($this->no('101'), $this->berta, false, $this->sofia);

        $this->assertSame(['102', '101'], array_column((new AsignacionService())->colaDelTrabajador($this->berta, $this->hoy), 'numero'));
    }

    /** Decisión de Nicolás (05/10/2026): la re-limpieza no suma para la trabajadora ni en la inspección. */
    public function testLaReLimpiezaNoSumaEnLosKpisDeAseoNiDeInspeccion(): void
    {
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $this->cb->encolarOk(200, ['success' => true]);
        $this->svc->pedirRelimpieza($this->no('101'), $this->berta, true, $this->sofia);
        $this->limpiar('101', $this->berta, asignar: false);
        $this->aprobar('101');

        $ana = $this->ficha($this->ana);
        $this->assertSame([1, 1, 0], [$ana['habitaciones'], $ana['esperado_hab'], $ana['rechazadas_hab']], 'a Ana no se le toca nada');
        $berta = $this->ficha($this->berta);
        $this->assertSame([0, 0, 0, 0], [$berta['habitaciones'], $berta['esperado_hab'], $berta['creditos'], $berta['ejecuciones']], 'a Berta no le suma');
        $this->assertSame(1, $berta['dias_trabajados'] ?? null, 'pero el día sí cuenta como trabajado');

        $rep = new ReportesService();
        $kpis = $rep->kpis($this->hoy, $this->hoy, 'ambos');
        $this->assertSame(1, $kpis['limpiadas']['valor'], 'Habitaciones limpiadas: solo la original');
        $sup = $rep->fichaKpis($this->hoy, $this->hoy, 'ambos')['supervisoras'];
        $this->assertSame([1, 1], [$sup['seccion']['completadas'], $sup['seccion']['auditadas_humanas']]);
        $sofia = $sup['inspectoras'][0];
        $this->assertSame([1, 0, 1], [$sofia['total'], $sofia['recepcion_aprobadas'], $sofia['recepcion_rechazadas']], 'el NO le cuenta a ella');
        $mensual = $rep->resumenMensual((int) date('Y'), (int) date('n'), 'ambos');
        $this->assertSame([1], array_values(array_map(static fn (array $t): int => $t['habitaciones'], array_filter($mensual, fn (array $t): bool => $t['usuario_id'] === $this->ana))));
    }

    /** Interruptor prendido: la pieza vuelve a la cola de quien la limpió; su re-limpieza tampoco suma. */
    public function testConElInterruptorLaReLimpiezaDeLaMismaTrabajadoraNoSuma(): void
    {
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $this->svc->configurarNoEnsucia(true, $this->sofia);
        $this->cb->encolarOk(200, ['success' => true]);
        $rev = $this->no('101');
        $this->assertSame('sucia', $this->estado('101'));

        $this->limpiar('101', $this->ana, asignar: false);
        $this->aprobar('101');

        $this->assertTrue($this->svc->obtener($rev)['relimpieza_iniciada']);
        $ana = $this->ficha($this->ana);
        $this->assertSame([1, 1], [$ana['habitaciones'], $ana['esperado_hab']], 'una sola pieza, no dos');
        $this->assertSame(1, $ana['ejecuciones'], 'solo el tiempo de la limpieza original');
    }

    public function testUnPedidoDeOtroDiaNoSeVincula(): void
    {
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $this->svc->configurarNoEnsucia(true, $this->sofia);
        $this->cb->encolarOk(200, ['success' => true]);
        $rev = $this->no('101');
        Database::execute(
            'UPDATE revisiones_entrega SET created_at = ? WHERE id = ?',
            [gmdate('Y-m-d\TH:i:s.000\Z', (int) strtotime('-1 day')), $rev]
        );

        $this->limpiar('101', $this->ana, asignar: false);

        $this->assertFalse($this->svc->obtener($rev)['relimpieza_iniciada'], 'la limpieza de hoy es la normal del día');
    }

    public function testReLimpiarValidaLaRevisionYLaTrabajadora(): void
    {
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $si = $this->svc->registrar($this->hab['101'], 'si', null, null, null, $this->carla)['revision']['id'];
        $this->esperarError('RELIMPIEZA_NO_APLICA', fn () => $this->svc->pedirRelimpieza($si, $this->berta, true, $this->sofia));

        $sucia = $this->no('102'); // la 102 está sucia: nadie la aprobó, no hay re-limpieza que pedir
        $this->esperarError('RELIMPIEZA_NO_APLICA', fn () => $this->svc->pedirRelimpieza($sucia, $this->berta, true, $this->sofia));

        $rev = $this->no('101');
        $this->esperarError('TRABAJADOR_NO_ENCONTRADO', fn () => $this->svc->pedirRelimpieza($rev, 99999, true, $this->sofia));
        $this->esperarError('REVISION_NO_ENCONTRADA', fn () => $this->svc->pedirRelimpieza(99999, $this->berta, true, $this->sofia));

        Database::execute("UPDATE revisiones_entrega SET created_at = ? WHERE id = ?", [gmdate('Y-m-d\TH:i:s.000\Z', time() - 60), $rev]);
        (new HabitacionService())->cambiarEstado($this->hab['101'], Habitacion::ESTADO_SUCIA, null, 'cron');
        $this->esperarError('REVISION_NO_VIGENTE', fn () => $this->svc->pedirRelimpieza($rev, $this->berta, true, $this->sofia));
        $this->assertSame(0, (int) Database::fetchColumn("SELECT COUNT(*) FROM audit_log WHERE accion = 'revision_entrega.pedir_relimpieza'"));
    }

    /** Columna «Recepción» de Reportes: una pieza por aprobación; el NO manda; el cierre automático no cuenta. */
    public function testLaColumnaRecepcionCuentaPorSupervisora(): void
    {
        foreach (['101', '102'] as $n) {
            $this->limpiar($n, $this->ana);
            $this->aprobar($n);
        }
        $this->svc->registrar($this->hab['101'], 'si', null, null, null, $this->carla);
        $this->no('102');
        $this->svc->registrar($this->hab['102'], 'si', null, null, null, $this->carla); // el NO manda

        $rep = new ReportesService();
        $sofia = $rep->fichaKpis($this->hoy, $this->hoy, 'ambos')['supervisoras']['inspectoras'][0];
        $this->assertSame([1, 1], [$sofia['recepcion_aprobadas'], $sofia['recepcion_rechazadas']]);
        $mes = array_values(array_filter(
            $rep->resumenMensualAuditores((int) date('Y'), (int) date('n'), 'ambos'),
            fn (array $a): bool => $a['usuario_id'] === $this->sofia
        ))[0];
        $this->assertSame([1, 1], [$mes['recepcion_aprobadas'], $mes['recepcion_rechazadas']]);

        // Si en el período no inspeccionó nada pero Recepción revisó lo que aprobó antes, igual aparece.
        Database::execute('UPDATE auditorias SET created_at = ?', [gmdate('Y-m-d\TH:i:s.000\Z', (int) strtotime('-3 days'))]);
        $inspectoras = $rep->fichaKpis($this->hoy, $this->hoy, 'ambos')['supervisoras']['inspectoras'];
        $this->assertCount(1, $inspectoras);
        $this->assertSame([0, 1, 1], [$inspectoras[0]['total'], $inspectoras[0]['recepcion_aprobadas'], $inspectoras[0]['recepcion_rechazadas']]);
    }
}
