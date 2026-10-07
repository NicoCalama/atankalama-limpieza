<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\CierreDiaService;
use Atankalama\Limpieza\Services\EspacioService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cierre del día de las áreas comunes (v6.18, decisión de Nicolás del 07/10/2026): la que queda
 * en progreso sigue mañana en la cola de la misma persona, con lo que ya marcó; de la rechazada que
 * nadie volvió a pedir se avisa cada noche; y la tarjeta muestra la franja solo de lo que está en
 * curso o pasó hoy. Ver CierreDiaService::cerrarAreasComunes() y EspacioService::franjaVisible().
 */
final class CierreAreasComunesTest extends TestCase
{
    private EspacioService $espacios;
    private AsignacionService $asig;
    private ChecklistService $checklist;
    private CierreDiaService $cierre;
    private string $hoy;
    private string $manana;
    private int $ana;
    private int $sofia;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();
        [$this->ana]   = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        [$this->sofia] = TestDatabase::crearUsuario('22222222-2', 'Sofía', 'Supervisora');
        $this->hoy       = date('Y-m-d');
        $this->manana    = date('Y-m-d', strtotime('+1 day'));
        $this->espacios  = new EspacioService();
        $this->asig      = new AsignacionService();
        $this->checklist = new ChecklistService();
        $this->cierre    = new CierreDiaService();
    }

    public function testAreaEnProgresoSigueMananaPrimeraEnLaColaYConLoMarcado(): void
    {
        $area = $this->espacios->crear('Piscina', '1_sur', ['Barrer', 'Vidrios', 'Cloro']);
        $this->espacios->pedirLimpieza($area, $this->ana, $this->hoy);
        $ejecucion = $this->checklist->iniciarEjecucion($area, $this->ana, $this->hoy);
        $primerItem = (int) $this->checklist->estadoEjecucion($ejecucion->id)['items'][0]['id'];
        $this->checklist->marcarItem($ejecucion->id, $primerItem, true, $this->ana);
        // Ana ya tiene una habitación para mañana: el área tiene que quedar antes.
        $pieza = $this->crearHabitacion('305');
        $this->asig->asignarManual($pieza, $this->ana, $this->manana);

        $r = $this->cierre->cerrarAreasComunes($this->hoy, $this->manana);

        $this->assertSame(['arrastradas' => 1, 'rechazadas' => 0], $r);
        $this->assertSame([], $this->asig->colaDelTrabajador($this->ana, $this->hoy));
        $cola = $this->asig->colaDelTrabajador($this->ana, $this->manana);
        $this->assertSame([$area, $pieza], array_map(static fn(array $f): int => (int) $f['habitacion_id'], $cola));
        $actual = AsignacionService::elegirHabitacionActual($cola);
        $this->assertNotNull($actual);
        $this->assertSame($area, (int) $actual['habitacion_id']);

        // Retoma la misma limpieza, con el ítem que había marcado.
        $retomada = $this->checklist->iniciarEjecucion($area, $this->ana, $this->manana);
        $this->assertSame($ejecucion->id, $retomada->id);
        $items = $this->checklist->estadoEjecucion($retomada->id)['items'];
        $this->assertSame(1, (int) $items[0]['marcado']);
        $this->assertSame('en_progreso', $this->estado($area));

        $avisos = $this->avisos($this->sofia, CierreDiaService::NOTIF_AREAS_EN_PROGRESO);
        $this->assertCount(1, $avisos);
        $this->assertStringContainsString('Piscina (1 Sur) con Ana', $avisos[0]['cuerpo']);
        $this->assertStringContainsString('Sigue primera en su cola del ' . date('d/m', strtotime($this->manana)), $avisos[0]['cuerpo']);
        $this->assertSame([], $this->avisos($this->ana, CierreDiaService::NOTIF_AREAS_EN_PROGRESO), 'la trabajadora no recibe el aviso');
    }

    public function testLaPreasignacionDeMananaDeOtraPersonaSeCancela(): void
    {
        [$beto] = TestDatabase::crearUsuario('33333333-3', 'Beto', 'Trabajador');
        $area = $this->espacios->crear('Patio', '1_sur', ['Barrer']);
        $this->espacios->pedirLimpieza($area, $this->ana, $this->hoy);
        $this->checklist->iniciarEjecucion($area, $this->ana, $this->hoy);
        $preasignada = $this->asig->asignarManual($area, $beto, $this->manana);

        $this->cierre->cerrarAreasComunes($this->hoy, $this->manana);

        $this->assertSame(0, (int) Database::fetchColumn('SELECT activa FROM asignaciones WHERE id = ?', [$preasignada->id]));
        $this->assertSame([], $this->asig->colaDelTrabajador($beto, $this->manana));
        $this->assertCount(1, $this->asig->colaDelTrabajador($this->ana, $this->manana));
        $aviso = $this->avisos($this->sofia, CierreDiaService::NOTIF_AREAS_EN_PROGRESO)[0];
        $this->assertStringContainsString('se canceló la preasignación de mañana a Beto', $aviso['cuerpo']);
    }

    public function testAreasAprobadasOPendientesYHabitacionesNoSeTocan(): void
    {
        $pendiente = $this->espacios->crear('Bodega', '1_sur', ['Ordenar']);
        $asigPendiente = $this->espacios->pedirLimpieza($pendiente, $this->ana, $this->hoy);
        $libre = $this->espacios->crear('Terraza', '1_sur', ['Barrer']);
        // Una habitación de huésped en progreso no es asunto de este cierre (su ciclo es Cloudbeds).
        $pieza = $this->crearHabitacion('410');
        $asigPieza = $this->asig->asignarManual($pieza, $this->ana, $this->hoy);
        $this->checklist->iniciarEjecucion($pieza, $this->ana, $this->hoy);

        $r = $this->cierre->cerrarAreasComunes($this->hoy, $this->manana);

        $this->assertSame(['arrastradas' => 0, 'rechazadas' => 0], $r);
        $this->assertSame($this->hoy, $this->fechaAsignacion($asigPendiente->id));
        $this->assertSame($this->hoy, $this->fechaAsignacion($asigPieza->id));
        $this->assertSame('sucia', $this->estado($pendiente));
        $this->assertSame('aprobada', $this->estado($libre));
        $this->assertSame(0, (int) Database::fetchColumn('SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ?', [$this->sofia]));
    }

    public function testAreaRechazadaSeAvisaUnaVezPorNoche(): void
    {
        $area = $this->espacios->crear('Baño recepción', '1_sur', ['Lavamanos']);
        $this->limpiarYRechazar($area);

        $r = $this->cierre->cerrarAreasComunes($this->hoy, $this->manana);
        $this->cierre->cerrarAreasComunes($this->hoy, $this->manana); // una segunda corrida a mano

        $this->assertSame(['arrastradas' => 0, 'rechazadas' => 1], $r);
        $this->assertSame('rechazada', $this->estado($area));
        $avisos = $this->avisos($this->sofia, CierreDiaService::NOTIF_AREAS_RECHAZADAS);
        $this->assertCount(1, $avisos);
        $this->assertSame('Área rechazada sin resolver', $avisos[0]['titulo']);
        $this->assertStringContainsString(
            'Baño recepción (1 Sur), rechazada el ' . date('d/m'),
            $avisos[0]['cuerpo']
        );
        $this->assertSame([], $this->avisos($this->ana, CierreDiaService::NOTIF_AREAS_RECHAZADAS));
    }

    public function testSoloLaPasadaDeLaNocheCierraLasAreas(): void
    {
        $this->assertFalse(CierreDiaService::esPasadaNocturna(15)); // el cron de las 15:50
        $this->assertFalse(CierreDiaService::esPasadaNocturna(19));
        $this->assertTrue(CierreDiaService::esPasadaNocturna(20));
        $this->assertTrue(CierreDiaService::esPasadaNocturna(23)); // el de las 23:55
    }

    /** @return array<string, array{0: string, 1: bool, 2: ?string}> */
    public static function franjas(): array
    {
        return [
            'sin asignar y aprobada: sin franja'         => ['aprobada', false, null],
            'sin asignar y aprobada auto.: sin franja'   => ['aprobada_automatica', false, null],
            'sin asignar y sucia: sin franja'            => ['sucia', false, null],
            'asignada y sin empezar: pendiente'          => ['sucia', true, 'sucia'],
            'asignada y aprobada hoy'                    => ['aprobada', true, 'aprobada'],
            'asignada y aprobada c/obs. hoy'             => ['aprobada_con_observacion', true, 'aprobada_con_observacion'],
            'en progreso'                                => ['en_progreso', true, 'en_progreso'],
            'en progreso sin asignación de hoy'          => ['en_progreso', false, 'en_progreso'],
            'por inspeccionar'                           => ['completada_pendiente_auditoria', false, 'completada_pendiente_auditoria'],
            'rechazada sin asignar mantiene su franja'   => ['rechazada', false, 'rechazada'],
        ];
    }

    #[DataProvider('franjas')]
    public function testFranjaDeLaTarjeta(string $estado, bool $asignadaHoy, ?string $esperada): void
    {
        $this->assertSame($esperada, EspacioService::franjaVisible($estado, $asignadaHoy));
    }

    public function testListarDiceQuienLaTieneHoyYQueFranjaMostrar(): void
    {
        $libre = $this->espacios->crear('Terraza', '1_sur', ['Barrer']);
        $pedida = $this->espacios->crear('Bodega', '1_sur', ['Ordenar']);
        $this->espacios->pedirLimpieza($pedida, $this->ana, $this->hoy);
        $rechazada = $this->espacios->crear('Piscina', '1_sur', ['Cloro']);
        $this->limpiarYRechazar($rechazada);
        // Preasignada para hoy y todavía «aprobada»: nadie abrió una cola ni el tablero.
        $preasignada = $this->espacios->crear('Patio', '1_sur', ['Barrer']);
        $asignacion = $this->asig->asignarManual($preasignada, $this->ana, $this->manana);
        Database::execute('UPDATE asignaciones SET fecha = ? WHERE id = ?', [$this->hoy, $asignacion->id]);

        $porId = array_column($this->espacios->listar('1_sur'), null, 'id');

        $this->assertNull($porId[$libre]['asignado_a_nombre']);
        $this->assertFalse($porId[$libre]['asignada_hoy']);
        $this->assertNull($porId[$libre]['franja']);
        $this->assertSame('Ana', $porId[$pedida]['asignado_a_nombre']);
        $this->assertSame('sucia', $porId[$pedida]['franja']);
        // La asignación de la limpieza rechazada era de hoy; mañana ya no lo será, pero la franja sigue.
        $this->assertSame('rechazada', $porId[$rechazada]['franja']);
        $this->assertSame('sucia', $porId[$preasignada]['estado']);
        $this->assertSame('Ana', $porId[$preasignada]['asignado_a_nombre']);
        $this->assertSame('sucia', $porId[$preasignada]['franja'], 'pendiente, no la aprobación de la limpieza anterior');

        $csv = $this->espacios->exportarCsv('1_sur');
        $this->assertStringContainsString('"Bodega";"Pendiente";"Ana"', $csv);
        $this->assertStringContainsString('"Terraza";"Sin asignar";""', $csv);
        $this->assertStringContainsString('"Piscina";"Rechazada"', $csv);
    }

    private function limpiarYRechazar(int $area): void
    {
        $this->espacios->pedirLimpieza($area, $this->ana, $this->hoy);
        $ejecucion = $this->checklist->iniciarEjecucion($area, $this->ana, $this->hoy);
        $items = array_map(static fn(array $i): int => (int) $i['id'], $this->checklist->estadoEjecucion($ejecucion->id)['items']);
        foreach ($items as $item) {
            $this->checklist->marcarItem($ejecucion->id, $item, true, $this->ana);
        }
        $this->checklist->completar($ejecucion->id, $this->ana);
        (new AuditoriaService())->emitirVeredicto($area, $this->sofia, Auditoria::VEREDICTO_RECHAZADO, 'Quedó sucio', [$items[0]]);
    }

    private function crearHabitacion(string $numero): int
    {
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado)
             VALUES ((SELECT id FROM hoteles WHERE codigo = '1_sur'), ?, (SELECT id FROM tipos_habitacion WHERE nombre = 'Doble'), 'sucia')",
            [$numero]
        );
        return Database::lastInsertId();
    }

    private function estado(int $habitacionId): string
    {
        return (string) Database::fetchColumn('SELECT estado FROM habitaciones WHERE id = ?', [$habitacionId]);
    }

    private function fechaAsignacion(int $asignacionId): string
    {
        return (string) Database::fetchColumn('SELECT fecha FROM asignaciones WHERE id = ?', [$asignacionId]);
    }

    /** @return list<array<string, mixed>> */
    private function avisos(int $usuarioId, string $tipo): array
    {
        return Database::fetchAll('SELECT titulo, cuerpo FROM notificaciones WHERE usuario_id = ? AND tipo = ?', [$usuarioId, $tipo]);
    }
}
