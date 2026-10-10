<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Support;

use Atankalama\Limpieza\Core\Database;
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

/**
 * Escenario del ciclo de limpieza de punta a punta contra un Cloudbeds simulado: hotel con las
 * piezas 101–103, Ana y Berta (Trabajador), Sofía (Supervisora) y el usuario Sistema del cierre
 * de día, más las ayudas para limpiar, aprobar, sincronizar, cerrar el día, barrer nocheros y
 * pasar al día siguiente. Lo usan CicloLimpiezaRedTest (lo que ya funciona) y los tests de los
 * arreglos de la v6.17.
 */
trait EscenarioCicloLimpieza
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

    protected function prepararEscenario(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO #__hoteles (codigo, nombre, cloudbeds_property_id) VALUES ('1_sur', '1 Sur', 'CB_1SUR')");
        Database::execute("INSERT INTO #__tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();
        $hotelId = (int) Database::fetchColumn("SELECT id FROM #__hoteles WHERE codigo = '1_sur'");
        $tipoId  = (int) Database::fetchColumn('SELECT id FROM #__tipos_habitacion LIMIT 1');

        $this->cb = new CloudbedsSimulado();
        foreach (['101', '102', '103'] as $numero) {
            Database::execute(
                "INSERT INTO #__habitaciones (hotel_id, numero, tipo_habitacion_id, cloudbeds_room_id, estado, es_espacio_comun) VALUES (?, ?, ?, ?, 'sucia', 0)",
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
        $this->asig   = new AsignacionService(cloudbeds: $this->sync);
        $this->chk    = new ChecklistService();
        $this->aud    = new AuditoriaService(cloudbeds: $this->sync);
        $this->cierre = new CierreDiaService($this->aud);
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
        // En Windows el reloj avanza a saltos de ~15 ms: sin esta pausa, un cambio de estado hecho
        // justo antes puede quedar con la misma marca de tiempo que la lectura del sync, y el sync
        // salta la pieza como «cambió después de leer Cloudbeds» (test intermitente, 10/10/2026).
        usleep(20_000);
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
        foreach (Database::fetchAll("SELECT id, created_at FROM #__audit_log WHERE accion = 'habitacion.cambiar_estado'") as $f) {
            $antes = (new \DateTimeImmutable((string) $f['created_at'], new \DateTimeZone('UTC')))->modify('-1 day');
            Database::execute('UPDATE #__audit_log SET created_at = ? WHERE id = ?', [$antes->format('Y-m-d\TH:i:s.v\Z'), (int) $f['id']]);
        }
        Database::execute('UPDATE #__habitaciones SET nochero_ultima_reversion = NULL');
    }

    private function templateDe(int $ejecucionId): int
    {
        return (int) Database::fetchColumn('SELECT template_id FROM #__ejecuciones_checklist WHERE id = ?', [$ejecucionId]);
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
            'SELECT COALESCE(SUM(creditos), 0) FROM #__items_checklist WHERE template_id = ? AND obligatorio = 1',
            [$templateId]
        );
    }

    private function alertasDeshechas(): int
    {
        return (int) Database::fetchColumn('SELECT COUNT(*) FROM #__alertas_activas WHERE tipo = ?', [AlertaActiva::TIPO_APROBACION_DESHECHA]);
    }

    private function assertSinAlertasDeshechas(string $mensaje = 'sin alerta de aprobación deshecha'): void
    {
        $this->assertSame(0, $this->alertasDeshechas(), $mensaje);
    }

    private function assertEstado(string $numero, string $esperado, string $mensaje = ''): void
    {
        $this->assertSame(
            $esperado,
            Database::fetchColumn('SELECT estado FROM #__habitaciones WHERE id = ?', [$this->hab[$numero]]),
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
