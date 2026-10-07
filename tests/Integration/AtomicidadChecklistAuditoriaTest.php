<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaException;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\ChecklistException;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\CierreDiaService;
use Atankalama\Limpieza\Services\HabitacionService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Atomicidad del checklist y la auditoría (auditoría de código del 07/10/2026):
 *  - Una ejecución en curso cuya pieza sale de 'en_progreso' por otro camino («Marcar sucia»,
 *    «sin aseo», Cloudbeds) queda vencida: no traba a la trabajadora ni se puede saltar.
 *  - completar() cierra la ejecución y cambia la pieza en una sola transacción.
 *  - emitirVeredicto() escribe todo en una sola transacción.
 *  - El cierre de día sigue con las demás piezas si una falla.
 */
final class AtomicidadChecklistAuditoriaTest extends TestCase
{
    private ChecklistService $checklist;
    private AsignacionService $asig;
    private HabitacionService $habitaciones;
    private int $hotelId;
    private int $tipoId;
    private int $anaId;
    private int $evaId;
    private string $fecha = '2026-04-14';

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
    }

    // --- Ejecución vencida ---------------------------------------------------------------

    public function testMarcarSuciaUnaEnCursoNoDejaALaTrabajadoraEnBucle(): void
    {
        $h101 = $this->crearPieza('101');
        $this->asig->asignarManual($h101, $this->anaId, $this->fecha);
        $vieja = $this->checklist->iniciarEjecucion($h101, $this->anaId, $this->fecha);

        // «Marcar sucia» de la supervisora (el controller llama exactamente esto).
        $this->habitaciones->cambiarEstado($h101, Habitacion::ESTADO_SUCIA, $this->evaId, 'ui');

        // Al volver a empezar, la ejecución vieja se descarta y arranca una nueva.
        $nueva = $this->checklist->iniciarEjecucion($h101, $this->anaId, $this->fecha);
        $this->assertNotSame($vieja->id, $nueva->id);
        $this->assertSame('en_progreso', $this->estadoDe($h101));
        $this->assertNull(Database::fetchOne('SELECT id FROM ejecuciones_checklist WHERE id = ?', [$vieja->id]));
    }

    public function testSinAseoSobreUnaEnCursoNoBloqueaLaSiguienteNiSeSalta(): void
    {
        $h101 = $this->crearPieza('101');
        $h102 = $this->crearPieza('102');
        $this->asig->asignarManual($h101, $this->anaId, $this->fecha);
        $this->asig->asignarManual($h102, $this->anaId, $this->fecha);
        $this->checklist->iniciarEjecucion($h101, $this->anaId, $this->fecha);

        // «Cliente no desea aseo» (o el sync de Cloudbeds): forzada a aprobada a medio limpiar.
        $this->habitaciones->cambiarEstado($h101, Habitacion::ESTADO_APROBADA, $this->evaId, 'ui', forzar: true);

        // La actual pasa a ser la 102, y el candado la deja empezar.
        $actual = $this->asig->habitacionActualDeCola($this->anaId, $this->fecha);
        $this->assertSame($h102, (int) $actual['habitacion_id']);
        $this->checklist->iniciarEjecucion($h102, $this->anaId, $this->fecha, true);
        $this->assertSame('en_progreso', $this->estadoDe($h102));

        // «No puedo limpiarla» sobre la 101 ya no la devuelve a sucia.
        try {
            $this->checklist->saltarEjecucion($h101, $this->anaId, 'No alcancé', $this->fecha);
            $this->fail('Debía lanzar EJECUCION_NO_ENCONTRADA');
        } catch (ChecklistException $e) {
            $this->assertSame('EJECUCION_NO_ENCONTRADA', $e->codigo);
        }
        $this->assertSame('aprobada', $this->estadoDe($h101));
    }

    // --- completar() ---------------------------------------------------------------------

    public function testCompletarUnaPiezaQueYaNoEstaEnProgresoNoCierraLaEjecucion(): void
    {
        $h101 = $this->crearPieza('101');
        $ejecId = $this->iniciarConObligatorios($h101);
        $this->habitaciones->cambiarEstado($h101, Habitacion::ESTADO_SUCIA, $this->evaId, 'ui');

        try {
            $this->checklist->completar($ejecId, $this->anaId);
            $this->fail('Debía lanzar HABITACION_NO_EN_PROGRESO');
        } catch (ChecklistException $e) {
            $this->assertSame('HABITACION_NO_EN_PROGRESO', $e->codigo);
            $this->assertSame(409, $e->httpStatus);
        }

        // El reintento no se da por bueno: la ejecución no quedó 'completada'.
        $this->assertSame('en_progreso', $this->estadoEjecucion($ejecId));
        $this->assertFalse($this->checklist->ultimaEjecucionCompletadaPorUsuario($h101, $this->anaId));
        $this->assertSame('sucia', $this->estadoDe($h101));
    }

    public function testCompletarRevierteSiFallaElCambioDeEstado(): void
    {
        $h101 = $this->crearPieza('101');
        $ejecId = $this->iniciarConObligatorios($h101);
        $this->fallarUpdateDe($h101);

        try {
            $this->checklist->completar($ejecId, $this->anaId);
            $this->fail('Debía propagar el fallo del cambio de estado');
        } catch (\PDOException) {
        }

        $this->assertSame('en_progreso', $this->estadoEjecucion($ejecId));
    }

    // --- emitirVeredicto() ---------------------------------------------------------------

    public function testVeredictoRevierteTodoSiFallaElCambioDeEstado(): void
    {
        $h101 = $this->crearPieza('101');
        $ejecId = $this->terminar($h101);
        $item = $this->primerItemObligatorio($ejecId);
        $this->fallarUpdateDe($h101);

        try {
            (new AuditoriaService())->emitirVeredicto(
                $h101,
                $this->evaId,
                Auditoria::VEREDICTO_APROBADO_CON_OBSERVACION,
                'Faltó repasar el velador',
                [$item]
            );
            $this->fail('Debía propagar el fallo del cambio de estado');
        } catch (\PDOException) {
        }

        // Nada quedó a medias: sin auditoría, ejecución completada y el ítem sigue marcado.
        $this->assertNull(Database::fetchOne('SELECT id FROM auditorias WHERE ejecucion_id = ?', [$ejecId]));
        $this->assertSame('completada', $this->estadoEjecucion($ejecId));
        $fila = Database::fetchOne(
            'SELECT marcado, desmarcado_por_auditor FROM ejecuciones_items WHERE ejecucion_id = ? AND item_id = ?',
            [$ejecId, $item]
        );
        $this->assertSame(1, (int) $fila['marcado']);
        $this->assertSame(0, (int) $fila['desmarcado_por_auditor']);

        // Y se puede auditar de nuevo una vez resuelto el problema.
        Database::pdo()->exec('DROP TRIGGER simular_fallo_update');
        (new AuditoriaService())->emitirVeredicto($h101, $this->evaId, Auditoria::VEREDICTO_APROBADO);
        $this->assertSame('aprobada', $this->estadoDe($h101));
    }

    public function testItemsDesmarcadosRepetidosSeCuentanUnaVez(): void
    {
        $h101 = $this->crearPieza('101');
        $ejecId = $this->terminar($h101);
        $item = $this->primerItemObligatorio($ejecId);

        $auditoria = (new AuditoriaService())->emitirVeredicto(
            $h101,
            $this->evaId,
            Auditoria::VEREDICTO_RECHAZADO,
            'Polvo en el velador',
            [$item, $item]
        );

        $this->assertSame([$item], $auditoria->itemsDesmarcados);
        $this->assertSame('rechazada', $this->estadoDe($h101));
    }

    public function testItemDesmarcadoAjenoAlChecklistNoDejaNadaEscrito(): void
    {
        $h101 = $this->crearPieza('101');
        $ejecId = $this->terminar($h101);

        try {
            (new AuditoriaService())->emitirVeredicto(
                $h101,
                $this->evaId,
                Auditoria::VEREDICTO_RECHAZADO,
                'Polvo en el velador',
                [999999]
            );
            $this->fail('Debía lanzar ITEMS_DESMARCADOS_INVALIDO');
        } catch (ChecklistException $e) {
            $this->assertSame('ITEMS_DESMARCADOS_INVALIDO', $e->codigo);
        }

        $this->assertNull(Database::fetchOne('SELECT id FROM auditorias WHERE ejecucion_id = ?', [$ejecId]));
        $this->assertSame('completada_pendiente_auditoria', $this->estadoDe($h101));
    }

    public function testSegundoVeredictoSobreLaMismaEjecucionDa409(): void
    {
        $h101 = $this->crearPieza('101');
        $this->terminar($h101);
        (new AuditoriaService())->emitirVeredicto($h101, $this->evaId, Auditoria::VEREDICTO_APROBADO);

        // La pieza ya no está pendiente: el chequeo responde antes de tocar nada.
        try {
            (new AuditoriaService())->emitirVeredicto($h101, $this->evaId, Auditoria::VEREDICTO_RECHAZADO, 'Polvo en el velador', [1]);
            $this->fail('Debía lanzar');
        } catch (AuditoriaException $e) {
            $this->assertSame(409, $e->httpStatus);
        }
        $this->assertSame('aprobada', $this->estadoDe($h101));
    }

    // --- Cierre de día -------------------------------------------------------------------

    public function testCierreDeDiaSigueConLasDemasSiUnaFalla(): void
    {
        $h101 = $this->crearPieza('101');
        $h102 = $this->crearPieza('102');
        $this->terminar($h101);
        $this->terminar($h102);
        $this->fallarUpdateDe($h101);

        $resultado = (new CierreDiaService(new AuditoriaService()))->aprobarPendientes($this->evaId, [
            ['id' => $h101, 'numero' => '101'],
            ['id' => $h102, 'numero' => '102'],
        ]);

        $this->assertSame(['aprobadas' => 1, 'fallidas' => 1], $resultado);
        $this->assertSame('completada_pendiente_auditoria', $this->estadoDe($h101));
        $this->assertSame('aprobada_automatica', $this->estadoDe($h102));
    }

    // --- Helpers -------------------------------------------------------------------------

    private function crearPieza(string $numero): int
    {
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, ?, ?, 'sucia')",
            [$this->hotelId, $numero, $this->tipoId]
        );
        return Database::lastInsertId();
    }

    /** Asigna la pieza a Ana, la inicia y marca todos los obligatorios. Devuelve la ejecución. */
    private function iniciarConObligatorios(int $habitacionId): int
    {
        $this->asig->asignarManual($habitacionId, $this->anaId, $this->fecha);
        $ejec = $this->checklist->iniciarEjecucion($habitacionId, $this->anaId, $this->fecha);
        foreach ($this->checklist->itemsDelTemplate($ejec->templateId) as $item) {
            if ((int) $item['obligatorio'] === 1) {
                $this->checklist->marcarItem($ejec->id, (int) $item['id'], true, $this->anaId);
            }
        }
        return $ejec->id;
    }

    private function terminar(int $habitacionId): int
    {
        $ejecId = $this->iniciarConObligatorios($habitacionId);
        $this->checklist->completar($ejecId, $this->anaId);
        return $ejecId;
    }

    private function primerItemObligatorio(int $ejecId): int
    {
        return (int) Database::fetchOne(
            'SELECT ic.id FROM items_checklist ic
               JOIN ejecuciones_checklist ec ON ec.template_id = ic.template_id
              WHERE ec.id = ? AND ic.obligatorio = 1 AND ic.activo = 1
              ORDER BY ic.orden, ic.id LIMIT 1',
            [$ejecId]
        )['id'];
    }

    /** Hace fallar cualquier UPDATE sobre esa habitación (simula un error de BD a mitad). */
    private function fallarUpdateDe(int $habitacionId): void
    {
        Database::pdo()->exec(
            "CREATE TRIGGER simular_fallo_update BEFORE UPDATE ON habitaciones
             FOR EACH ROW WHEN OLD.id = {$habitacionId}
             BEGIN
                 SELECT RAISE(ABORT, 'fallo simulado');
             END"
        );
    }

    private function estadoDe(int $habitacionId): string
    {
        return (string) Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$habitacionId])['estado'];
    }

    private function estadoEjecucion(int $ejecId): string
    {
        return (string) Database::fetchOne('SELECT estado FROM ejecuciones_checklist WHERE id = ?', [$ejecId])['estado'];
    }
}
