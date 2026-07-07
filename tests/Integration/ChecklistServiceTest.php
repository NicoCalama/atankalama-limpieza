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
use Atankalama\Limpieza\Services\UsuarioService;
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

    /**
     * Regresión del deadlock del candado: si una habitación en curso se REASIGNA a otra
     * persona (la asignación del trabajador queda inactiva) y luego se audita, la ejecución
     * del trabajador queda huérfana pero NO debe contar para el candado una-a-la-vez; de lo
     * contrario el trabajador queda trabado sin poder iniciar, saltar ni completar nada.
     */
    public function testCandadoNoBloqueaPorEjecucionDeAsignacionReasignada(): void
    {
        // A empieza la 101 (asignación activa).
        $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);

        // La supervisora reasigna la 101 a otra persona: la asignación de A se desactiva,
        // pero su ejecución queda 'en_progreso' (huérfana), colgando de una asignación
        // con activa=0 y la fecha de hoy.
        Database::execute(
            'UPDATE asignaciones SET activa = 0 WHERE habitacion_id = ? AND usuario_id = ? AND fecha = ?',
            [$this->habitacionId, $this->usuarioId, $this->fecha]
        );
        // La otra persona limpió y la 101 quedó aprobada (inmutable).
        Database::execute("UPDATE habitaciones SET estado = 'aprobada' WHERE id = ?", [$this->habitacionId]);

        // A tiene una segunda habitación válidamente asignada hoy.
        $segunda = $this->crearSegundaHabitacionAsignada();

        // El candado NO debe contar la ejecución huérfana: A puede empezar la segunda.
        $ejec = $this->svc->iniciarEjecucion($segunda, $this->usuarioId, $this->fecha);
        $this->assertSame('en_progreso', $ejec->estado);
    }

    /**
     * Regresión del bug (8): el controller deriva la fecha del SERVIDOR e ignora la del
     * body. Si confiara en el body, mandar una fecha arbitraria eludiría el candado
     * una-a-la-vez (que se acota por fecha). Aquí, con la 101 en curso hoy, iniciar otra
     * pieza mandando otra fecha en el body debe seguir chocando con el candado (409).
     */
    public function testControllerIniciarIgnoraFechaDelBody(): void
    {
        $hoy = date('Y-m-d');
        $habA = $this->crearSegundaHabitacionAsignada('301', $hoy);
        $habB = $this->crearSegundaHabitacionAsignada('302', $hoy);

        // A queda en progreso hoy.
        $this->svc->iniciarEjecucion($habA, $this->usuarioId, $hoy);

        // Intento de bypass: iniciar B por el controller con una fecha falsa en el body.
        $usuario = (new UsuarioService())->buscarPorId($this->usuarioId);
        $req = new Request(
            metodo: 'POST',
            path: "/api/habitaciones/{$habB}/iniciar",
            cuerpo: ['fecha' => '2999-12-31'],
            ruta: ['id' => (string) $habB],
        );
        $req->usuario = $usuario;

        $resp = (new ChecklistsController())->iniciar($req);

        // El candado sigue aplicando porque el controller usó la fecha de hoy, no la del body.
        $this->assertSame(409, $resp->status);
        $this->assertStringContainsString('YA_TIENE_HABITACION_EN_PROGRESO', $resp->cuerpo);
    }

    /**
     * Los espacios comunes (es_espacio_comun=1) NO se eximen del candado una-a-la-vez
     * (funcionan como habitaciones), y al saltarlos el texto de la alerta se adapta.
     */
    public function testEspacioComunBajoCandadoYTextoDeSalto(): void
    {
        $espacioId = $this->crearEspacioComunAsignado('Piscina');
        $this->svc->iniciarEjecucion($espacioId, $this->usuarioId, $this->fecha);

        // Con el espacio en progreso, el candado bloquea iniciar la habitación 101.
        try {
            $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
            $this->fail('El espacio común debe contar para el candado una-a-la-vez');
        } catch (ChecklistException $e) {
            $this->assertSame('YA_TIENE_HABITACION_EN_PROGRESO', $e->codigo);
        }

        // Al saltar el espacio, el texto se adapta ("Espacio ..." en vez de "Habitación ...").
        $res = $this->svc->saltarEjecucion($espacioId, $this->usuarioId, 'Requiere mantención', $this->fecha);
        $this->assertSame($espacioId, $res['habitacion_id']);

        $alerta = Database::fetchOne("SELECT titulo, descripcion FROM alertas_activas WHERE tipo = 'habitacion_saltada'");
        $this->assertNotNull($alerta);
        $this->assertSame('Espacio Piscina saltado', $alerta['titulo']);
        $this->assertStringContainsString('el espacio Piscina', $alerta['descripcion']);
    }

    /**
     * El cap de 200 caracteres del motivo se aplica en el servidor (por API se puede
     * exceder el maxlength=200 del textarea): el motivo devuelto y el persistido en la
     * alerta quedan truncados. Usa un motivo multibyte para ejercitar mb_substr.
     */
    public function testSaltarTruncaMotivoLargo(): void
    {
        $this->crearSegundaHabitacionAsignada();
        $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);

        $motivoLargo = str_repeat('á', 250);
        $res = $this->svc->saltarEjecucion($this->habitacionId, $this->usuarioId, $motivoLargo, $this->fecha);

        $this->assertSame(200, mb_strlen($res['motivo']));

        $alerta = Database::fetchOne("SELECT contexto_json FROM alertas_activas WHERE tipo = 'habitacion_saltada'");
        $ctx = json_decode((string) $alerta['contexto_json'], true);
        $this->assertSame(200, mb_strlen($ctx['motivo']));
    }

    // -----------------------------------------------------------------------
    // Gap "e": orden obligatorio de la cola + "habitación actual"
    // -----------------------------------------------------------------------

    /** Marca todos los obligatorios y completa la ejecución de la habitación indicada. */
    private function completarHabitacion(int $habitacionId): void
    {
        $ejec = $this->svc->iniciarEjecucion($habitacionId, $this->usuarioId, $this->fecha);
        foreach ($this->svc->itemsDelTemplate($ejec->templateId) as $item) {
            if ((int) $item['obligatorio'] === 1) {
                $this->svc->marcarItem($ejec->id, (int) $item['id'], true, $this->usuarioId);
            }
        }
        $this->svc->completar($ejec->id, $this->usuarioId);
    }

    public function testHabitacionActualDeColaEsLaPrimeraPendienteYSaltaCompletadas(): void
    {
        $segunda = $this->crearSegundaHabitacionAsignada(); // 102, orden_cola 2

        // Con ambas pendientes, la actual es la primera de la cola (101).
        $actual = $this->asignaciones->habitacionActualDeCola($this->usuarioId, $this->fecha);
        $this->assertSame($this->habitacionId, (int) $actual['habitacion_id']);

        // Completada la 101, la actual avanza a la 102.
        $this->completarHabitacion($this->habitacionId);
        $actual = $this->asignaciones->habitacionActualDeCola($this->usuarioId, $this->fecha);
        $this->assertSame($segunda, (int) $actual['habitacion_id']);

        // Completadas ambas, no queda actual.
        $this->completarHabitacion($segunda);
        $this->assertNull($this->asignaciones->habitacionActualDeCola($this->usuarioId, $this->fecha));
    }

    public function testExigirOrdenBloqueaIniciarFueraDeOrden(): void
    {
        $segunda = $this->crearSegundaHabitacionAsignada(); // 102, no es la actual (lo es la 101)

        $this->expectException(ChecklistException::class);
        $this->expectExceptionMessage('Debes empezar por tu habitación actual.');
        try {
            $this->svc->iniciarEjecucion($segunda, $this->usuarioId, $this->fecha, true);
        } catch (ChecklistException $e) {
            $this->assertSame('NO_ES_TU_HABITACION_ACTUAL', $e->codigo);
            $this->assertSame(409, $e->httpStatus);
            // No dejó ejecución colgando ni tocó el estado de la 102.
            $hab = Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$segunda]);
            $this->assertSame('sucia', $hab['estado']);
            throw $e;
        }
    }

    public function testExigirOrdenPermiteIniciarLaHabitacionActual(): void
    {
        $this->crearSegundaHabitacionAsignada();

        $ejec = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha, true);
        $this->assertSame('en_progreso', $ejec->estado);
    }

    public function testExigirOrdenPermiteReanudarLaEnProgreso(): void
    {
        $this->crearSegundaHabitacionAsignada();

        $e1 = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha, true);
        // Re-iniciar la misma (en progreso, sigue siendo la actual) es idempotente, no bloquea.
        $e2 = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha, true);
        $this->assertSame($e1->id, $e2->id);
    }

    public function testSinExigirOrdenPermiteIniciarFueraDeOrden(): void
    {
        // Rol con habitaciones.ver_todas (supervisora/admin): el controller pasa
        // exigirOrden=false y puede iniciar cualquier asignada, sin candado de orden.
        $segunda = $this->crearSegundaHabitacionAsignada();

        $ejec = $this->svc->iniciarEjecucion($segunda, $this->usuarioId, $this->fecha, false);
        $this->assertSame('en_progreso', $ejec->estado);
    }

    // -----------------------------------------------------------------------
    // Editor de templates por tipo (checklists.editar) + peso de créditos
    // -----------------------------------------------------------------------

    private function templateDelTipo(): int
    {
        $tipoId = (int) Database::fetchOne('SELECT id FROM tipos_habitacion LIMIT 1')['id'];
        return (int) $this->svc->templateParaTipo($tipoId);
    }

    public function testEditarTemplateActualizaInPlaceConservandoIds(): void
    {
        $tid = $this->templateDelTipo();
        $items = $this->svc->itemsDelTemplate($tid);
        $ids = array_map(static fn($i) => (int) $i['id'], $items);

        // Reenvía TODOS los ítems con su id, cambiando descripción y peso del primero.
        $payload = [];
        foreach ($items as $i) {
            $payload[] = [
                'id' => (int) $i['id'],
                'descripcion' => (int) $i['id'] === $ids[0] ? 'Descripción editada' : $i['descripcion'],
                'obligatorio' => (int) $i['obligatorio'] === 1,
                'creditos' => (int) $i['id'] === $ids[0] ? 3 : (int) $i['creditos'],
            ];
        }
        $this->svc->editarTemplate($tid, null, $payload, $this->usuarioId);

        $despues = $this->svc->itemsDelTemplate($tid);
        // Mismos ids en el mismo orden (se actualizaron in-place, no se recrearon).
        $this->assertSame($ids, array_map(static fn($i) => (int) $i['id'], $despues));
        $this->assertSame('Descripción editada', $despues[0]['descripcion']);
        $this->assertSame(3, (int) $despues[0]['creditos']);
    }

    public function testEditarTemplateAgregaYDesactivaItemsSinBorrar(): void
    {
        $tid = $this->templateDelTipo();
        $items = $this->svc->itemsDelTemplate($tid);
        $total = count($items);

        // Conserva todos menos el último, y agrega uno nuevo (sin id).
        $payload = [];
        foreach (array_slice($items, 0, $total - 1) as $i) {
            $payload[] = ['id' => (int) $i['id'], 'descripcion' => $i['descripcion'], 'obligatorio' => (int) $i['obligatorio'] === 1, 'creditos' => (int) $i['creditos']];
        }
        $payload[] = ['descripcion' => 'Ítem nuevo', 'obligatorio' => true, 'creditos' => 2];

        $this->svc->editarTemplate($tid, null, $payload, $this->usuarioId);

        $activos = $this->svc->itemsDelTemplate($tid);
        $this->assertCount($total, $activos); // -1 quitado + 1 nuevo
        $this->assertContains('Ítem nuevo', array_map(static fn($i) => $i['descripcion'], $activos));

        // El quitado NO se borró: sigue en la tabla con activo=0 (preserva histórico, FK RESTRICT).
        $quitadoId = (int) $items[$total - 1]['id'];
        $fila = Database::fetchOne('SELECT activo FROM items_checklist WHERE id = ?', [$quitadoId]);
        $this->assertNotNull($fila);
        $this->assertSame(0, (int) $fila['activo']);
    }

    public function testEditarTemplateOpcionalNoGuardaCreditosAunqueSeEnvien(): void
    {
        $tid = $this->templateDelTipo();
        $items = $this->svc->itemsDelTemplate($tid);

        // Reenvía todos; el primero pasa a OPCIONAL pero con un peso "sucio" de 50.
        $payload = [];
        foreach ($items as $idx => $i) {
            $payload[] = [
                'id' => (int) $i['id'],
                'descripcion' => $i['descripcion'],
                'obligatorio' => $idx !== 0,
                'creditos' => $idx === 0 ? 50 : (int) $i['creditos'],
            ];
        }
        $this->svc->editarTemplate($tid, null, $payload, $this->usuarioId);

        $primero = Database::fetchOne('SELECT obligatorio, creditos FROM items_checklist WHERE id = ?', [(int) $items[0]['id']]);
        $this->assertSame(0, (int) $primero['obligatorio']);
        // El servidor normaliza a 0: los opcionales no llevan crédito, aunque el cliente mande 50.
        $this->assertSame(0, (int) $primero['creditos']);
    }

    public function testEditarTemplateVacioLanza(): void
    {
        try {
            $this->svc->editarTemplate($this->templateDelTipo(), null, [], $this->usuarioId);
            $this->fail('Debía lanzar');
        } catch (ChecklistException $e) {
            $this->assertSame('CHECKLIST_VACIO', $e->codigo);
        }
    }

    public function testEditarTemplateItemAjenoLanza(): void
    {
        try {
            $this->svc->editarTemplate($this->templateDelTipo(), null, [
                ['id' => 999999, 'descripcion' => 'x', 'obligatorio' => true, 'creditos' => 1],
            ], $this->usuarioId);
            $this->fail('Debía lanzar');
        } catch (ChecklistException $e) {
            $this->assertSame('ITEM_AJENO', $e->codigo);
        }
    }

    public function testEditarTemplateDeEspacioNoSePermiteAca(): void
    {
        // Un template propio de espacio (habitacion_id != NULL) NO se edita por esta vía:
        // los espacios se editan desde áreas comunes. Se le crea su template a mano.
        $espacioId = $this->crearEspacioComunAsignado('Piscina');
        $tipoId = (int) Database::fetchOne('SELECT id FROM tipos_habitacion LIMIT 1')['id'];
        Database::execute(
            "INSERT INTO checklists_template (tipo_habitacion_id, habitacion_id, nombre) VALUES (?, ?, 'Checklist — Piscina')",
            [$tipoId, $espacioId]
        );
        $tid = (int) Database::lastInsertId();

        try {
            $this->svc->editarTemplate($tid, null, [
                ['descripcion' => 'x', 'obligatorio' => true, 'creditos' => 1],
            ], $this->usuarioId);
            $this->fail('Debía lanzar: los templates de espacio se editan desde áreas comunes');
        } catch (ChecklistException $e) {
            $this->assertSame('TEMPLATE_NO_ENCONTRADO', $e->codigo);
        }
    }

    public function testEditarTemplateSeReflejaEnEjecucionEnProgreso(): void
    {
        // Ejecución en curso; editar el template debe verse en vivo (la ejecución filtra activo=1).
        $ejec = $this->svc->iniciarEjecucion($this->habitacionId, $this->usuarioId, $this->fecha);
        $items = $this->svc->itemsDelTemplate($ejec->templateId);
        $primeroId = (int) $items[0]['id'];
        $this->svc->marcarItem($ejec->id, $primeroId, true, $this->usuarioId);

        // Renombra el primero (mismo id) y quita el último.
        $payload = [];
        foreach (array_slice($items, 0, count($items) - 1) as $i) {
            $payload[] = [
                'id' => (int) $i['id'],
                'descripcion' => (int) $i['id'] === $primeroId ? 'Renombrado' : $i['descripcion'],
                'obligatorio' => (int) $i['obligatorio'] === 1,
                'creditos' => (int) $i['creditos'],
            ];
        }
        $this->svc->editarTemplate($ejec->templateId, null, $payload, $this->usuarioId);

        $porId = [];
        foreach ($this->svc->estadoEjecucion($ejec->id)['items'] as $it) {
            $porId[(int) $it['id']] = $it;
        }
        // El primero conserva su marca (mismo id) y muestra la nueva descripción.
        $this->assertArrayHasKey($primeroId, $porId);
        $this->assertSame('Renombrado', $porId[$primeroId]['descripcion']);
        $this->assertSame(1, (int) $porId[$primeroId]['marcado']);
    }

    /**
     * Crea un espacio común 'sucio' asignado al mismo trabajador y retorna su id.
     */
    private function crearEspacioComunAsignado(string $numero = 'Piscina'): int
    {
        $hotelId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $tipoId = (int) Database::fetchOne('SELECT id FROM tipos_habitacion LIMIT 1')['id'];
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado, es_espacio_comun) VALUES (?, ?, ?, 'sucia', 1)",
            [$hotelId, $numero, $tipoId]
        );
        $id = Database::lastInsertId();
        $this->asignaciones->asignarManual($id, $this->usuarioId, $this->fecha);
        return $id;
    }
}
