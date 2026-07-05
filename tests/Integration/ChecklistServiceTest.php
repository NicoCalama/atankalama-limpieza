<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\ChecklistException;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class ChecklistServiceTest extends TestCase
{
    private ChecklistService $svc;
    private AsignacionService $asignaciones;
    private int $habitacionId;
    private int $usuarioId;
    private string $fecha = '2026-04-14';

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();

        $hotelId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $tipoId = (int) Database::fetchOne("SELECT id FROM tipos_habitacion LIMIT 1")['id'];

        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, '101', ?, 'sucia')",
            [$hotelId, $tipoId]
        );
        $this->habitacionId = Database::lastInsertId();

        [$this->usuarioId] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');

        $this->asignaciones = new AsignacionService();
        $this->asignaciones->asignarManual($this->habitacionId, $this->usuarioId, $this->fecha);

        $this->svc = new ChecklistService();
    }

    public function testIniciarCreaEjecucionYCambiaEstado(): void
    {
        $ejec = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);

        $this->assertSame('en_progreso', $ejec->estado);
        $hab = Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$this->habitacionId]);
        $this->assertSame(Habitacion::ESTADO_EN_PROGRESO, $hab['estado']);
    }

    public function testIniciarEsIdempotente(): void
    {
        $e1 = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        $e2 = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        $this->assertSame($e1->id, $e2->id);
    }

    public function testIniciarSinAsignacionLanza(): void
    {
        [$otro] = TestDatabase::crearUsuario('22222222-2', 'Bea', 'Trabajador');
        try {
            $this->svc->iniciarEjecucion($this->habitacionId, $otro, $this->fecha);
            $this->fail('Debía lanzar');
        } catch (ChecklistException $e) {
            $this->assertSame('HABITACION_NO_ASIGNADA', $e->codigo);
        }
    }

    public function testMarcarItemActualizaProgreso(): void
    {
        $ejec = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        $items = $this->svc->itemsDelTemplate($ejec->templateId);
        $primero = (int) $items[0]['id'];

        $progreso = $this->svc->marcarItem($ejec->id, $primero, true, $this->usuarioId);

        $this->assertSame(1, $progreso['marcados']);
        $this->assertSame(10, $progreso['total']);
        $this->assertSame(10, $progreso['porcentaje']);
    }

    public function testMarcarItemGuardaMarcadoPor(): void
    {
        $ejec = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        $items = $this->svc->itemsDelTemplate($ejec->templateId);
        $primero = (int) $items[0]['id'];

        // Al marcar, el ítem queda a nombre del trabajador.
        $this->svc->marcarItem($ejec->id, $primero, true, $this->usuarioId);
        $fila = Database::fetchOne(
            'SELECT marcado, marcado_por FROM ejecuciones_items WHERE ejecucion_id = ? AND item_id = ?',
            [$ejec->id, $primero]
        );
        $this->assertSame(1, (int) $fila['marcado']);
        $this->assertSame($this->usuarioId, (int) $fila['marcado_por']);

        // Al desmarcar, se libera la atribución.
        $this->svc->marcarItem($ejec->id, $primero, false, $this->usuarioId);
        $fila = Database::fetchOne(
            'SELECT marcado, marcado_por FROM ejecuciones_items WHERE ejecucion_id = ? AND item_id = ?',
            [$ejec->id, $primero]
        );
        $this->assertSame(0, (int) $fila['marcado']);
        $this->assertNull($fila['marcado_por']);
    }

    public function testMarcarItemDeOtroUsuarioLanza(): void
    {
        $ejec = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        [$otro] = TestDatabase::crearUsuario('22222222-2', 'Bea', 'Trabajador');
        $items = $this->svc->itemsDelTemplate($ejec->templateId);

        try {
            $this->svc->marcarItem($ejec->id, (int) $items[0]['id'], true, $otro);
            $this->fail('Debía lanzar');
        } catch (ChecklistException $e) {
            $this->assertSame('EJECUCION_AJENA', $e->codigo);
        }
    }

    public function testCompletarRequiereTodosObligatorios(): void
    {
        $ejec = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);

        try {
            $this->svc->completar($ejec->id, $this->usuarioId);
            $this->fail('Debía lanzar');
        } catch (ChecklistException $e) {
            $this->assertSame('CHECKLIST_INCOMPLETO', $e->codigo);
        }
    }

    public function testCompletarExitoso(): void
    {
        $ejec = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        $items = $this->svc->itemsDelTemplate($ejec->templateId);

        foreach ($items as $item) {
            if ((int) $item['obligatorio'] === 1) {
                $this->svc->marcarItem($ejec->id, (int) $item['id'], true, $this->usuarioId);
            }
        }

        $this->svc->completar($ejec->id, $this->usuarioId);

        $ejecActualizada = Database::fetchOne('SELECT * FROM ejecuciones_checklist WHERE id = ?', [$ejec->id]);
        $this->assertSame('completada', $ejecActualizada['estado']);
        $this->assertNotNull($ejecActualizada['timestamp_fin']);

        $hab = Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$this->habitacionId]);
        $this->assertSame(Habitacion::ESTADO_COMPLETADA_PENDIENTE_AUDITORIA, $hab['estado']);
    }

    public function testPersistenciaTapATapReanuda(): void
    {
        $ejec = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        $items = $this->svc->itemsDelTemplate($ejec->templateId);
        $this->svc->marcarItem($ejec->id, (int) $items[0]['id'], true, $this->usuarioId);
        $this->svc->marcarItem($ejec->id, (int) $items[1]['id'], true, $this->usuarioId);

        $estado = $this->svc->estadoEjecucion($ejec->id);

        $marcadosEnResp = array_filter($estado['items'], fn($it) => (int) $it['marcado'] === 1);
        $this->assertCount(2, $marcadosEnResp);
        $this->assertSame(2, $estado['progreso']['marcados']);
    }

    public function testItemDesmarcadoAfectaProgreso(): void
    {
        $ejec = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        $items = $this->svc->itemsDelTemplate($ejec->templateId);
        $this->svc->marcarItem($ejec->id, (int) $items[0]['id'], true, $this->usuarioId);
        $p = $this->svc->marcarItem($ejec->id, (int) $items[0]['id'], false, $this->usuarioId);

        $this->assertSame(0, $p['marcados']);
    }

    /**
     * Crea una segunda habitación 'sucia' asignada al mismo trabajador y retorna su id.
     */
    private function crearSegundaHabitacionAsignada(string $numero = '102', ?string $fecha = null): int
    {
        $fecha ??= $this->fecha;
        $hotelId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $tipoId = (int) Database::fetchOne('SELECT id FROM tipos_habitacion LIMIT 1')['id'];
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, ?, ?, 'sucia')",
            [$hotelId, $numero, $tipoId]
        );
        $id = Database::lastInsertId();
        $this->asignaciones->asignarManual($id, $this->usuarioId, $fecha);
        return $id;
    }

    public function testCandadoNoBloqueaEjecucionEnProgresoDeOtraFecha(): void
    {
        // Ejecución 'en_progreso' de un turno anterior, nunca terminada.
        $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);

        // Habitación de OTRO día (turno siguiente).
        $otraFecha = '2026-04-15';
        $hoyId = $this->crearSegundaHabitacionAsignada('201', $otraFecha);

        // No debe bloquear: la ejecución huérfana es de otra fecha, no aparece en la
        // cola de hoy y no sería alcanzable para terminarla ni saltarla.
        $ejec = $this->svc->iniciarEjecucion($hoyId, $this->usuarioId, $otraFecha);
        $this->assertSame('en_progreso', $ejec->estado);
    }

    public function testNoPuedeIniciarSegundaHabitacionConOtraEnProgreso(): void
    {
        $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        $segunda = $this->crearSegundaHabitacionAsignada();

        try {
            $this->svc->iniciarEjecucion($segunda, $this->usuarioId, $this->fecha);
            $this->fail('Debía lanzar por candado una-a-la-vez');
        } catch (ChecklistException $e) {
            $this->assertSame('YA_TIENE_HABITACION_EN_PROGRESO', $e->codigo);
            $this->assertSame(409, $e->httpStatus);
        }
    }

    public function testSaltarLiberaCandadoDevuelveSuciaYReordena(): void
    {
        $segunda = $this->crearSegundaHabitacionAsignada();
        $ejec = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);

        $res = $this->svc->saltarEjecucion($this->habitacionId, $this->usuarioId, 'Huésped no ha salido', $this->fecha);
        $this->assertSame($this->habitacionId, $res['habitacion_id']);

        // La ejecución se descartó.
        $this->assertNull(Database::fetchOne('SELECT id FROM ejecuciones_checklist WHERE id = ?', [$ejec->id]));

        // La habitación vuelve a 'sucia'.
        $hab = Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$this->habitacionId]);
        $this->assertSame(Habitacion::ESTADO_SUCIA, $hab['estado']);

        // Se levantó la alerta a la supervisora.
        $alerta = Database::fetchOne("SELECT * FROM alertas_activas WHERE tipo = 'habitacion_saltada'");
        $this->assertNotNull($alerta);

        // La saltada quedó al final de la cola (mayor orden_cola que la segunda).
        $ordenSaltada = (int) Database::fetchOne(
            'SELECT orden_cola FROM asignaciones WHERE habitacion_id = ? AND usuario_id = ? AND fecha = ? AND activa = 1',
            [$this->habitacionId, $this->usuarioId, $this->fecha]
        )['orden_cola'];
        $ordenSegunda = (int) Database::fetchOne(
            'SELECT orden_cola FROM asignaciones WHERE habitacion_id = ? AND usuario_id = ? AND fecha = ? AND activa = 1',
            [$segunda, $this->usuarioId, $this->fecha]
        )['orden_cola'];
        $this->assertGreaterThan($ordenSegunda, $ordenSaltada);

        // Y ahora sí puede iniciar la segunda (candado liberado).
        $e2 = $this->svc->iniciarEjecucion($segunda, $this->usuarioId, $this->fecha);
        $this->assertSame('en_progreso', $e2->estado);
    }

    public function testSaltarSinEjecucionEnProgresoLanza(): void
    {
        try {
            $this->svc->saltarEjecucion($this->habitacionId, $this->usuarioId, 'Falta un insumo', $this->fecha);
            $this->fail('Debía lanzar');
        } catch (ChecklistException $e) {
            $this->assertSame('EJECUCION_NO_ENCONTRADA', $e->codigo);
        }
    }

    public function testSaltarSinMotivoLanza(): void
    {
        $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        try {
            $this->svc->saltarEjecucion($this->habitacionId, $this->usuarioId, '   ', $this->fecha);
            $this->fail('Debía lanzar');
        } catch (ChecklistException $e) {
            $this->assertSame('MOTIVO_REQUERIDO', $e->codigo);
        }
    }

    /**
     * Regresión: una ejecución 'en_progreso' huérfana (su asignación fue desactivada
     * al reasignar la habitación) NO debe ser saltable. Si lo fuera, revertiría a
     * 'sucia' una habitación que otra persona ya limpió y auditó, violando la
     * inmutabilidad post-auditoría.
     */
    public function testSaltarNoRevierteHabitacionYaAuditada(): void
    {
        $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        // La asignación se desactiva (simula reasignación): la ejecución queda huérfana.
        Database::execute(
            'UPDATE asignaciones SET activa = 0 WHERE habitacion_id = ? AND usuario_id = ? AND fecha = ?',
            [$this->habitacionId, $this->usuarioId, $this->fecha]
        );
        // Otra persona limpió y la habitación fue aprobada.
        Database::execute("UPDATE habitaciones SET estado = 'aprobada' WHERE id = ?", [$this->habitacionId]);

        try {
            $this->svc->saltarEjecucion($this->habitacionId, $this->usuarioId, 'Huésped no ha salido', $this->fecha);
            $this->fail('Debía lanzar: la ejecución huérfana no es saltable');
        } catch (ChecklistException $e) {
            $this->assertSame('EJECUCION_NO_ENCONTRADA', $e->codigo);
        }

        // La habitación auditada NO se revirtió a 'sucia'.
        $hab = Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$this->habitacionId]);
        $this->assertSame(Habitacion::ESTADO_APROBADA, $hab['estado']);
    }

    /**
     * Al retomar y completar una habitación que había sido saltada, la alerta P2 a la
     * supervisora se resuelve (la condición desapareció): no queda colgada en la bandeja.
     */
    public function testCompletarResuelveAlertaDeSalto(): void
    {
        $this->crearSegundaHabitacionAsignada();
        $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        $this->svc->saltarEjecucion($this->habitacionId, $this->usuarioId, 'Falta un insumo', $this->fecha);
        $this->assertNotNull(
            Database::fetchOne("SELECT id FROM alertas_activas WHERE tipo = 'habitacion_saltada'"),
            'El salto debe crear la alerta'
        );

        $ejec = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        foreach ($this->svc->itemsDelTemplate($ejec->templateId) as $item) {
            if ((int) $item['obligatorio'] === 1) {
                $this->svc->marcarItem($ejec->id, (int) $item['id'], true, $this->usuarioId);
            }
        }
        $this->svc->completar($ejec->id, $this->usuarioId);

        $this->assertNull(
            Database::fetchOne("SELECT id FROM alertas_activas WHERE tipo = 'habitacion_saltada'"),
            'Completar la habitación retomada debe resolver la alerta'
        );
    }
}
