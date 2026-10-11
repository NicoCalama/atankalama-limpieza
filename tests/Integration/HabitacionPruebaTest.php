<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\AsignacionException;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\CloudbedsSyncService;
use Atankalama\Limpieza\Services\HabitacionService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Services\RevisionEntregaService;
use Atankalama\Limpieza\Services\TicketService;
use Atankalama\Limpieza\Tests\Support\FakeHttpTransport;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Habitación de prueba (v7.5, docs/habitacion-prueba.md): sin Cloudbeds, solo la ve y se le asigna a
 * quien tiene habitaciones.ver_prueba, fuera de lo que mide o reparte y sin avisos a nadie más.
 */
final class HabitacionPruebaTest extends TestCase
{
    private string $hoy;
    private int $hotel;
    private int $pieza;
    private int $prueba;
    private int $admin;
    private int $ana;
    private int $sofia;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        $this->hoy = date('Y-m-d');
        Database::execute("INSERT INTO hoteles (codigo, nombre, cloudbeds_property_id) VALUES ('1_sur', '1 Sur', 'CB_1SUR')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();
        $this->hotel = (int) Database::fetchColumn("SELECT id FROM hoteles WHERE codigo = '1_sur'");
        $tipo = (int) Database::fetchColumn('SELECT id FROM tipos_habitacion LIMIT 1');
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, cloudbeds_room_id, estado) VALUES (?, '101', ?, 'CB_R101', 'sucia')",
            [$this->hotel, $tipo]
        );
        $this->pieza = Database::lastInsertId();
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado, es_prueba) VALUES (?, 'PRUEBA', ?, 'sucia', 1)",
            [$this->hotel, $tipo]
        );
        $this->prueba = Database::lastInsertId();

        [$this->admin] = TestDatabase::crearUsuario('11111111-1', 'Admin Prueba', 'Admin');
        [$this->ana] = TestDatabase::crearUsuario('16000001-5', 'Ana', 'Trabajador');
        [$this->sofia] = TestDatabase::crearUsuario('15000001-7', 'Sofía', 'Supervisora');
        Database::execute('INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES (?, ?, ?)', ['mañana', '08:00', '16:00']);
        $turno = (int) Database::fetchColumn('SELECT id FROM turnos LIMIT 1');
        foreach ([$this->ana, $this->admin] as $u) {
            Database::execute('INSERT INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)', [$u, $turno, $this->hoy]);
        }
    }

    /** Para probar con una trabajadora sin kpis.excluido: su rol también recibe el permiso. */
    private function darPermisoATrabajador(): void
    {
        $rol = (int) Database::fetchColumn("SELECT id FROM roles WHERE nombre = 'Trabajador'");
        Database::execute('INSERT INTO rol_permisos (rol_id, permiso_codigo) VALUES (?, ?)', [$rol, 'habitaciones.ver_prueba']);
    }

    private function limpiar(int $habitacionId, int $usuarioId): void
    {
        $chk = new ChecklistService();
        $ejec = $chk->iniciarEjecucion($habitacionId, $usuarioId, $this->hoy);
        foreach ($chk->estadoEjecucion($ejec->id)['items'] as $it) {
            $chk->marcarItem($ejec->id, (int) $it['id'], true, $usuarioId);
        }
        $chk->completar($ejec->id, $usuarioId);
    }

    /** @return list<string> */
    private static function numeros(array $filas): array
    {
        return array_map(static fn(array $f): string => (string) $f['numero'], $filas);
    }

    public function testSoloLaVeQuienTieneElPermiso(): void
    {
        $svc = new HabitacionService();
        $this->assertSame(['101'], self::numeros($svc->listar('ambos')));
        $conPrueba = $svc->listar('ambos', null, true);
        $this->assertSame(['101', 'PRUEBA'], self::numeros($conPrueba));
        $this->assertTrue($conPrueba[1]['es_prueba']);
        $this->assertFalse($conPrueba[0]['es_prueba']);
    }

    public function testSoloSeLeAsignaAQuienPuedeVerla(): void
    {
        $asig = new AsignacionService();
        try {
            $asig->asignarManual($this->prueba, $this->ana, $this->hoy, $this->sofia);
            $this->fail('a una trabajadora sin el permiso no se le asigna');
        } catch (AsignacionException $e) {
            $this->assertSame('HABITACION_DE_PRUEBA', $e->codigo);
        }
        $this->assertSame($this->admin, $asig->asignarManual($this->prueba, $this->admin, $this->hoy)->usuarioId);
    }

    public function testAutoAsignarYElTableroNoLaReparten(): void
    {
        $asig = new AsignacionService();
        $asig->autoAsignar('1_sur', $this->hoy);
        $this->assertNull($asig->obtenerActivaDeHabitacion($this->prueba, $this->hoy), 'el reparto no la toca');
        $this->assertNotNull($asig->obtenerActivaDeHabitacion($this->pieza, $this->hoy));

        $vista = $asig->vistaConsolidada('ambos', $this->hoy);
        $this->assertNotContains('PRUEBA', self::numeros($vista['sin_asignar']));
        $this->assertContains('PRUEBA', self::numeros($asig->vistaConsolidada('ambos', $this->hoy, true)['sin_asignar']));

        // Asignada a quien la puede ver: su cola la muestra solo a quien también puede verla.
        $asig->asignarManual($this->prueba, $this->admin, $this->hoy);
        $colaAdmin = static function (array $vista, int $admin): array {
            foreach ($vista['trabajadores'] as $t) {
                if ($t['usuario']['id'] === $admin) {
                    return self::numeros($t['cola']);
                }
            }
            return [];
        };
        $this->assertSame([], $colaAdmin($asig->vistaConsolidada('ambos', $this->hoy), $this->admin));
        $this->assertSame(['PRUEBA'], $colaAdmin($asig->vistaConsolidada('ambos', $this->hoy, true), $this->admin));
    }

    public function testElCicloNoTocaCloudbedsNiAvisaANadieMas(): void
    {
        $this->darPermisoATrabajador();
        (new AsignacionService())->asignarManual($this->prueba, $this->ana, $this->hoy, $this->sofia);
        $this->limpiar($this->prueba, $this->ana);

        $auditorias = new AuditoriaService();
        $this->assertNotContains('PRUEBA', self::numeros($auditorias->bandejaPendientes()));
        $this->assertContains('PRUEBA', self::numeros($auditorias->bandejaPendientes(null, true)));

        // Rechazo: a quien la limpió le llega su aviso, pero no hay alerta ni push a supervisoras.
        $item = (int) Database::fetchColumn(
            'SELECT ei.item_id FROM ejecuciones_items ei
               JOIN ejecuciones_checklist ec ON ec.id = ei.ejecucion_id
              WHERE ec.habitacion_id = ? ORDER BY ei.id LIMIT 1',
            [$this->prueba]
        );
        $auditorias->emitirVeredicto($this->prueba, $this->sofia, 'rechazado', null, [$item], TestDatabase::opcionId('rechazo', 'Otro'));
        $this->assertSame(0, (int) Database::fetchColumn('SELECT COUNT(*) FROM alertas_activas'));
        $this->assertSame(1, (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND tipo = 'habitacion_rechazada'",
            [$this->ana]
        ));
        $this->assertSame(0, (int) Database::fetchColumn('SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ?', [$this->sofia]));

        // Aprobar: Cloudbeds nunca recibe nada (el transporte falso no tiene respuestas encoladas).
        (new AsignacionService())->asignarManual($this->prueba, $this->ana, $this->hoy, $this->sofia);
        $this->limpiar($this->prueba, $this->ana);
        $transport = new FakeHttpTransport();
        $conCloudbeds = new AuditoriaService(cloudbeds: new CloudbedsSyncService(new CloudbedsClient(
            transport: $transport, baseUrl: 'https://cb.test', apiKey: 'k', backoffs: [0, 0, 0], dormir: static fn(int $s) => null,
        )));
        $conCloudbeds->emitirVeredicto($this->prueba, $this->sofia, 'aprobado');
        $this->assertSame([], $transport->peticiones);
        $this->assertSame(0, (int) Database::fetchColumn('SELECT COUNT(*) FROM cloudbeds_sync_historial'));
        $this->assertSame(0, (int) Database::fetchColumn("SELECT COUNT(*) FROM logs_eventos WHERE nivel = 'WARNING' AND modulo = 'cloudbeds'"));
    }

    public function testNoCuentaEnLosKpisNiEnLaProduccionDeRecepcion(): void
    {
        $this->darPermisoATrabajador();
        $asig = new AsignacionService();
        $asig->asignarManual($this->pieza, $this->ana, $this->hoy, $this->sofia);
        $asig->asignarManual($this->prueba, $this->ana, $this->hoy, $this->sofia);
        $this->limpiar($this->pieza, $this->ana);
        $this->limpiar($this->prueba, $this->ana);
        $auditorias = new AuditoriaService();
        $auditorias->emitirVeredicto($this->pieza, $this->sofia, 'aprobado');
        $auditorias->emitirVeredicto($this->prueba, $this->sofia, 'aprobado');

        $kpis = (new ReportesService())->kpis($this->hoy, $this->hoy, 'ambos');
        $this->assertSame(1, $kpis['limpiadas']['valor'], 'solo la 101');

        [$carla] = TestDatabase::crearUsuario('17000001-3', 'Carla', 'Recepción');
        $revision = new RevisionEntregaService();
        $revision->registrar($this->pieza, 'si', null, null, null, $carla);
        $revision->registrar($this->prueba, 'si', null, null, null, $carla);
        $produccion = $revision->produccionPorRecepcionista($this->hoy, $this->hoy, 'ambos');
        $this->assertSame(1, $produccion[0]['inspecciones'], 'solo la 101');
    }

    public function testSusTicketsNoAvisanNiVanANovedades(): void
    {
        $tickets = new TicketService();
        $deLaPrueba = $tickets->crear($this->hotel, 'Luz o ampolleta', 'Luz o ampolleta', 'normal', $this->admin, $this->prueba);
        $this->assertSame(0, (int) Database::fetchColumn("SELECT COUNT(*) FROM alertas_activas WHERE tipo = 'ticket_nuevo'"));
        $tickets->notificarCreacionANovedades($deLaPrueba->id);
        $this->assertSame(0, (int) Database::fetchColumn("SELECT COUNT(*) FROM logs_eventos WHERE modulo = 'novedades_sync'"), 'ni siquiera lo intenta');

        $deLa101 = $tickets->crear($this->hotel, 'Luz o ampolleta', 'Luz o ampolleta', 'normal', $this->admin, $this->pieza);
        $this->assertSame(1, (int) Database::fetchColumn("SELECT COUNT(*) FROM alertas_activas WHERE tipo = 'ticket_nuevo'"));
        $tickets->notificarCreacionANovedades($deLa101->id);
        $this->assertSame(1, (int) Database::fetchColumn("SELECT COUNT(*) FROM logs_eventos WHERE modulo = 'novedades_sync'"), 'el de una pieza real sí');

        $this->assertSame([$deLa101->id], array_map(static fn(array $t): int => (int) $t['id'], $tickets->listar()));
        $this->assertCount(2, $tickets->listar(['incluir_prueba' => true]));
    }

    public function testLaMigracionCreaUnaPorHotelUnaSolaVez(): void
    {
        Database::execute('DELETE FROM habitaciones WHERE id = ?', [$this->prueba]);
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('inn', 'Inn')");
        $correr = static function (): string {
            ob_start();
            try {
                $argv = ['migrate-add-habitacion-prueba.php'];
                include dirname(__DIR__, 2) . '/scripts/migrate-add-habitacion-prueba.php';
            } finally {
                $salida = (string) ob_get_clean();
            }
            return $salida;
        };
        $salida = $correr();
        $this->assertStringContainsString('1 Sur: habitación de prueba «PRUEBA» creada (tipo Doble)', $salida);
        $this->assertStringContainsString('Inn no tiene habitaciones', $salida);
        $this->assertSame(1, (int) Database::fetchColumn('SELECT COUNT(*) FROM habitaciones WHERE es_prueba = 1'));
        $fila = Database::fetchOne("SELECT estado, cloudbeds_room_id, es_espacio_comun FROM habitaciones WHERE es_prueba = 1");
        $this->assertSame(['estado' => 'aprobada', 'cloudbeds_room_id' => null, 'es_espacio_comun' => 0], [
            'estado' => $fila['estado'], 'cloudbeds_room_id' => $fila['cloudbeds_room_id'], 'es_espacio_comun' => (int) $fila['es_espacio_comun'],
        ]);

        $this->assertStringContainsString('1 Sur ya tiene su habitación de prueba', $correr());
        $this->assertSame(1, (int) Database::fetchColumn('SELECT COUNT(*) FROM habitaciones WHERE es_prueba = 1'));
    }
}
