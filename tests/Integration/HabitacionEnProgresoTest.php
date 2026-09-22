<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\AsignacionesController;
use Atankalama\Limpieza\Controllers\EspaciosController;
use Atankalama\Limpieza\Controllers\HomeController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Models\Usuario;
use Atankalama\Limpieza\Services\AsignacionException;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\UsuarioService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Piezas EN PROGRESO (22/09/2026):
 *  - La "habitación actual" del trabajador es la que tiene en curso, aunque una pendiente quede
 *    antes en su cola (una rechazada que conserva su lugar, una que la supervisora sube). Antes
 *    el Inicio le mostraba la pendiente, el candado no lo dejaba empezarla y la ficha no le
 *    cargaba el checklist de la que tenía en curso: quedaba trabado.
 *  - Reasignarlas o quitarlas exige asignaciones.mover_en_progreso (Admin por defecto).
 *
 * Usa la fecha de HOY: la regla y el Inicio miran la asignación del día.
 */
final class HabitacionEnProgresoTest extends TestCase
{
    private AsignacionService $asignaciones;
    private ChecklistService $checklist;
    private string $hoy;
    private int $hotelId;
    private int $tipoId;
    private int $ana;
    private int $bea;
    private int $sofia; // Supervisora: asigna, pero sin asignaciones.mover_en_progreso
    private int $admin;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();
        $this->hotelId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $this->tipoId = (int) Database::fetchOne('SELECT id FROM tipos_habitacion LIMIT 1')['id'];

        [$this->ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        [$this->bea] = TestDatabase::crearUsuario('22222222-2', 'Bea', 'Trabajador');
        [$this->sofia] = TestDatabase::crearUsuario('33333333-3', 'Sofía', 'Supervisora');
        [$this->admin] = TestDatabase::crearUsuario('44444444-4', 'Admin', 'Admin');

        $this->hoy = date('Y-m-d');
        $this->asignaciones = new AsignacionService();
        $this->checklist = new ChecklistService();
    }

    // --- Habitación actual -------------------------------------------------------------

    public function testRechazoDeUnaTerminadaNoTrabaALaQueEstaLimpiando(): void
    {
        [$h101, $h102] = $this->asignarA($this->ana, '101', '102');
        $itemFallido = $this->terminar($h101, $this->ana);
        $ejec102 = $this->checklist->iniciarEjecucion($h102, $this->ana, $this->hoy, true);
        (new AuditoriaService())->emitirVeredicto($h101, $this->sofia, 'rechazado', 'Polvo en el velador', [$itemFallido]);

        // La actual es la que tiene en curso, no la rechazada (que conserva el primer lugar).
        $this->assertSame($h102, $this->actualDe($this->ana));

        // El Inicio le muestra la 102...
        $home = $this->json((new HomeController())->trabajador($this->req($this->ana)));
        $this->assertSame('102', $home['data']['habitacion_actual']['numero']);

        // ...y la ficha la reconoce como suya (a la trabajadora la cola le trae solo la actual).
        $cola = $this->json((new AsignacionesController())->colaTrabajador(
            $this->req($this->ana, ruta: ['id' => (string) $this->ana])
        ))['data']['cola'];
        $this->assertSame([$h102], array_map(fn(array $a): int => (int) $a['habitacion_id'], $cola));

        // Retomarla devuelve su misma ejecución; al terminarla le toca rehacer la rechazada.
        $this->assertSame($ejec102->id, $this->checklist->iniciarEjecucion($h102, $this->ana, $this->hoy, true)->id);
        $this->terminar($h102, $this->ana);
        $this->assertSame($h101, $this->actualDe($this->ana));
        $this->assertSame('en_progreso', $this->checklist->iniciarEjecucion($h101, $this->ana, $this->hoy, true)->estado);
    }

    public function testSubirUnaPendienteSobreLaQueEstaLimpiandoNoCambiaLaActual(): void
    {
        [$h101, $h102] = $this->asignarA($this->ana, '101', '102');
        $this->checklist->iniciarEjecucion($h101, $this->ana, $this->hoy, true);

        $this->asignaciones->reordenarCola($this->ana, $this->hoy, [$h102, $h101]);

        $this->assertSame($h101, $this->actualDe($this->ana));
    }

    public function testUnaPiezaMovidaAMedioLimpiarNoLeGanaALaPropiaEnCurso(): void
    {
        // Bea deja la 201 a medio limpiar; Ana tiene la 101 en curso.
        [$h201] = $this->asignarA($this->bea, '201');
        $this->checklist->iniciarEjecucion($h201, $this->bea, $this->hoy, true);
        [$h101] = $this->asignarA($this->ana, '101');
        $this->checklist->iniciarEjecucion($h101, $this->ana, $this->hoy, true);

        // El admin le pasa la 201 a Ana y se la sube al primer lugar.
        $this->asignaciones->reasignar($h201, $this->ana, $this->hoy, 'test', $this->admin, puedeMoverEnProgreso: true);
        $this->asignaciones->reordenarCola($this->ana, $this->hoy, [$h201, $h101]);

        // Sigue siendo la 101: la única con una ejecución suya en curso (la que exige el candado).
        $this->assertSame($h101, $this->actualDe($this->ana));
    }

    // --- Mover una pieza en progreso: solo con asignaciones.mover_en_progreso --------------

    public function testSoloAdminTraeElPermisoPorDefecto(): void
    {
        $this->assertTrue($this->usuario($this->admin)->tienePermiso('asignaciones.mover_en_progreso'));
        $this->assertFalse($this->usuario($this->sofia)->tienePermiso('asignaciones.mover_en_progreso'));
        $this->assertTrue($this->usuario($this->sofia)->tienePermiso('asignaciones.asignar_manual'));
    }

    public function testSupervisoraNoPuedeReasignarQuitarNiAsignarEncimaDeUnaEnProgreso(): void
    {
        [$h101] = $this->asignarA($this->ana, '101');
        $ejec = $this->checklist->iniciarEjecucion($h101, $this->ana, $this->hoy, true);
        $ctrl = new AsignacionesController();

        $reasignar = $ctrl->reasignar($this->req($this->sofia, ['habitacion_id' => $h101, 'usuario_id' => $this->bea, 'fecha' => $this->hoy]));
        $quitar = $ctrl->desasignar($this->req($this->sofia, ['habitacion_id' => $h101, 'fecha' => $this->hoy]));
        $asignar = $ctrl->crear($this->req($this->sofia, ['habitacion_ids' => [$h101], 'usuario_id' => $this->bea, 'fecha' => $this->hoy]));

        foreach ([$reasignar, $quitar, $asignar] as $resp) {
            $this->assertSame(403, $resp->status);
            $this->assertSame('HABITACION_EN_PROGRESO', $this->json($resp)['error']['codigo']);
        }
        $this->assertStringContainsString('La habitación 101 está en progreso', $this->json($reasignar)['error']['mensaje']);

        // Nada cambió: sigue siendo de Ana, en progreso y con su misma ejecución.
        $this->assertSame($this->ana, $this->asignaciones->obtenerActivaDeHabitacion($h101, $this->hoy)?->usuarioId);
        $this->assertSame('en_progreso', $this->estadoDe($h101));
        $this->assertSame($ejec->id, $this->checklist->iniciarEjecucion($h101, $this->ana, $this->hoy, true)->id);
    }

    public function testAdminSiPuedeReasignarYQuitarUnaEnProgreso(): void
    {
        [$h101, $h102] = $this->asignarA($this->ana, '101', '102');
        $this->checklist->iniciarEjecucion($h101, $this->ana, $this->hoy, true);
        $ctrl = new AsignacionesController();

        $reasignar = $ctrl->reasignar($this->req($this->admin, ['habitacion_id' => $h101, 'usuario_id' => $this->bea, 'fecha' => $this->hoy]));
        $this->assertSame(201, $reasignar->status);
        $this->assertSame($this->bea, $this->asignaciones->obtenerActivaDeHabitacion($h101, $this->hoy)?->usuarioId);

        // Con la 101 fuera, Ana sigue con la 102; el admin también puede quitársela a medio limpiar.
        $this->checklist->iniciarEjecucion($h102, $this->ana, $this->hoy, true);
        $quitar = $ctrl->desasignar($this->req($this->admin, ['habitacion_id' => $h102, 'fecha' => $this->hoy]));
        $this->assertSame(200, $quitar->status);
        $this->assertSame('sucia', $this->estadoDe($h102));
    }

    public function testSupervisoraSigueMoviendoPendientesYRechazadas(): void
    {
        [$h101, $h102] = $this->asignarA($this->ana, '101', '102');
        $itemFallido = $this->terminar($h101, $this->ana);
        (new AuditoriaService())->emitirVeredicto($h101, $this->sofia, 'rechazado', 'Polvo en el velador', [$itemFallido]);
        $ctrl = new AsignacionesController();

        $reasignar = $ctrl->reasignar($this->req($this->sofia, ['habitacion_id' => $h101, 'usuario_id' => $this->bea, 'fecha' => $this->hoy]));
        $quitar = $ctrl->desasignar($this->req($this->sofia, ['habitacion_id' => $h102, 'fecha' => $this->hoy]));

        $this->assertSame(201, $reasignar->status);
        $this->assertSame(200, $quitar->status);
    }

    public function testLoteConUnaEnProgresoNoAsignaNinguna(): void
    {
        [$h101] = $this->asignarA($this->ana, '101');
        $this->checklist->iniciarEjecucion($h101, $this->ana, $this->hoy, true);
        $h103 = $this->crearPieza('103');

        $resp = (new AsignacionesController())->crear(
            $this->req($this->sofia, ['habitacion_ids' => [$h103, $h101], 'usuario_id' => $this->bea, 'fecha' => $this->hoy])
        );

        $this->assertSame(403, $resp->status);
        $this->assertNull($this->asignaciones->obtenerActivaDeHabitacion($h103, $this->hoy));
    }

    public function testPlanificarUnaEnProgresoParaOtroDiaNoSeBloquea(): void
    {
        [$h101] = $this->asignarA($this->ana, '101');
        $this->checklist->iniciarEjecucion($h101, $this->ana, $this->hoy, true);
        $manana = date('Y-m-d', strtotime('+1 day'));

        $resp = (new AsignacionesController())->crear(
            $this->req($this->sofia, ['habitacion_ids' => [$h101], 'usuario_id' => $this->bea, 'fecha' => $manana])
        );

        $this->assertSame(201, $resp->status);
        $this->assertSame($this->ana, $this->asignaciones->obtenerActivaDeHabitacion($h101, $this->hoy)?->usuarioId);
    }

    public function testPorDefectoElServicioNoMueveUnaEnProgreso(): void
    {
        // Seguro por omisión: un llamador que no pasa el permiso (copilot, uno nuevo) no la mueve.
        [$h101] = $this->asignarA($this->ana, '101');
        $this->checklist->iniciarEjecucion($h101, $this->ana, $this->hoy, true);

        $this->expectException(AsignacionException::class);
        $this->asignaciones->asignarManual($h101, $this->bea, $this->hoy, $this->sofia);
    }

    public function testPedirLimpiezaDeUnAreaEnProgresoSoloAdmin(): void
    {
        $area = $this->crearPieza('Piscina', esEspacio: true);
        $this->asignaciones->asignarManual($area, $this->ana, $this->hoy);
        // Estado forzado: la regla mira el estado y la asignación de hoy, no la ejecución.
        Database::execute("UPDATE habitaciones SET estado = 'en_progreso' WHERE id = ?", [$area]);
        $ctrl = new EspaciosController();
        $cuerpo = ['usuario_id' => $this->bea, 'fecha' => $this->hoy];

        $sup = $ctrl->pedirLimpieza($this->req($this->sofia, $cuerpo, ['id' => (string) $area]));
        $this->assertSame(403, $sup->status);
        $this->assertStringContainsString('El área Piscina está en progreso', $this->json($sup)['error']['mensaje']);

        $adm = $ctrl->pedirLimpieza($this->req($this->admin, $cuerpo, ['id' => (string) $area]));
        $this->assertSame(200, $adm->status);
    }

    // --- Helpers -----------------------------------------------------------------------

    private function crearPieza(string $numero, bool $esEspacio = false): int
    {
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado, es_espacio_comun) VALUES (?, ?, ?, 'sucia', ?)",
            [$this->hotelId, $numero, $this->tipoId, $esEspacio ? 1 : 0]
        );
        return Database::lastInsertId();
    }

    /**
     * Crea las piezas y se las asigna en ese orden (orden_cola 1, 2, ...).
     *
     * @return list<int>
     */
    private function asignarA(int $usuarioId, string ...$numeros): array
    {
        $ids = [];
        foreach ($numeros as $numero) {
            $id = $this->crearPieza($numero);
            $this->asignaciones->asignarManual($id, $usuarioId, $this->hoy);
            $ids[] = $id;
        }
        return $ids;
    }

    /** Limpia la pieza completa y devuelve un ítem obligatorio (para poder rechazarla). */
    private function terminar(int $habitacionId, int $usuarioId): int
    {
        $ejec = $this->checklist->iniciarEjecucion($habitacionId, $usuarioId, $this->hoy, true);
        $itemId = null;
        foreach ($this->checklist->itemsDelTemplate($ejec->templateId) as $item) {
            if ((int) $item['obligatorio'] === 1) {
                $this->checklist->marcarItem($ejec->id, (int) $item['id'], true, $usuarioId);
                $itemId ??= (int) $item['id'];
            }
        }
        $this->checklist->completar($ejec->id, $usuarioId);
        return (int) $itemId;
    }

    private function actualDe(int $usuarioId): ?int
    {
        $actual = $this->asignaciones->habitacionActualDeCola($usuarioId, $this->hoy);
        return $actual === null ? null : (int) $actual['habitacion_id'];
    }

    private function estadoDe(int $habitacionId): string
    {
        return (string) Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$habitacionId])['estado'];
    }

    private function usuario(int $id): Usuario
    {
        return (new UsuarioService())->buscarPorId($id) ?? throw new \RuntimeException("Usuario {$id} no existe");
    }

    /**
     * @param array<string, mixed> $cuerpo
     * @param array<string, string> $ruta
     */
    private function req(int $actorId, array $cuerpo = [], array $ruta = []): Request
    {
        $req = new Request(metodo: 'POST', path: '/test', cuerpo: $cuerpo, ruta: $ruta);
        $req->usuario = $this->usuario($actorId);
        return $req;
    }

    /** @return array<string, mixed> */
    private function json(Response $resp): array
    {
        return json_decode($resp->cuerpo, true);
    }
}
