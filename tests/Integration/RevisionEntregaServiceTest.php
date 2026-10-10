<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\HabitacionService;
use Atankalama\Limpieza\Services\RevisionEntregaException;
use Atankalama\Limpieza\Services\RevisionEntregaService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Inspección pre-entrega (v7; en código «revision_entrega»): Recepción registra SÍ / NO antes de
 * entregar la pieza. Validaciones, aviso a supervisoras, idempotencia, revisión vigente (hasta que la pieza
 * cambia de estado), catálogo de motivos y reporte. Lo que toca Cloudbeds, el estado de la pieza y la foto para el KPI de supervisoras
 * está en RevisionEntregaCicloTest.
 */
final class RevisionEntregaServiceTest extends TestCase
{
    private RevisionEntregaService $svc;
    private int $sofia;
    private int $ana;
    private int $carla;
    private int $cron;
    private int $banoSucio;
    private int $viejo;
    /** @var array<string, int> */
    private array $hab = [];

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur'), ('inn', 'Inn')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        $sur = (int) Database::fetchColumn("SELECT id FROM hoteles WHERE codigo = '1_sur'");
        $inn = (int) Database::fetchColumn("SELECT id FROM hoteles WHERE codigo = 'inn'");
        $tipo = (int) Database::fetchColumn('SELECT id FROM tipos_habitacion LIMIT 1');
        foreach ([['101', $sur, 0, 1], ['102', $sur, 0, 1], ['201', $inn, 0, 1], ['LOBBY', $sur, 1, 1], ['999', $sur, 0, 0]] as [$num, $hotel, $comun, $activa]) {
            Database::execute(
                "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado, es_espacio_comun, activa) VALUES (?, ?, ?, 'aprobada', ?, ?)",
                [$hotel, $num, $tipo, $comun, $activa]
            );
            $this->hab[$num] = Database::lastInsertId();
        }
        [$this->sofia] = TestDatabase::crearUsuario('11111111-1', 'Sofía Pérez', 'Supervisora');
        [$this->ana]   = TestDatabase::crearUsuario('22222222-2', 'Ana', 'Trabajador');
        [$this->carla] = TestDatabase::crearUsuario('33333333-3', 'Carla Rojas', 'Recepción');
        [$this->cron]  = TestDatabase::crearUsuario('SISTEMA-CRON', 'Sistema', 'Admin');

        Database::execute("INSERT INTO motivos_revision_entrega (nombre, activo) VALUES ('Baño sucio', 1), ('Viejo', 0)");
        $this->banoSucio = (int) Database::fetchColumn("SELECT id FROM motivos_revision_entrega WHERE nombre = 'Baño sucio'");
        $this->viejo = (int) Database::fetchColumn("SELECT id FROM motivos_revision_entrega WHERE nombre = 'Viejo'");

        $this->svc = new RevisionEntregaService();
    }

    private function contar(string $tabla, string $where = '1 = 1', array $params = []): int
    {
        return (int) Database::fetchColumn("SELECT COUNT(*) FROM {$tabla} WHERE {$where}", $params);
    }

    private function esperarError(string $codigo, int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail("se esperaba {$codigo}");
        } catch (RevisionEntregaException $e) {
            $this->assertSame($codigo, $e->codigo);
            $this->assertSame($status, $e->httpStatus);
        }
    }

    public function testUnSiQuedaRegistradoSinMotivoNiAviso(): void
    {
        $r = $this->svc->registrar($this->hab['101'], 'si', $this->banoSucio, 'ignorado', 'revision-entrega/2026/10/0123456789abcdef.webp', $this->carla);

        $this->assertFalse($r['repetida']);
        $rev = $r['revision'];
        $this->assertSame('si', $rev['resultado']);
        $this->assertNull($rev['motivo_id'], 'el SÍ no lleva motivo aunque llegue');
        $this->assertNull($rev['comentario']);
        $this->assertNull($rev['foto_url']);
        $this->assertFalse($rev['paso_a_sucia']);
        $this->assertSame('aprobada', $rev['estado_pieza'], 'guarda el estado de la pieza al momento');
        $this->assertSame('Carla Rojas', $rev['usuario_nombre']);
        $this->assertSame('aprobada', Database::fetchColumn('SELECT estado FROM habitaciones WHERE id = ?', [$this->hab['101']]));
        $this->assertSame(0, $this->contar('notificaciones'));
        $this->assertSame(1, $this->contar(
            'audit_log',
            "accion = 'revision_entrega.registrar' AND entidad = 'habitacion' AND entidad_id = ?",
            [$this->hab['101']]
        ));
    }

    public function testUnNoExigeUnMotivoActivoYValidaTodo(): void
    {
        $this->esperarError('MOTIVO_REQUERIDO', 400, fn() => $this->svc->registrar($this->hab['101'], 'no', null, null, null, $this->carla));
        $this->esperarError('MOTIVO_NO_ENCONTRADO', 404, fn() => $this->svc->registrar($this->hab['101'], 'no', $this->viejo, null, null, $this->carla));
        $this->esperarError('RESULTADO_INVALIDO', 400, fn() => $this->svc->registrar($this->hab['101'], 'quizas', null, null, null, $this->carla));
        $this->esperarError('COMENTARIO_LARGO', 400, fn() => $this->svc->registrar($this->hab['101'], 'no', $this->banoSucio, str_repeat('ñ', 301), null, $this->carla));
        foreach (['LOBBY', '999'] as $num) {
            $this->esperarError('HABITACION_NO_ENCONTRADA', 404, fn() => $this->svc->registrar($this->hab[$num], 'si', null, null, null, $this->carla));
        }
        $this->esperarError('HABITACION_NO_ENCONTRADA', 404, fn() => $this->svc->registrar(99999, 'si', null, null, null, $this->carla));

        $this->assertSame(0, $this->contar('revisiones_entrega'), 'nada inválido se guarda');
    }

    public function testUnNoAvisaSoloAQuienRecibeLasAlertas(): void
    {
        $r = $this->svc->registrar(
            $this->hab['101'], 'no', $this->banoSucio, '  la tina quedó con pelos  ',
            'revision-entrega/2026/10/0123456789abcdef.webp', $this->carla
        );

        $this->assertSame('la tina quedó con pelos', $r['revision']['comentario']);
        $this->assertSame('/uploads/revision-entrega/2026/10/0123456789abcdef.webp', $r['revision']['foto_url']);

        $notifs = Database::fetchAll('SELECT * FROM notificaciones');
        $this->assertCount(1, $notifs, 'solo Sofía: Ana, Carla y el usuario del cron no reciben');
        $n = $notifs[0];
        $this->assertSame($this->sofia, (int) $n['usuario_id']);
        $this->assertSame('revision_entrega_no', $n['tipo']);
        $this->assertSame('Hab. 101 (Atankalama): pre-entrega no aprobada', $n['titulo']);
        $this->assertStringStartsWith('Baño sucio: «la tina quedó con pelos». Revisó Carla a las ', $n['cuerpo']);
        $this->assertStringContainsString('. Hay foto.', $n['cuerpo']);
        $this->assertStringEndsWith('Si hay que rehacerla, usa «Re-limpiar» en la pieza.', $n['cuerpo'], 'la pieza estaba aprobada: se puede re-limpiar');
        $this->assertStringNotContainsString('sucia', mb_strtolower(str_replace('Baño sucio', '', $n['cuerpo'])), 'con el interruptor apagado no dice que volvió a sucia');
        $this->assertStringEndsWith('/habitaciones/' . $this->hab['101'], $n['url']);
    }

    public function testLaMismaClaveNoDuplicaNiReAvisa(): void
    {
        $a = $this->svc->registrar($this->hab['101'], 'no', $this->banoSucio, null, null, $this->carla, 'clave-1');
        $b = $this->svc->registrar($this->hab['101'], 'no', $this->banoSucio, null, null, $this->carla, 'clave-1');

        $this->assertFalse($a['repetida']);
        $this->assertTrue($b['repetida']);
        $this->assertSame($a['revision']['id'], $b['revision']['id']);
        $this->assertSame(1, $this->contar('revisiones_entrega'));
        $this->assertSame(1, $this->contar('notificaciones'));
        $this->assertSame(1, $this->contar('audit_log', "accion = 'revision_entrega.registrar'"));

        $this->svc->registrar($this->hab['101'], 'si', null, null, null, $this->carla, 'clave-2');
        $this->svc->registrar($this->hab['101'], 'si', null, null, null, $this->carla, str_repeat('x', 65));
        $this->assertSame(3, $this->contar('revisiones_entrega'), 'clave distinta o demasiado larga (se ignora) = fila nueva');

        $this->expectException(\PDOException::class);
        Database::execute(
            "INSERT INTO revisiones_entrega (habitacion_id, usuario_id, resultado, estado_pieza, idempotency_key) VALUES (?, ?, 'si', 'aprobada', 'clave-2')",
            [$this->hab['102'], $this->carla]
        );
    }

    /**
     * Revisión de la v7: una clave que quedó pegada de un envío viejo no puede «tragarse» una revisión
     * posterior. Solo es reintento si es la misma pieza, la misma respuesta y sigue siendo la última.
     */
    public function testUnaClaveViejaNoSeTragaUnaRevisionNueva(): void
    {
        $si = $this->svc->registrar($this->hab['101'], 'si', null, null, null, $this->carla, 'k-vieja');
        $this->svc->registrar($this->hab['101'], 'no', $this->banoSucio, null, null, $this->carla, 'k-no');

        // Ya hay una revisión más nueva (el NO): la clave del SÍ viejo no es un reintento.
        $otroSi = $this->svc->registrar($this->hab['101'], 'si', null, null, null, $this->carla, 'k-vieja');
        $this->assertFalse($otroSi['repetida']);
        $this->assertNotSame($si['revision']['id'], $otroSi['revision']['id']);
        $this->assertSame('si', $this->svc->revisionesVigentes()[$this->hab['101']]['resultado'], 'el SÍ nuevo quedó como la última');

        // Misma clave pero otra respuesta u otra pieza: tampoco es reintento.
        $otraPieza = $this->svc->registrar($this->hab['102'], 'si', null, null, null, $this->carla, 'k-no');
        $this->assertFalse($otraPieza['repetida']);
        $this->assertSame(4, $this->contar('revisiones_entrega'));
        $this->assertNull($this->svc->reintentoVigente('k-no', $this->hab['101'], 'si'));
    }

    public function testLasObservacionesCuentanCadaSaltoDeLineaComoUno(): void
    {
        // 150 «a» separadas por CRLF: 299 caracteres si cada salto cuenta como 1, 448 si cuenta como 2.
        $texto = rtrim(str_repeat("a\r\n", 150));
        $r = $this->svc->registrar($this->hab['101'], 'no', $this->banoSucio, $texto, null, $this->carla);

        $this->assertStringNotContainsString("\r", (string) $r['revision']['comentario'], 'se guarda con saltos simples');
        $this->assertSame(mb_strlen(str_replace("\r\n", "\n", $texto)), mb_strlen((string) $r['revision']['comentario']));
    }

    public function testElFormularioTraeLosMotivosActivosYElInterruptor(): void
    {
        $f = $this->svc->formulario();
        $this->assertSame(['Baño sucio'], array_column($f['motivos'], 'nombre'));
        $this->assertFalse($f['no_ensucia']);

        $this->svc->configurarNoEnsucia(true, $this->sofia);
        $this->assertTrue($this->svc->formulario()['no_ensucia']);
    }

    /** Lleva la revisión hacia atrás en el tiempo, para que lo que pase después sea claramente posterior. */
    private function retrasar(int $revisionId, string $cuanto): void
    {
        Database::execute(
            'UPDATE revisiones_entrega SET created_at = ? WHERE id = ?',
            [gmdate('Y-m-d\TH:i:s.000\Z', (int) strtotime($cuanto)), $revisionId]
        );
    }

    /**
     * Decisión de Nicolás (05/10/2026): el botón de la tarjeta se reinicia cuando la pieza cambia de
     * estado, no a medianoche. Mientras no cambie, manda la última revisión, aunque sea de ayer.
     */
    public function testLaVigenteEsLaUltimaYNoSeReiniciaAMedianoche(): void
    {
        $this->svc->registrar($this->hab['101'], 'no', $this->banoSucio, null, null, $this->carla);
        $this->svc->registrar($this->hab['101'], 'si', null, null, null, $this->carla);
        $ayer = $this->svc->registrar($this->hab['102'], 'no', $this->banoSucio, null, null, $this->carla);
        $this->retrasar($ayer['revision']['id'], '-1 day');

        $vigentes = $this->svc->revisionesVigentes();
        $this->assertSame('si', $vigentes[$this->hab['101']]['resultado'] ?? null, 'la última manda');
        $this->assertSame('no', $vigentes[$this->hab['102']]['resultado'] ?? null, 'la de ayer sigue: la pieza no cambió de estado');
        $this->assertSame(date('Y-m-d', (int) strtotime('-1 day')), $vigentes[$this->hab['102']]['fecha_local'], 'con su fecha, para mostrarla');
        $this->assertArrayNotHasKey($this->hab['201'], $vigentes, 'sin revisar = sin resultado');
    }

    public function testUnCambioDeEstadoReiniciaLaInspeccion(): void
    {
        $habs = new HabitacionService();
        $si = $this->svc->registrar($this->hab['101'], 'si', null, null, null, $this->carla);
        $this->retrasar($si['revision']['id'], '-1 minute');

        $habs->cambiarEstado($this->hab['101'], Habitacion::ESTADO_SUCIA, $this->sofia);
        $this->assertArrayNotHasKey($this->hab['101'], $this->svc->revisionesVigentes(), 'se ensució: el botón vuelve a «Inspección pre-entrega»');
        $this->assertSame('si', $this->svc->ultimaDePieza($this->hab['101'])['resultado'] ?? null, 'el detalle conserva la última');

        // Una revisión nueva (sobre la pieza sucia) vale hasta el próximo cambio.
        $no = $this->svc->registrar($this->hab['101'], 'no', $this->banoSucio, null, null, $this->carla);
        $this->assertSame('no', $this->svc->revisionesVigentes()[$this->hab['101']]['resultado'] ?? null);
        $this->retrasar($no['revision']['id'], '-1 minute');
        $habs->cambiarEstado($this->hab['101'], Habitacion::ESTADO_EN_PROGRESO, $this->ana);
        $this->assertArrayNotHasKey($this->hab['101'], $this->svc->revisionesVigentes(), 'empezaron a limpiarla');
    }

    public function testUnaIdaYVueltaDeEstadoTambienReinicia(): void
    {
        $habs = new HabitacionService();
        $si = $this->svc->registrar($this->hab['101'], 'si', null, null, null, $this->carla);
        $this->retrasar($si['revision']['id'], '-1 minute');

        // aprobada → sucia → aprobada (p. ej. el sync la ensucia y después la devuelve a aprobada):
        // queda en el mismo estado que al revisarla, pero entremedio cambió.
        $habs->cambiarEstado($this->hab['101'], Habitacion::ESTADO_SUCIA, null, 'cron');
        $habs->cambiarEstado($this->hab['101'], Habitacion::ESTADO_APROBADA, null, 'cron', forzar: true);

        $this->assertSame('aprobada', Database::fetchColumn('SELECT estado FROM habitaciones WHERE id = ?', [$this->hab['101']]));
        $this->assertArrayNotHasKey($this->hab['101'], $this->svc->revisionesVigentes());
    }

    /**
     * La (re)asignación pone la pieza en sucia con un UPDATE directo, sin audit_log (AsignacionService):
     * igual cuenta como cambio de estado.
     */
    public function testLaReasignacionQueEnsuciaLaPiezaTambienReinicia(): void
    {
        $this->svc->registrar($this->hab['101'], 'no', $this->banoSucio, null, null, $this->carla);
        $this->assertArrayHasKey($this->hab['101'], $this->svc->revisionesVigentes());

        (new AsignacionService())->asignarManual($this->hab['101'], $this->ana, date('Y-m-d'), $this->sofia);

        $this->assertSame('sucia', Database::fetchColumn('SELECT estado FROM habitaciones WHERE id = ?', [$this->hab['101']]));
        $this->assertArrayNotHasKey($this->hab['101'], $this->svc->revisionesVigentes());
    }

    public function testMotivosSeCreanRenombranYDesactivanPeroNoSeBorran(): void
    {
        $this->esperarError('MOTIVO_DUPLICADO', 409, fn() => $this->svc->crearMotivo('BAÑO SUCIO', $this->sofia));
        $this->esperarError('NOMBRE_INVALIDO', 400, fn() => $this->svc->crearMotivo(' x ', $this->sofia));
        $this->esperarError('NOMBRE_INVALIDO', 400, fn() => $this->svc->crearMotivo(str_repeat('a', 61), $this->sofia));
        $this->esperarError('MOTIVO_NO_ENCONTRADO', 404, fn() => $this->svc->actualizarMotivo(999, ['activo' => false], $this->sofia));

        $id = $this->svc->crearMotivo('  Mal olor ', $this->sofia);
        $this->esperarError('MOTIVO_DUPLICADO', 409, fn() => $this->svc->actualizarMotivo($id, ['nombre' => 'baño sucio'], $this->sofia));
        $this->svc->actualizarMotivo($id, ['nombre' => 'Olor a humo'], $this->sofia);
        $this->svc->actualizarMotivo($id, ['activo' => false], $this->sofia);

        $this->assertNotContains('Olor a humo', array_column($this->svc->listarMotivos(true), 'nombre'));
        $todos = array_column($this->svc->listarMotivos(false), 'activo', 'nombre');
        $this->assertSame(['Baño sucio' => true, 'Olor a humo' => false, 'Viejo' => false], $todos);

        $this->assertFalse(method_exists($this->svc, 'eliminarMotivo'), 'no hay borrado de motivos');
        $this->svc->registrar($this->hab['101'], 'no', $this->banoSucio, null, null, $this->carla);
        try {
            Database::execute('DELETE FROM motivos_revision_entrega WHERE id = ?', [$this->banoSucio]);
            $this->fail('la FK RESTRICT protege el historial');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(1, $this->contar('audit_log', "accion = 'revision_entrega.motivo_crear'"));
        $this->assertSame(2, $this->contar('audit_log', "accion = 'revision_entrega.motivo_actualizar'"));
    }

    public function testElReporteCuentaPorMotivoYFiltraPorHotelYFechas(): void
    {
        $olor = $this->svc->crearMotivo('Mal olor', $this->sofia);
        $this->svc->registrar($this->hab['101'], 'no', $this->banoSucio, 'pelos', null, $this->carla);
        $this->svc->registrar($this->hab['102'], 'no', $this->banoSucio, null, null, $this->carla);
        $this->svc->registrar($this->hab['102'], 'si', null, null, null, $this->carla);
        $this->svc->registrar($this->hab['201'], 'no', $olor, null, null, $this->carla);
        $vieja = $this->svc->registrar($this->hab['101'], 'si', null, null, null, $this->carla);
        Database::execute('UPDATE revisiones_entrega SET created_at = ? WHERE id = ?', ['2026-01-15T15:00:00.000Z', $vieja['revision']['id']]);

        $hoy = date('Y-m-d');
        $sur = $this->svc->reporte($hoy, $hoy, '1_sur');
        $this->assertSame(['total' => 3, 'si' => 1, 'no' => 2, 'pct_no' => 66.7, 'a_sucia' => 0], $sur['resumen']);
        $this->assertSame([['motivo_id' => $this->banoSucio, 'nombre' => 'Baño sucio', 'activo' => true, 'cantidad' => 2, 'pct' => 100.0]], $sur['por_motivo']);
        $this->assertSame(['102', '102', '101'], array_column($sur['historial'], 'numero'), 'más nueva primero');
        $this->assertFalse($sur['truncado']);

        $ambos = $this->svc->reporte($hoy, $hoy, 'ambos');
        $this->assertSame(4, $ambos['resumen']['total'], 'la de enero queda fuera del rango');
        $this->assertSame(['Baño sucio', 'Mal olor'], array_column($ambos['por_motivo'], 'nombre'));

        $vacio = $this->svc->reporte('2025-01-01', '2025-01-02', 'ambos');
        $this->assertSame(['total' => 0, 'si' => 0, 'no' => 0, 'pct_no' => null, 'a_sucia' => 0], $vacio['resumen']);
    }

    public function testElHistorialDelReporteTieneTope(): void
    {
        $ahora = gmdate('Y-m-d\TH:i:s.000\Z');
        for ($i = 0; $i < RevisionEntregaService::MAX_HISTORIAL + 1; $i++) {
            Database::execute(
                "INSERT INTO revisiones_entrega (habitacion_id, usuario_id, resultado, estado_pieza, created_at) VALUES (?, ?, 'si', 'aprobada', ?)",
                [$this->hab['101'], $this->carla, $ahora]
            );
        }
        $r = $this->svc->reporte(date('Y-m-d'), date('Y-m-d'), 'ambos');
        $this->assertTrue($r['truncado']);
        $this->assertCount(RevisionEntregaService::MAX_HISTORIAL, $r['historial']);
        $this->assertSame(RevisionEntregaService::MAX_HISTORIAL + 1, $r['resumen']['total'], 'los conteos no se truncan');
    }

    public function testElInterruptorArrancaApagadoYSeGuarda(): void
    {
        $this->assertFalse($this->svc->noEnsucia());
        $this->svc->configurarNoEnsucia(true, $this->sofia);
        $this->assertTrue($this->svc->noEnsucia());
        $fila = Database::fetchOne("SELECT valor, updated_by FROM alertas_config WHERE clave = 'revision_entrega_no_ensucia'");
        $this->assertSame(['valor' => '1', 'updated_by' => $this->sofia], ['valor' => $fila['valor'], 'updated_by' => (int) $fila['updated_by']]);
        $this->svc->configurarNoEnsucia(false, $this->sofia);
        $this->assertFalse($this->svc->noEnsucia());
        $this->assertSame(2, $this->contar('audit_log', "accion = 'revision_entrega.config_actualizar'"));
    }

    /**
     * Va al final: rompe la tabla de notificaciones (PushService es final, no se puede stubear).
     * La BD se recrea en el setUp del test siguiente.
     */
    public function testUnAvisoQueFallaNoPierdeLaRevision(): void
    {
        Database::pdo()->exec('DROP TABLE notificaciones');

        $r = $this->svc->registrar($this->hab['101'], 'no', $this->banoSucio, null, null, $this->carla);

        $this->assertSame(1, $this->contar('revisiones_entrega', 'id = ?', [$r['revision']['id']]));
        $this->assertSame(1, $this->contar('logs_eventos', "nivel = 'ERROR' AND modulo = 'revision_entrega'"));
    }
}
