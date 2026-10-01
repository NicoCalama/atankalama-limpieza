<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Helpers\Fechas;
use Atankalama\Limpieza\Models\AlertaActiva;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\CierreDiaService;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\CloudbedsSyncService;
use Atankalama\Limpieza\Services\HabitacionService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Tests\Support\CloudbedsSimulado;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * RED DE SEGURIDAD del ciclo de limpieza con Cloudbeds (01/10/2026), antes de los arreglos de la
 * v6.17. Cada test es una de las combinaciones que el documento «Ciclo de limpieza y Cloudbeds:
 * casos posibles y soluciones» simuló y marcó como «funcionan bien», más el flujo que confirmó
 * jefatura: a las 15:50 el cierre aprueba lo que nadie inspeccionó y a las 16:00 el barrido
 * devuelve los nocheros a sucia para el turno de tarde.
 *
 * Ningún arreglo puede romperlos. Si uno falla después de un cambio, el cambio está mal o la
 * decisión de negocio cambió: en ese caso se actualiza el test Y el documento, nunca solo el test.
 *
 * Corren el código real de punta a punta (checklist, auditoría, sync, cierre de día, barrido de
 * nocheros) contra un Cloudbeds simulado con memoria (tests/Support/CloudbedsSimulado.php).
 */
final class CicloLimpiezaRedTest extends TestCase
{
    private CloudbedsSimulado $cb;
    private CloudbedsSyncService $sync;
    private HabitacionService $habs;
    private AsignacionService $asig;
    private ChecklistService $chk;
    private AuditoriaService $aud;
    private CierreDiaService $cierre;
    private int $ana;
    private int $berta;
    private int $sofia;
    private int $sistema;
    private string $hoy;
    /** @var array<string, int> número → id */
    private array $hab = [];

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre, cloudbeds_property_id) VALUES ('1_sur', '1 Sur', 'CB_1SUR')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();
        $hotelId = (int) Database::fetchColumn("SELECT id FROM hoteles WHERE codigo = '1_sur'");
        $tipoId  = (int) Database::fetchColumn('SELECT id FROM tipos_habitacion LIMIT 1');

        $this->cb = new CloudbedsSimulado();
        foreach (['101', '102', '103'] as $numero) {
            Database::execute(
                "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, cloudbeds_room_id, estado, es_espacio_comun) VALUES (?, ?, ?, ?, 'sucia', 0)",
                [$hotelId, $numero, $tipoId, "CB_{$numero}"]
            );
            $this->hab[$numero] = Database::lastInsertId();
            $this->cb->pieza("CB_{$numero}", 'dirty', 'check-out', false);
        }

        [$this->ana]     = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        [$this->berta]   = TestDatabase::crearUsuario('22222222-2', 'Berta', 'Trabajador');
        [$this->sofia]   = TestDatabase::crearUsuario('33333333-3', 'Sofia', 'Supervisora');
        [$this->sistema] = TestDatabase::crearUsuario('SISTEMA-CRON', 'Sistema', 'Admin');

        $this->hoy    = date('Y-m-d');
        $this->sync   = new CloudbedsSyncService(new CloudbedsClient(
            transport: $this->cb,
            baseUrl: 'https://cb.test',
            apiKey: 'k',
            backoffs: [0, 0, 0],
            dormir: static fn(int $s) => null,
            dryRun: false,
        ));
        $this->habs   = new HabitacionService();
        $this->asig   = new AsignacionService();
        $this->chk    = new ChecklistService();
        $this->aud    = new AuditoriaService(cloudbeds: $this->sync);
        $this->cierre = new CierreDiaService($this->aud);
    }

    // ── Casos que funcionan bien ─────────────────────────────────────────────

    public function testElHuespedHaceCheckOutMientrasSeLimpia(): void
    {
        $this->cb->estadia('CB_101');
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);

        $this->cb->seVa('CB_101');
        $this->sincronizar();
        $this->assertEstado('101', 'en_progreso', 'el sync no interrumpe una limpieza en curso');

        $this->terminar($e->id, $this->ana);
        $this->aprobar('101');
        $this->sincronizar();

        $this->assertEstado('101', 'aprobada');
        $this->assertSame('clean', $this->cb->condicion('CB_101'));
        $this->assertSinAlertasDeshechas();
    }

    public function testLlegaUnHuespedAUnaPiezaAprobadaHoyYAlDiaSiguienteVuelveALaCola(): void
    {
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');

        $this->cb->llega('CB_101');
        $this->sincronizar();
        $this->assertEstado('101', 'aprobada', 'conserva la aprobación todo el día (pieza 706, 22/09)');

        $this->pasarDia();
        $this->sincronizar();
        $this->assertEstado('101', 'sucia', 'al día siguiente entra al aseo del día');
        $this->assertSinAlertasDeshechas();
    }

    public function testAprobadaConElHuespedAdentroYCheckOutAnticipadoVuelveALaColaConAlertaQueSeResuelveSola(): void
    {
        $this->cb->estadia('CB_101');
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $this->sincronizar();
        $this->assertEstado('101', 'aprobada');

        $this->cb->seVa('CB_101');
        $this->sincronizar();
        $this->assertEstado('101', 'sucia', 'se fue en la tarde: hay que limpiarla como salida');
        $this->assertSame(1, $this->alertasDeshechas());

        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $this->assertSinAlertasDeshechas('al re-aprobarse la alerta se resuelve sola');
    }

    public function testLateCheckOutDespuesDeUnaAprobacionAutomatica(): void
    {
        $this->cb->estadia('CB_101');
        $this->limpiar('101', $this->ana);
        $this->cierreDeDia();
        $this->assertEstado('101', 'aprobada_automatica');

        $this->cb->pieza('CB_101', 'dirty', 'check-out', true); // le toca salir, pero sigue adentro
        $this->sincronizar();
        $this->assertEstado('101', 'aprobada_automatica', 'se conserva mientras está ocupada');

        $this->cb->seVa('CB_101');
        $this->sincronizar();
        $this->assertEstado('101', 'sucia');
        $this->assertSame(1, $this->alertasDeshechas());
    }

    public function testElHuespedSeCambiaDePieza(): void
    {
        // Origen: la 101, limpiada con el huésped adentro. Destino: la 102, limpiada vacía.
        $this->cb->estadia('CB_101');
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $this->limpiar('102', $this->ana);
        $this->aprobar('102');

        $this->cb->seVa('CB_101');
        $this->cb->llega('CB_102');
        $this->sincronizar();

        $this->assertEstado('101', 'sucia', 'la de origen vuelve a la cola');
        $this->assertEstado('102', 'aprobada', 'la de destino, recién hecha, se conserva');
    }

    public function testUnaAprobacionALas2230EsDeHoyTambienEnLosCambiosDeHorario(): void
    {
        // 22:30 de Chile ya es el día siguiente en UTC (el reloj del servidor).
        // 04/04 y 05/09/2026: vísperas del fin y del inicio del horario de verano.
        foreach ([$this->hoy, '2026-04-04', '2026-09-05'] as $fecha) {
            Database::execute('DELETE FROM audit_log');
            Database::execute(
                "INSERT INTO audit_log (usuario_id, accion, entidad, entidad_id, detalles_json, origen, created_at)
                 VALUES (NULL, 'habitacion.cambiar_estado', 'habitacion', ?, ?, 'ui', ?)",
                [$this->hab['101'], json_encode(['desde' => 'completada_pendiente_auditoria', 'hasta' => 'aprobada']), Fechas::instanteLocalUtc($fecha, '22:30')]
            );
            $manana = date('Y-m-d', (int) strtotime($fecha . ' +1 day'));
            $this->assertTrue($this->habs->cambioDeEstadoHoy($this->hab['101'], $fecha), "22:30 del {$fecha} es de ese día");
            $this->assertFalse($this->habs->cambioDeEstadoHoy($this->hab['101'], $manana), "y no del {$manana}");
        }
    }

    public function testNadieInspeccionaYElHuespedSigue(): void
    {
        $this->cb->estadia('CB_101');
        $this->limpiar('101', $this->ana);
        $this->sincronizar();
        $this->assertEstado('101', 'completada_pendiente_auditoria', 'el sync no toca una pieza en espera de inspección');

        $this->cierreDeDia();
        $this->assertEstado('101', 'aprobada_automatica');
        $this->assertSame(
            Auditoria::VEREDICTO_APROBADO_AUTOMATICO,
            Database::fetchColumn('SELECT veredicto FROM auditorias WHERE habitacion_id = ?', [$this->hab['101']])
        );

        $this->pasarDia();
        $this->cb->madrugada();
        $this->sincronizar();
        $this->assertEstado('101', 'sucia', 'en la madrugada vuelve a la cola para el servicio del día');
        $this->assertSinAlertasDeshechas();
    }

    public function testEntraUnHuespedMientrasLaPiezaEsperaInspeccion(): void
    {
        $this->limpiar('101', $this->ana);
        $this->cb->llega('CB_101');
        $this->sincronizar();
        $this->assertEstado('101', 'completada_pendiente_auditoria');

        $this->aprobar('101');
        $this->sincronizar();
        $this->assertEstado('101', 'aprobada');

        $this->pasarDia();
        $this->cb->madrugada();
        $this->sincronizar();
        $this->assertEstado('101', 'sucia', 'al día siguiente sigue el ciclo normal');
        $this->assertSinAlertasDeshechas();
    }

    public function testReasignarUnaPiezaSuciaQueNadieEmpezoNoEscribeACloudbeds(): void
    {
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $this->asig->asignarManual($this->hab['101'], $this->berta, $this->hoy);

        $this->assertCount(0, $this->asig->colaDelTrabajador($this->ana, $this->hoy));
        $this->assertCount(1, $this->asig->colaDelTrabajador($this->berta, $this->hoy));
        $this->assertEstado('101', 'sucia');
        $this->assertSame([], $this->cb->escrituras);
    }

    public function testElCierreDeLas1550NoTocaPiezasEnCursoNiSucias(): void
    {
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        $this->limpiar('103', $this->berta);

        $this->cierreDeDia();

        $this->assertEstado('101', 'en_progreso');
        $this->assertEstado('102', 'sucia');
        $this->assertEstado('103', 'aprobada_automatica');
    }

    public function testDarPorLimpiaDuranteUnaLimpiezaLaCierraConLoMarcadoYLaTrabajadoraQuedaLibre(): void
    {
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        $primero = $this->obligatorios($e->templateId)[0];
        $this->chk->marcarItem($e->id, $primero, true, $this->ana);

        $this->chk->marcarLimpiaManual($this->hab['101'], $this->sofia);

        $this->assertEstado('101', 'completada_pendiente_auditoria', 'queda para inspección');
        $this->assertSame('completada', Database::fetchColumn('SELECT estado FROM ejecuciones_checklist WHERE id = ?', [$e->id]));
        $this->assertSame(
            $this->ana,
            (int) Database::fetchColumn('SELECT marcado_por FROM ejecuciones_items WHERE ejecucion_id = ? AND item_id = ?', [$e->id, $primero]),
            'conserva lo que Ana alcanzó a marcar'
        );
        // Ana queda libre: puede empezar otra pieza.
        $this->asig->asignarManual($this->hab['102'], $this->ana, $this->hoy);
        $this->chk->iniciarEjecucion($this->hab['102'], $this->ana, $this->hoy);
        $this->assertEstado('102', 'en_progreso');
    }

    public function testNocheroAseoDeLaMananaBarridoDeLas1600YSegundoAseo(): void
    {
        $this->cb->estadia('CB_101');
        $this->habs->marcarNochero($this->hab['101'], $this->hoy);

        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $this->barridoNocheros();
        $this->assertEstado('101', 'sucia', 'el barrido de las 16:00 pide la limpieza de la tarde');
        $this->assertSame('dirty', $this->cb->condicion('CB_101'), 'y avisa a Cloudbeds para que su sync no la cierre');

        $this->sincronizar();
        $this->assertEstado('101', 'sucia');

        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $this->barridoNocheros();
        $this->sincronizar();
        $this->assertEstado('101', 'aprobada', 'el barrido no se repite el mismo día');
        $this->assertSame(2, (int) Database::fetchColumn('SELECT COUNT(*) FROM ejecuciones_checklist WHERE habitacion_id = ?', [$this->hab['101']]));
        $this->assertSinAlertasDeshechas();
    }

    public function testReLimpiezaDeUnaRechazadaQueApruebaElCierreAutomatico(): void
    {
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $e = $this->limpiar('101', $this->ana);
        $this->aud->emitirVeredicto($this->hab['101'], $this->sofia, Auditoria::VEREDICTO_RECHAZADO, 'Rehacer estos ítems.', array_slice($this->obligatorios($this->templateDe($e)), 0, 2));
        $this->assertEstado('101', 'rechazada');

        $this->limpiar('101', $this->ana);
        $this->cierreDeDia();

        $this->assertEstado('101', 'aprobada_automatica', 'la marca «nadie la inspeccionó» se conserva');
        $ficha = $this->filaDe('Ana');
        $this->assertSame(1, $ficha['rechazadas_hab']);
        $this->assertSame(
            (int) round($this->creditosObligatorios($this->templateDe($e)) * 0.5),
            $ficha['creditos'],
            'escalera 100/50/0: la segunda vez vale la mitad'
        );
    }

    public function testFallaLaEscrituraACloudbedsEnElCierreConLaPiezaOcupada(): void
    {
        $this->cb->estadia('CB_101');
        $this->limpiar('101', $this->ana);

        $this->cb->fallarEscrituras = true;
        $this->cierreDeDia();
        $this->cb->fallarEscrituras = false;
        $this->assertEstado('101', 'aprobada_automatica');
        $this->assertSame('dirty', $this->cb->condicion('CB_101'), 'Cloudbeds no se enteró');

        $this->sincronizar();
        $this->assertEstado('101', 'aprobada_automatica', 'ocupada y de hoy: se conserva');

        $this->pasarDia();
        $this->cb->madrugada();
        $this->sincronizar();
        $this->assertEstado('101', 'sucia', 'en la madrugada vuelve a la cola del día');
        $this->assertSinAlertasDeshechas();
    }

    // ── Flujo confirmado por jefatura (01/10/2026) ───────────────────────────

    public function testJefaturaCierreDeLas1550YBarridoDeNocherosDeLas1600ParaElTurnoDeTarde(): void
    {
        // Mañana: Ana limpia la 101 (nochero) y la 102 (normal), las dos con huésped. Nadie inspecciona.
        foreach (['101', '102'] as $n) {
            $this->cb->estadia("CB_{$n}");
        }
        $this->habs->marcarNochero($this->hab['101'], $this->hoy);
        $this->limpiar('101', $this->ana);
        $this->limpiar('102', $this->ana);

        // 15:50: el cierre aprueba solas las dos y avisa «limpia» a Cloudbeds.
        $this->cierreDeDia();
        $this->assertEstado('101', 'aprobada_automatica');
        $this->assertEstado('102', 'aprobada_automatica');
        $this->assertSame('clean', $this->cb->condicion('CB_101'));
        $this->assertSame('clean', $this->cb->condicion('CB_102'));

        // 16:00: el barrido devuelve SOLO el nochero a sucia, y avisa «sucia» a Cloudbeds.
        $this->barridoNocheros();
        $this->assertEstado('101', 'sucia', 'el turno de tarde sabe que hay que limpiarla');
        $this->assertEstado('102', 'aprobada_automatica', 'la pieza normal no se toca');
        $this->assertSame('dirty', $this->cb->condicion('CB_101'));

        // El sync siguiente no deshace nada: ni re-aprueba el nochero ni ensucia la normal.
        $this->sincronizar();
        $this->assertEstado('101', 'sucia');
        $this->assertEstado('102', 'aprobada_automatica');

        // Tarde: la 101 aparece entre las sucias y se limpia otra vez; esa sí se inspecciona.
        $sucias = array_map(static fn(array $h): int => (int) $h['id'], $this->habs->listar('ambos', 'sucia'));
        $this->assertContains($this->hab['101'], $sucias);
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $this->barridoNocheros();
        $this->sincronizar();
        $this->assertEstado('101', 'aprobada', 'una sola reversión por día');
        $this->assertSinAlertasDeshechas();
    }

    // ── Ayudas ───────────────────────────────────────────────────────────────

    /** Asigna (si hace falta), limpia con todos los obligatorios y deja la pieza en espera de inspección. */
    private function limpiar(string $numero, int $usuario): int
    {
        $this->asig->asignarManual($this->hab[$numero], $usuario, $this->hoy);
        $e = $this->chk->iniciarEjecucion($this->hab[$numero], $usuario, $this->hoy);
        $this->terminar($e->id, $usuario);
        return $e->id;
    }

    private function terminar(int $ejecucionId, int $usuario): void
    {
        foreach ($this->obligatorios($this->templateDe($ejecucionId)) as $itemId) {
            $this->chk->marcarItem($ejecucionId, $itemId, true, $usuario);
        }
        $this->chk->completar($ejecucionId, $usuario);
    }

    private function aprobar(string $numero): void
    {
        $this->aud->emitirVeredicto($this->hab[$numero], $this->sofia, Auditoria::VEREDICTO_APROBADO);
    }

    private function sincronizar(): void
    {
        $this->sync->sincronizar(null, 'auto_cron');
    }

    private function cierreDeDia(): void
    {
        $this->cierre->aprobarPendientes($this->sistema);
    }

    private function barridoNocheros(): void
    {
        $this->habs->barrerNocheros($this->hoy, $this->sync);
    }

    /**
     * Pasa al día siguiente: todo cambio de estado y todo barrido de nochero queda en el pasado
     * (la app decide «¿se aprobó hoy?» con el audit_log). UTC: ayer a esta hora nunca es hoy en Chile.
     */
    private function pasarDia(): void
    {
        foreach (Database::fetchAll("SELECT id, created_at FROM audit_log WHERE accion = 'habitacion.cambiar_estado'") as $f) {
            $antes = (new \DateTimeImmutable((string) $f['created_at'], new \DateTimeZone('UTC')))->modify('-1 day');
            Database::execute('UPDATE audit_log SET created_at = ? WHERE id = ?', [$antes->format('Y-m-d\TH:i:s.v\Z'), (int) $f['id']]);
        }
        Database::execute('UPDATE habitaciones SET nochero_ultima_reversion = NULL');
    }

    private function templateDe(int $ejecucionId): int
    {
        return (int) Database::fetchColumn('SELECT template_id FROM ejecuciones_checklist WHERE id = ?', [$ejecucionId]);
    }

    /** @return list<int> */
    private function obligatorios(int $templateId): array
    {
        $ids = [];
        foreach ($this->chk->itemsDelTemplate($templateId) as $it) {
            if ((int) $it['obligatorio'] === 1) {
                $ids[] = (int) $it['id'];
            }
        }
        return $ids;
    }

    private function creditosObligatorios(int $templateId): int
    {
        return (int) Database::fetchColumn(
            'SELECT COALESCE(SUM(creditos), 0) FROM items_checklist WHERE template_id = ? AND obligatorio = 1',
            [$templateId]
        );
    }

    private function alertasDeshechas(): int
    {
        return (int) Database::fetchColumn('SELECT COUNT(*) FROM alertas_activas WHERE tipo = ?', [AlertaActiva::TIPO_APROBACION_DESHECHA]);
    }

    private function assertSinAlertasDeshechas(string $mensaje = 'sin alerta de aprobación deshecha'): void
    {
        $this->assertSame(0, $this->alertasDeshechas(), $mensaje);
    }

    private function assertEstado(string $numero, string $esperado, string $mensaje = ''): void
    {
        $this->assertSame(
            $esperado,
            Database::fetchColumn('SELECT estado FROM habitaciones WHERE id = ?', [$this->hab[$numero]]),
            $mensaje !== '' ? $mensaje : "estado de la {$numero}"
        );
    }

    /** @return array<string, mixed> */
    private function filaDe(string $nombre): array
    {
        foreach ((new ReportesService())->fichaKpis($this->hoy, $this->hoy, 'ambos')['trabajadores'] as $t) {
            if ($t['nombre'] === $nombre) {
                return $t;
            }
        }
        $this->fail("No aparece {$nombre} en la ficha");
    }
}
