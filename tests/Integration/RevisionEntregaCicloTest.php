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
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Services\RevisionEntregaService;
use Atankalama\Limpieza\Tests\Support\FakeHttpTransport;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Lo que un NO de la revisión de entrega le hace (o no) a la pieza, a Cloudbeds y a los indicadores.
 *
 * Interruptor apagado (default): nada — ni estado, ni Cloudbeds, ni inspección, ni KPIs.
 * Interruptor prendido: solo una pieza APROBADA vuelve a sucia, con su 'dirty' a Cloudbeds, como
 * «Marcar sucia» a mano. Nunca escribe auditorias.
 */
final class RevisionEntregaCicloTest extends TestCase
{
    private FakeHttpTransport $cb;
    private RevisionEntregaService $svc;
    private int $ana;
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
        foreach (['101', '102'] as $numero) {
            Database::execute(
                "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, cloudbeds_room_id, estado, es_espacio_comun) VALUES (?, ?, ?, ?, 'sucia', 0)",
                [$hotel, $numero, $tipo, 'CB_' . $numero]
            );
            $this->hab[$numero] = Database::lastInsertId();
        }
        [$this->ana]   = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        [$this->sofia] = TestDatabase::crearUsuario('22222222-2', 'Sofía', 'Supervisora');
        [$this->carla] = TestDatabase::crearUsuario('33333333-3', 'Carla', 'Recepción');
        Database::execute("INSERT INTO motivos_revision_entrega (nombre) VALUES ('Baño sucio')");
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
        $this->svc = new RevisionEntregaService(cloudbeds: $sync);
    }

    /** Ana limpia la pieza y Sofía la aprueba en inspección (sin Cloudbeds). */
    private function limpiarYAprobar(string $numero): void
    {
        (new AsignacionService())->asignarManual($this->hab[$numero], $this->ana, $this->hoy);
        $chk = new ChecklistService();
        $e = $chk->iniciarEjecucion($this->hab[$numero], $this->ana, $this->hoy);
        foreach ($chk->itemsDelTemplate($e->templateId) as $it) {
            if ((int) $it['obligatorio'] === 1) {
                $chk->marcarItem($e->id, (int) $it['id'], true, $this->ana);
            }
        }
        $chk->completar($e->id, $this->ana);
        (new AuditoriaService())->emitirVeredicto($this->hab[$numero], $this->sofia, Auditoria::VEREDICTO_APROBADO);
    }

    private function estado(string $numero): string
    {
        return (string) Database::fetchColumn('SELECT estado FROM habitaciones WHERE id = ?', [$this->hab[$numero]]);
    }

    private function auditorias(): int
    {
        return (int) Database::fetchColumn('SELECT COUNT(*) FROM auditorias');
    }

    /** @return array<string, mixed> */
    private function indicadores(): array
    {
        $rep = new ReportesService();
        return [
            'kpis' => $rep->kpis($this->hoy, $this->hoy, 'ambos'),
            'ficha' => $rep->fichaKpis($this->hoy, $this->hoy, 'ambos'),
            'mensual' => $rep->resumenMensual((int) date('Y'), (int) date('n'), 'ambos'),
        ];
    }

    public function testConElInterruptorApagadoUnNoNoTocaNada(): void
    {
        $this->limpiarYAprobar('101');
        $antes = $this->indicadores();
        $this->cb->peticiones = [];

        $r = $this->svc->registrar($this->hab['101'], 'no', $this->motivo, 'pelos en la tina', null, $this->carla);
        $this->svc->registrar($this->hab['101'], 'si', null, null, null, $this->carla);

        // Foto para el KPI de calidad de las supervisoras: qué limpieza y qué inspección se evaluó.
        $fila = Database::fetchOne(
            'SELECT r.estado_pieza, r.ejecucion_id, r.auditoria_id, a.auditor_id, ec.usuario_id AS limpio
               FROM revisiones_entrega r
               JOIN auditorias a ON a.id = r.auditoria_id
               JOIN ejecuciones_checklist ec ON ec.id = r.ejecucion_id
              WHERE r.id = ?',
            [$r['revision']['id']]
        );
        $this->assertNotNull($fila, 'la revisión queda vinculada a la limpieza y a su inspección');
        $this->assertSame('aprobada', $fila['estado_pieza']);
        $this->assertSame($this->sofia, (int) $fila['auditor_id'], 'la supervisora que aprobó');
        $this->assertSame($this->ana, (int) $fila['limpio'], 'y quién limpió');

        $this->assertFalse($r['revision']['paso_a_sucia']);
        $this->assertSame('aprobada', $this->estado('101'));
        $this->assertSame([], $this->cb->peticiones, 'ninguna escritura a Cloudbeds');
        $this->assertSame(1, $this->auditorias(), 'la inspección sigue siendo una sola');
        $this->assertSame(0, (int) Database::fetchColumn("SELECT COUNT(*) FROM cloudbeds_sync_historial WHERE tipo = 'escritura_estado'"));

        // KPIs de aseo e inspección iguales; lo único que cambia es la columna «Recepción» de la supervisora
        // que aprobó la pieza: el NO le cuenta a ella (un NO y un SÍ sobre la misma aprobación = no aprobada).
        $despues = $this->indicadores();
        $sofia = array_values(array_filter(
            $despues['ficha']['supervisoras']['inspectoras'],
            fn (array $i): bool => $i['usuario_id'] === $this->sofia
        ))[0] ?? [];
        $this->assertSame([0, 1], [$sofia['recepcion_aprobadas'] ?? null, $sofia['recepcion_rechazadas'] ?? null]);
        $this->assertEquals($this->sinRecepcion($antes), $this->sinRecepcion($despues), 'KPIs, ficha y resumen mensual iguales');
    }

    /**
     * @param array<string, mixed> $indicadores
     * @return array<string, mixed>
     */
    private function sinRecepcion(array $indicadores): array
    {
        foreach ($indicadores['ficha']['supervisoras']['inspectoras'] as &$i) {
            unset($i['recepcion_aprobadas'], $i['recepcion_rechazadas'], $i['recepcion_calidad_pct']);
        }
        unset($i);
        return $indicadores;
    }

    public function testConElInterruptorApagadoUnNoDejaIgualesLosSieteEstados(): void
    {
        foreach (Habitacion::ESTADOS_VALIDOS as $estado) {
            Database::execute('UPDATE habitaciones SET estado = ? WHERE id = ?', [$estado, $this->hab['102']]);
            $r = $this->svc->registrar($this->hab['102'], 'no', $this->motivo, null, null, $this->carla);
            $this->assertSame($estado, $this->estado('102'), "estado {$estado}");
            $this->assertSame($estado, $r['revision']['estado_pieza'], "guarda el estado {$estado} del momento");
            $this->assertFalse($r['revision']['paso_a_sucia']);
        }
        $this->assertSame([], $this->cb->peticiones);
        $this->assertNull(
            Database::fetchColumn('SELECT ejecucion_id FROM revisiones_entrega ORDER BY id DESC LIMIT 1'),
            'una pieza que nunca se limpió en la app no tiene limpieza ni inspección que vincular'
        );
    }

    public function testConElInterruptorPrendidoUnNoDevuelveLaPiezaAprobadaASuciaYAvisaACloudbeds(): void
    {
        $this->limpiarYAprobar('101');
        $this->svc->configurarNoEnsucia(true, $this->sofia);
        $this->cb->peticiones = [];
        $this->cb->encolarOk(200, ['success' => true]);

        $r = $this->svc->registrar($this->hab['101'], 'no', $this->motivo, null, null, $this->carla);

        $this->assertTrue($r['revision']['paso_a_sucia']);
        $this->assertSame('sucia', $this->estado('101'));
        $this->assertCount(1, $this->cb->peticiones, 'una sola escritura');
        $this->assertStringContainsString('/postHousekeepingStatus', $this->cb->peticiones[0]['url']);
        $this->assertSame('dirty', $this->cb->peticiones[0]['cuerpo']['roomCondition'] ?? null);
        $this->assertSame('CB_101', $this->cb->peticiones[0]['cuerpo']['roomID'] ?? null);

        $cambio = Database::fetchOne(
            "SELECT usuario_id, detalles_json FROM audit_log
              WHERE accion = 'habitacion.cambiar_estado' AND entidad_id = ? ORDER BY id DESC LIMIT 1",
            [$this->hab['101']]
        );
        $this->assertSame($this->carla, (int) $cambio['usuario_id']);
        $this->assertSame(['desde' => 'aprobada', 'hasta' => 'sucia'], json_decode((string) $cambio['detalles_json'], true));

        $cuerpo = (string) Database::fetchColumn('SELECT cuerpo FROM notificaciones WHERE usuario_id = ?', [$this->sofia]);
        $this->assertStringEndsWith('La pieza volvió a sucia y a la cola de Ana; puedes cambiarla con «Re-limpiar».', $cuerpo);
        // La limpió Ana hoy: la pieza vuelve sola a su cola y a ella se le avisa (antes no se enteraba).
        $aviso = Database::fetchOne("SELECT titulo, cuerpo FROM notificaciones WHERE usuario_id = ? AND tipo = 'asignacion' ORDER BY id DESC LIMIT 1", [$this->ana]);
        $this->assertSame('Hab. 101 de vuelta en tu cola', $aviso['titulo'] ?? null);
        $this->assertStringContainsString('Baño sucio', (string) ($aviso['cuerpo'] ?? ''));
        $this->assertSame('101', (new AsignacionService())->habitacionActualDeCola($this->ana, $this->hoy)['numero'] ?? null);
        $this->assertSame(1, $this->auditorias(), 'nunca escribe auditorias');
        $this->assertSame(
            'no',
            $this->svc->revisionesVigentes()[$this->hab['101']]['resultado'] ?? null,
            'el cambio a sucia que provocó el mismo NO no reinicia el botón'
        );

        // El SÍ nunca cambia el estado.
        $this->svc->registrar($this->hab['101'], 'si', null, null, null, $this->carla);
        $this->assertSame('sucia', $this->estado('101'));
        $this->assertCount(1, $this->cb->peticiones);
    }

    /** El NO que devolvió la pieza a sucia se ve en la tarjeta hasta que empiezan a limpiarla. */
    public function testElNoQueLaDevolvioASuciaSigueVigenteHastaQueEmpiezanALimpiar(): void
    {
        $this->limpiarYAprobar('101');
        $this->svc->configurarNoEnsucia(true, $this->sofia);
        $this->cb->encolarOk(200, ['success' => true]);
        $this->svc->registrar($this->hab['101'], 'no', $this->motivo, null, null, $this->carla);

        (new AsignacionService())->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $this->assertSame('no', $this->svc->revisionesVigentes()[$this->hab['101']]['resultado'] ?? null, 'asignarla no la cambia de estado: ya estaba sucia');

        (new ChecklistService())->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        $this->assertSame('en_progreso', $this->estado('101'));
        $this->assertArrayNotHasKey($this->hab['101'], $this->svc->revisionesVigentes(), 'empezaron a limpiarla: vuelve a «Inspección pre-entrega»');
    }

    /** El cambio a sucia y la revisión van juntos: si la revisión no se puede guardar, la pieza no cambia. */
    public function testSiLaRevisionNoSePuedeGuardarLaPiezaTampocoCambia(): void
    {
        Database::execute("UPDATE habitaciones SET estado = 'aprobada' WHERE id = ?", [$this->hab['101']]);
        $this->svc->configurarNoEnsucia(true, $this->sofia);
        Database::pdo()->exec('DROP TABLE revisiones_entrega');

        try {
            $this->svc->registrar($this->hab['101'], 'no', $this->motivo, null, null, $this->carla);
            $this->fail('sin la tabla, la revisión no se puede guardar');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('aprobada', $this->estado('101'), 'el cambio a sucia se deshizo junto con la revisión');
        $this->assertSame(0, (int) Database::fetchColumn("SELECT COUNT(*) FROM audit_log WHERE accion = 'habitacion.cambiar_estado'"));
        $this->assertSame([], $this->cb->peticiones, 'y a Cloudbeds no se le avisó nada');
    }

    public function testConElInterruptorPrendidoLasPiezasNoAprobadasNoCambian(): void
    {
        $this->svc->configurarNoEnsucia(true, $this->sofia);
        foreach ([
            Habitacion::ESTADO_SUCIA,
            Habitacion::ESTADO_EN_PROGRESO,
            Habitacion::ESTADO_COMPLETADA_PENDIENTE_AUDITORIA,
            Habitacion::ESTADO_RECHAZADA,
        ] as $estado) {
            Database::execute('UPDATE habitaciones SET estado = ? WHERE id = ?', [$estado, $this->hab['102']]);
            $r = $this->svc->registrar($this->hab['102'], 'no', $this->motivo, null, null, $this->carla);
            $this->assertSame($estado, $this->estado('102'), "estado {$estado}");
            $this->assertFalse($r['revision']['paso_a_sucia']);
        }
        $this->assertSame([], $this->cb->peticiones);
        foreach (Database::fetchAll('SELECT cuerpo FROM notificaciones') as $n) {
            $this->assertStringNotContainsString('volvió a sucia', $n['cuerpo']);
        }
    }

    public function testConElInterruptorPrendidoYCloudbedsCaidoLaPiezaIgualPasaASucia(): void
    {
        Database::execute("UPDATE habitaciones SET estado = 'aprobada' WHERE id = ?", [$this->hab['101']]);
        $this->svc->configurarNoEnsucia(true, $this->sofia);
        for ($i = 0; $i < 4; $i++) {
            $this->cb->encolarFallo(500);
        }

        $r = $this->svc->registrar($this->hab['101'], 'no', $this->motivo, null, null, $this->carla);

        $this->assertSame('sucia', $this->estado('101'));
        $this->assertTrue($r['revision']['paso_a_sucia']);
        $this->assertSame(1, (int) Database::fetchColumn("SELECT COUNT(*) FROM alertas_activas WHERE tipo = 'cloudbeds_sync_failed'"), 'la alerta P0 de siempre');
    }
}
