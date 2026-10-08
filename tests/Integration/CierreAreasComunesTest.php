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
 * Áreas comunes al cierre del día (v6.18, decisión de Nicolás del 07/10/2026): de la rechazada que
 * nadie volvió a pedir se avisa cada noche, y la tarjeta muestra la franja solo de lo que está en
 * curso o pasó hoy. Ver CierreDiaService::avisarAreasRechazadas() y EspacioService::franjaVisible().
 * El área que quedaba en progreso ya no se arrastra al día siguiente: desde la v6.19 la termina el
 * cierre de la noche, sin créditos (CierreSinTerminarTest).
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

    public function testAreaRechazadaSeAvisaUnaVezPorNoche(): void
    {
        $area = $this->espacios->crear('Baño recepción', '1_sur', ['Lavamanos']);
        $this->limpiarYRechazar($area);

        $r = $this->cierre->avisarAreasRechazadas($this->hoy);
        $this->cierre->avisarAreasRechazadas($this->hoy); // una segunda corrida a mano

        $this->assertSame(1, $r);
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

    public function testSoloLaPasadaDeLaNocheTerminaLoQueSigueEnCurso(): void
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

    private function estado(int $habitacionId): string
    {
        return (string) Database::fetchColumn('SELECT estado FROM habitaciones WHERE id = ?', [$habitacionId]);
    }

    /** @return list<array<string, mixed>> */
    private function avisos(int $usuarioId, string $tipo): array
    {
        return Database::fetchAll('SELECT titulo, cuerpo FROM notificaciones WHERE usuario_id = ? AND tipo = ?', [$usuarioId, $tipo]);
    }
}
