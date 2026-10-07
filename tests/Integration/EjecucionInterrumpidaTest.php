<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\ChecklistsController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\ChecklistException;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\HabitacionService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Services\UsuarioService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Ejecución vencida, decisión de Nicolás (07/10/2026): si la pieza se aprueba por otra vía
 * («cliente no desea aseo», Cloudbeds) mientras la trabajadora la limpia, su limpieza queda
 * 'interrumpida' y lo que alcanzó a marcar le da créditos, sin contar como pieza hecha. Si la
 * supervisora la marca sucia, el avance se descarta como antes. Ver docs/checklist.md.
 */
final class EjecucionInterrumpidaTest extends TestCase
{
    private ChecklistService $checklist;
    private AsignacionService $asig;
    private HabitacionService $habitaciones;
    private int $hotelId;
    private int $tipoId;
    private int $anaId;
    private int $evaId;
    private string $hoy;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();

        $this->hotelId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $this->tipoId = (int) Database::fetchOne('SELECT id FROM tipos_habitacion LIMIT 1')['id'];
        [$this->anaId] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        [$this->evaId] = TestDatabase::crearUsuario('22222222-2', 'Eva', 'Supervisora');

        $this->checklist = new ChecklistService();
        $this->asig = new AsignacionService();
        $this->habitaciones = new HabitacionService();
        $this->hoy = date('Y-m-d');
    }

    public function testSinAseoCierraLaLimpiezaComoInterrumpidaConLoMarcado(): void
    {
        $h101 = $this->crearPieza('101');
        [$ejecId, $marcados] = $this->empezarYMarcar($h101, 2);

        // «Cliente no desea aseo» (el controller llama exactamente esto).
        $this->habitaciones->cambiarEstado($h101, Habitacion::ESTADO_APROBADA, $this->evaId, 'ui', forzar: true);

        $ejec = Database::fetchOne('SELECT estado, timestamp_fin FROM ejecuciones_checklist WHERE id = ?', [$ejecId]);
        $this->assertSame('interrumpida', $ejec['estado']);
        $this->assertNotNull($ejec['timestamp_fin']);
        $this->assertSame(count($marcados), (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM ejecuciones_items WHERE ejecucion_id = ? AND marcado = 1 AND marcado_por = ?',
            [$ejecId, $this->anaId]
        ));
        $this->assertSame(1, (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM audit_log WHERE accion = 'checklist.interrumpir' AND entidad_id = ?",
            [$ejecId]
        ));
    }

    public function testCloudbedsTambienLaInterrumpe(): void
    {
        $h101 = $this->crearPieza('101');
        [$ejecId] = $this->empezarYMarcar($h101, 1);

        // El sync de Cloudbeds aprueba sin usuario (cron).
        $this->habitaciones->cambiarEstado($h101, Habitacion::ESTADO_APROBADA, null, 'cron', forzar: true);

        $this->assertSame('interrumpida', $this->estadoEjecucion($ejecId));
        $this->assertSame('cron', Database::fetchColumn(
            "SELECT origen FROM audit_log WHERE accion = 'checklist.interrumpir' AND entidad_id = ?",
            [$ejecId]
        ));
    }

    public function testMarcarSuciaNoLaInterrumpe(): void
    {
        $h101 = $this->crearPieza('101');
        [$ejecId] = $this->empezarYMarcar($h101, 1);

        $this->habitaciones->cambiarEstado($h101, Habitacion::ESTADO_SUCIA, $this->evaId, 'ui');

        // Queda vencida: al volver a empezar se descarta con lo marcado (la supervisora pidió rehacerla).
        $this->assertSame('en_progreso', $this->estadoEjecucion($ejecId));
        $this->checklist->iniciarEjecucion($h101, $this->anaId, $this->hoy);
        $this->assertNull(Database::fetchOne('SELECT id FROM ejecuciones_checklist WHERE id = ?', [$ejecId]));
    }

    public function testMarcarOTerminarUnaInterrumpidaLeExplicaQuePaso(): void
    {
        $h101 = $this->crearPieza('101');
        [$ejecId] = $this->empezarYMarcar($h101, 1);
        $this->habitaciones->cambiarEstado($h101, Habitacion::ESTADO_APROBADA, $this->evaId, 'ui', forzar: true);
        $otroItem = (int) Database::fetchColumn(
            'SELECT ic.id FROM items_checklist ic
               JOIN ejecuciones_checklist ec ON ec.template_id = ic.template_id
              WHERE ec.id = ? AND ic.obligatorio = 1 AND ic.activo = 1
              ORDER BY ic.orden DESC, ic.id DESC LIMIT 1',
            [$ejecId]
        );

        foreach ([
            fn () => $this->checklist->marcarItem($ejecId, $otroItem, true, $this->anaId),
            fn () => $this->checklist->completar($ejecId, $this->anaId),
        ] as $accion) {
            try {
                $accion();
                $this->fail('Debía lanzar EJECUCION_INTERRUMPIDA');
            } catch (ChecklistException $e) {
                $this->assertSame('EJECUCION_INTERRUMPIDA', $e->codigo);
                $this->assertSame(409, $e->httpStatus);
            }
        }

        // «Habitación terminada» desde la app (cola offline incluida) no da 404 sino el mismo aviso.
        $req = new Request(metodo: 'POST', path: "/api/habitaciones/{$h101}/completar", ruta: ['id' => (string) $h101]);
        $req->usuario = (new UsuarioService())->buscarPorId($this->anaId);
        $res = (new ChecklistsController())->completar($req);
        $this->assertSame(409, $res->status);
        $this->assertSame('EJECUCION_INTERRUMPIDA', json_decode($res->cuerpo, true)['error']['codigo']);
    }

    public function testLosCreditosCuentanPeroLaPiezaNo(): void
    {
        $h101 = $this->crearPieza('101');
        [, $marcados] = $this->empezarYMarcar($h101, 2);
        $this->habitaciones->cambiarEstado($h101, Habitacion::ESTADO_APROBADA, $this->evaId, 'ui', forzar: true);

        $ana = $this->filaDe('Ana');
        $this->assertSame(array_sum($marcados), $ana['creditos']);
        $this->assertSame($ana['creditos'], $ana['creditos_no_auditados'], 'nadie la inspeccionó');
        $this->assertSame(0, $ana['habitaciones']);
        $this->assertSame(0, $ana['hab_no_auditadas']);
    }

    public function testSiLaPiezaVuelveASuciaLaNuevaLimpiezaNoBorraLaInterrumpida(): void
    {
        $h101 = $this->crearPieza('101');
        [$ejecId] = $this->empezarYMarcar($h101, 2);
        $this->habitaciones->cambiarEstado($h101, Habitacion::ESTADO_APROBADA, null, 'cron', forzar: true);
        // Cloudbeds la vuelve a dar sucia el mismo día y Ana la limpia de nuevo.
        $this->habitaciones->cambiarEstado($h101, Habitacion::ESTADO_SUCIA, null, 'cron');

        $nueva = $this->checklist->iniciarEjecucion($h101, $this->anaId, $this->hoy);

        $this->assertNotSame($ejecId, $nueva->id);
        $this->assertSame('interrumpida', $this->estadoEjecucion($ejecId));
    }

    public function testUnaVencidaDeAntesSeCierraComoInterrumpidaSiLaPiezaEstaAprobada(): void
    {
        $h101 = $this->crearPieza('101');
        [$ejecId] = $this->empezarYMarcar($h101, 1);
        // Pieza aprobada sin pasar por cambiarEstado (dato que quedó así antes de este cambio).
        Database::execute("UPDATE habitaciones SET estado = 'aprobada' WHERE id = ?", [$h101]);

        try {
            $this->checklist->iniciarEjecucion($h101, $this->anaId, $this->hoy);
            $this->fail('Debía lanzar ESTADO_INVALIDO_PARA_INICIAR');
        } catch (ChecklistException $e) {
            $this->assertSame('ESTADO_INVALIDO_PARA_INICIAR', $e->codigo);
        }
        $this->assertSame('interrumpida', $this->estadoEjecucion($ejecId), 'antes se borraba con sus créditos');
    }

    public function testUnaPiezaSinLimpiezaEnCursoSeApruebaSinTocarNada(): void
    {
        $h101 = $this->crearPieza('101');
        $this->asig->asignarManual($h101, $this->anaId, $this->hoy);

        $this->habitaciones->cambiarEstado($h101, Habitacion::ESTADO_APROBADA, $this->evaId, 'ui', forzar: true);

        $this->assertSame(0, (int) Database::fetchColumn('SELECT COUNT(*) FROM ejecuciones_checklist'));
    }

    // --- Helpers ---------------------------------------------------------------------------

    private function crearPieza(string $numero): int
    {
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, ?, ?, 'sucia')",
            [$this->hotelId, $numero, $this->tipoId]
        );
        return Database::lastInsertId();
    }

    /**
     * Asigna la pieza a Ana, la empieza y marca los primeros $cuantos ítems obligatorios.
     *
     * @return array{0: int, 1: list<int>} id de la ejecución y créditos de cada ítem marcado
     */
    private function empezarYMarcar(int $habitacionId, int $cuantos): array
    {
        $this->asig->asignarManual($habitacionId, $this->anaId, $this->hoy);
        $ejec = $this->checklist->iniciarEjecucion($habitacionId, $this->anaId, $this->hoy);
        $obligatorios = array_values(array_filter(
            $this->checklist->itemsDelTemplate($ejec->templateId),
            static fn (array $i): bool => (int) $i['obligatorio'] === 1
        ));
        $creditos = [];
        foreach (array_slice($obligatorios, 0, $cuantos) as $item) {
            $this->checklist->marcarItem($ejec->id, (int) $item['id'], true, $this->anaId);
            $creditos[] = (int) $item['creditos'];
        }
        return [$ejec->id, $creditos];
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

    private function estadoEjecucion(int $ejecId): string
    {
        return (string) Database::fetchColumn('SELECT estado FROM ejecuciones_checklist WHERE id = ?', [$ejecId]);
    }
}
