<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\AlertasController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Kernel;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Models\AlertaActiva;
use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Services\AlertasPredictivasService;
use Atankalama\Limpieza\Services\AlertasService;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuthService;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\CloudbedsSyncService;
use Atankalama\Limpieza\Services\HabitacionService;
use Atankalama\Limpieza\Services\HomeService;
use Atankalama\Limpieza\Services\UsuarioService;
use Atankalama\Limpieza\Tests\Support\FakeHttpTransport;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tanda 3 de la auditoría de código (07/10/2026): ciclo de vida de las alertas.
 * Sin botón «Descartar», toda alerta tiene que cerrarse sola cuando la condición desaparece.
 */
final class AlertasCicloVidaTest extends TestCase
{
    private AlertasService $alertas;
    private int $hotelId;
    private int $tipoId;
    private int $anaId;
    private int $supId;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre, cloudbeds_property_id) VALUES ('1_sur', '1 Sur', 'CB_1SUR')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        $this->hotelId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $this->tipoId = (int) Database::fetchOne('SELECT id FROM tipos_habitacion LIMIT 1')['id'];
        [$this->anaId] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        [$this->supId] = TestDatabase::crearUsuario('22222222-2', 'Sofía', 'Supervisora');
        $this->alertas = new AlertasService();
    }

    // --- Dedupe ----------------------------------------------------------------------------

    public function testLevantarDeNuevoActualizaElTextoDeLaAlerta(): void
    {
        $this->alertas->levantar(AlertaActiva::TIPO_TRABAJADOR_EN_RIESGO, 'Ana podría no alcanzar', 'Le quedan 6 habitaciones.', ['usuario_id' => $this->anaId], null, 'trabajador:1:fecha:2026-10-07');
        $this->alertas->levantar(AlertaActiva::TIPO_TRABAJADOR_EN_RIESGO, 'Ana podría no alcanzar', 'Le quedan 2 habitaciones.', ['usuario_id' => $this->anaId], null, 'trabajador:1:fecha:2026-10-07');

        $filas = Database::fetchAll("SELECT descripcion, contexto_json FROM alertas_activas WHERE tipo = 'trabajador_en_riesgo'");
        $this->assertCount(1, $filas, 'no se duplica');
        $this->assertSame('Le quedan 2 habitaciones.', $filas[0]['descripcion']);
        $this->assertStringContainsString('"_dedupe":"trabajador:1:fecha:2026-10-07"', $filas[0]['contexto_json'], 'conserva su dedupe');
    }

    // --- Predictivas -----------------------------------------------------------------------

    public function testRecalcularCierraLasAlertasDeDiasAnteriores(): void
    {
        $this->alertas->levantar(AlertaActiva::TIPO_TRABAJADOR_EN_RIESGO, 'Ana podría no alcanzar', 'x', ['usuario_id' => $this->anaId], null, "trabajador:{$this->anaId}:fecha:2026-10-06");
        $this->alertas->levantar(AlertaActiva::TIPO_FIN_TURNO_PENDIENTES, 'Fin de turno', 'x', ['usuario_id' => $this->anaId], null, "trabajador:{$this->anaId}:fecha:2026-10-06");
        $this->alertas->levantar(AlertaActiva::TIPO_TRABAJADOR_DISPONIBLE, 'Ana está disponible', 'x', ['usuario_id' => $this->anaId], null, HomeService::dedupeDisponible($this->anaId, '2026-10-06'));

        (new AlertasPredictivasService())->recalcularTodos('2026-10-07', '10:00');

        $this->assertSame(0, (int) Database::fetchColumn('SELECT COUNT(*) FROM alertas_activas'));
        $this->assertSame(3, (int) Database::fetchColumn("SELECT COUNT(*) FROM bitacora_alertas WHERE resolucion = 'auto'"));
    }

    public function testRecalcularCierraLaDeHoyDeQuienYaNoTieneTurno(): void
    {
        $this->alertas->levantar(AlertaActiva::TIPO_TRABAJADOR_EN_RIESGO, 'Ana podría no alcanzar', 'x', ['usuario_id' => $this->anaId], null, "trabajador:{$this->anaId}:fecha:2026-10-07");

        (new AlertasPredictivasService())->recalcularTodos('2026-10-07', '10:00');

        $this->assertSame(0, (int) Database::fetchColumn('SELECT COUNT(*) FROM alertas_activas'));
    }

    public function testRecalcularDeUnaFechaPasadaNoCierraLasDeHoy(): void
    {
        $this->alertas->levantar(AlertaActiva::TIPO_TRABAJADOR_DISPONIBLE, 'Ana está disponible', 'x', ['usuario_id' => $this->anaId], null, HomeService::dedupeDisponible($this->anaId, '2026-10-07'));

        (new AlertasPredictivasService())->recalcularTodos('2026-10-06', '10:00');

        $this->assertSame(1, (int) Database::fetchColumn('SELECT COUNT(*) FROM alertas_activas'));
    }

    public function testConElTurnoTerminadoDejaDeEstarEnRiesgo(): void
    {
        $this->turnoHoy($this->anaId, '08:00', '16:00', '2026-10-07');
        $h = $this->crearPieza('101', 'sucia');
        (new AsignacionService())->asignarManual($h, $this->anaId, '2026-10-07');
        $svc = new AlertasPredictivasService();

        $svc->evaluarTrabajador($this->anaId, '2026-10-07', '16:00', '15:55');
        $this->assertSame(1, (int) Database::fetchColumn("SELECT COUNT(*) FROM alertas_activas WHERE tipo = 'trabajador_en_riesgo'"));

        // 16:30: el turno ya terminó. Antes el tiempo restante quedaba negativo y seguía «en riesgo».
        $svc->evaluarTrabajador($this->anaId, '2026-10-07', '16:00', '16:30');
        $this->assertSame(0, (int) Database::fetchColumn("SELECT COUNT(*) FROM alertas_activas WHERE tipo IN ('trabajador_en_riesgo', 'fin_turno_pendientes')"));
    }

    // --- Habitación saltada ----------------------------------------------------------------

    public function testLaSaltadaSeCierraSiLaPiezaSeApruebaPorOtroCamino(): void
    {
        $h = $this->crearPieza('101', 'sucia');
        $this->alertas->levantar(AlertaActiva::TIPO_HABITACION_SALTADA, 'Habitación 101 saltada', 'x', ['habitacion_id' => $h], $this->hotelId, "saltada:{$h}");

        // «Cliente no desea aseo» / Cloudbeds: aprobada sin pasar por el checklist.
        (new HabitacionService())->cambiarEstado($h, Habitacion::ESTADO_APROBADA, $this->supId, 'ui', forzar: true);

        $this->assertSame(0, (int) Database::fetchColumn("SELECT COUNT(*) FROM alertas_activas WHERE tipo = 'habitacion_saltada'"));
    }

    // --- Trabajador disponible -------------------------------------------------------------

    public function testElAvisoDeDisponibleLlegaALaSupervisoraYSeCierraAlAsignarle(): void
    {
        $hoy = date('Y-m-d');
        $home = new HomeService();
        $this->assertTrue($home->registrarAvisoDisponibilidad($this->anaId, $hoy));

        $alerta = Database::fetchOne("SELECT titulo FROM alertas_activas WHERE tipo = 'trabajador_disponible'");
        $this->assertNotNull($alerta, 'antes no se levantaba ninguna alerta');
        $this->assertSame('Ana está disponible', $alerta['titulo']);

        (new AsignacionService())->asignarManual($this->crearPieza('101', 'sucia'), $this->anaId, $hoy, $this->supId);

        $this->assertSame(0, (int) Database::fetchColumn("SELECT COUNT(*) FROM alertas_activas WHERE tipo = 'trabajador_disponible'"));
    }

    public function testAvisarDisponibleExigeElPermiso(): void
    {
        $router = Kernel::construirRouter();
        $sinPermiso = $router->despachar(new Request(
            metodo: 'POST', path: '/api/disponibilidad/avisar', cookies: [AuthService::SESSION_COOKIE => $this->login('22222222-2')]
        ));
        $this->assertSame(403, $sinPermiso->status, 'la Supervisora no tiene disponibilidad.notificar_supervisora');

        $conPermiso = $router->despachar(new Request(
            metodo: 'POST', path: '/api/disponibilidad/avisar', cookies: [AuthService::SESSION_COOKIE => $this->login('11111111-1')]
        ));
        $this->assertSame(200, $conPermiso->status);
    }

    // --- Sin «Descartar» -------------------------------------------------------------------

    public function testLasAlertasNoSeDescartanNiSeBorranConReintentar(): void
    {
        $p0 = $this->alertas->levantar(AlertaActiva::TIPO_CLOUDBEDS_SYNC_FAILED, 'Sincronización Cloudbeds falló', 'x', [], null, 'cloudbeds_sync');
        $ticket = $this->alertas->levantar(AlertaActiva::TIPO_TICKET_NUEVO, 'Ticket', 'x', [], null, 'ticket:1');

        foreach (['descartar', 'cloudbeds_retry'] as $accion) {
            $resp = $this->accion($p0->id, $accion);
            $this->assertSame(400, $resp->status, $accion);
            $this->assertSame('ACCION_NO_PERMITIDA', json_decode($resp->cuerpo, true)['error']['codigo']);
        }
        $this->assertNotNull(Database::fetchOne('SELECT 1 FROM alertas_activas WHERE id = ?', [$p0->id]), 'la P0 sigue ahí');

        // Las acciones reales siguen resolviendo.
        $this->assertSame(200, $this->accion($ticket->id, 'marcar_atendido')->status);
        $this->assertNull(Database::fetchOne('SELECT 1 FROM alertas_activas WHERE id = ?', [$ticket->id]));
    }

    // --- P0 de Cloudbeds -------------------------------------------------------------------

    public function testLaP0SeLevantaConSyncParcialYSeCierraConUnSyncCompleto(): void
    {
        $this->crearPieza('101', 'aprobada', 'CB_R101');
        // Segundo hotel mal configurado: falla mientras el primero sí actualiza piezas → 'parcial'.
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('inn', 'Inn')");
        $transport = new FakeHttpTransport();
        $sync = new CloudbedsSyncService(new CloudbedsClient(
            transport: $transport, baseUrl: 'https://cb.test', apiKey: 'k', backoffs: [0, 0, 0], dormir: static fn(int $s) => null,
        ));

        $transport->encolarOk(200, ['success' => true, 'data' => [['roomID' => 'CB_R101', 'roomCondition' => 'dirty']]]);
        $syncId = $sync->sincronizar(null, 'auto_cron');
        $this->assertSame('parcial', Database::fetchColumn('SELECT resultado FROM cloudbeds_sync_historial WHERE id = ?', [$syncId]));
        $p0 = Database::fetchOne("SELECT descripcion FROM alertas_activas WHERE tipo = 'cloudbeds_sync_failed'");
        $this->assertNotNull($p0, 'antes el sync parcial no avisaba');
        $this->assertStringContainsString('inn', $p0['descripcion']);

        // Se arregla el segundo hotel (acá: se desactiva) y el siguiente sync completo cierra la P0.
        Database::execute("UPDATE hoteles SET activo = 0 WHERE codigo = 'inn'");
        $transport->encolarOk(200, ['success' => true, 'data' => [['roomID' => 'CB_R101', 'roomCondition' => 'dirty']]]);
        $sync->sincronizar(null, 'auto_cron');
        $this->assertSame(0, (int) Database::fetchColumn("SELECT COUNT(*) FROM alertas_activas WHERE tipo = 'cloudbeds_sync_failed'"));
    }

    // --- Helpers ---------------------------------------------------------------------------

    private function crearPieza(string $numero, string $estado, ?string $roomId = null): int
    {
        Database::execute(
            'INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado, cloudbeds_room_id) VALUES (?, ?, ?, ?, ?)',
            [$this->hotelId, $numero, $this->tipoId, $estado, $roomId]
        );
        return Database::lastInsertId();
    }

    private function turnoHoy(int $usuarioId, string $inicio, string $fin, string $fecha): void
    {
        Database::execute('INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES (?, ?, ?)', ["T{$inicio}", $inicio, $fin]);
        Database::execute('INSERT INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)', [$usuarioId, Database::lastInsertId(), $fecha]);
    }

    private function login(string $rut): string
    {
        return (new AuthService())->login($rut, 'Abc12345')['token'];
    }

    private function accion(int $alertaId, string $accion): \Atankalama\Limpieza\Core\Response
    {
        $req = new Request(metodo: 'POST', path: "/api/alertas/{$alertaId}/accion", cuerpo: ['accion' => $accion], ruta: ['id' => (string) $alertaId]);
        $req->usuario = (new UsuarioService())->buscarPorId($this->supId);
        return (new AlertasController())->ejecutarAccion($req);
    }
}
