<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\HabitacionException;
use Atankalama\Limpieza\Services\HabitacionService;
use Atankalama\Limpieza\Services\HoraTardeService;
use Atankalama\Limpieza\Tests\Support\EscenarioCicloLimpieza;
use PHPUnit\Framework\TestCase;

/**
 * v7.23 (reunión del 08/11/2026): chapa Día / Noche / Día-Noche y hora de la tarde configurable.
 *  - Noche no entra al barrido; Día/Noche es lo que era «nochero» (se barre desde la hora de la tarde).
 *  - La hora de la tarde sale de Ajustes (16:00 por defecto) y la usan el barrido y el marcado inmediato.
 *  - Una marca vencida vuelve a Día, y al planificar un día futuro no cuenta si vence antes.
 */
final class ChapaHoraTardeV723Test extends TestCase
{
    use EscenarioCicloLimpieza;

    protected function setUp(): void
    {
        $this->prepararEscenario();
    }

    private function fila(string $numero): array
    {
        return Database::fetchOne('SELECT es_nochero, nochero_hasta, nochero_modo FROM habitaciones WHERE id = ?', [$this->hab[$numero]]);
    }

    // ─────────────────────────── La chapa ───────────────────────────

    public function testMarcarNocheYDiaNocheYVolverADia(): void
    {
        $h = $this->habs->marcarNochero($this->hab['101'], $this->hoy, $this->sofia, null, '09:00', Habitacion::NOCHERO_NOCHE);
        $this->assertSame('noche', $h->chapa());
        $this->assertSame('noche', $h->toArray()['chapa']);
        $this->assertSame(['es_nochero' => 1, 'nochero_hasta' => $this->hoy, 'nochero_modo' => 'noche'], array_map(
            static fn($v) => is_numeric($v) && strlen((string) $v) === 1 ? (int) $v : $v,
            $this->fila('101')
        ));

        // Sin modo = Día/Noche (lo que hacía la «N» hasta la v7.22).
        $this->assertSame('dia_noche', $this->habs->marcarNochero($this->hab['102'], $this->hoy, $this->sofia, null, '09:00')->chapa());

        $this->assertSame('dia', $this->habs->desmarcarNochero($this->hab['101'], $this->sofia)->chapa());
        $this->assertSame('dia_noche', $this->fila('101')['nochero_modo'], 'sin marca el modo vuelve al de siempre');

        $this->expectException(HabitacionException::class);
        $this->habs->marcarNochero($this->hab['103'], $this->hoy, $this->sofia, null, '09:00', 'tarde');
    }

    public function testLasMarcasDeAntesQuedanDiaNoche(): void
    {
        Database::execute('UPDATE habitaciones SET es_nochero = 1, nochero_hasta = ? WHERE id = ?', [$this->hoy, $this->hab['101']]);
        $this->assertSame('dia_noche', $this->fila('101')['nochero_modo']);
        $this->assertSame('dia_noche', $this->habs->obtener($this->hab['101'])->chapa());
    }

    // ─────────────────────────── El barrido ───────────────────────────

    public function testElBarridoSoloTomaDiaNocheNoNoche(): void
    {
        $this->cb->estadia('CB_101');
        $this->cb->estadia('CB_102');
        $this->habs->marcarNochero($this->hab['101'], $this->hoy, $this->sofia, null, '09:00', Habitacion::NOCHERO_DIA_NOCHE);
        $this->habs->marcarNochero($this->hab['102'], $this->hoy, $this->sofia, null, '09:00', Habitacion::NOCHERO_NOCHE);
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $this->limpiar('102', $this->berta);
        $this->aprobar('102');

        $this->barridoNocheros();

        $this->assertEstado('101', 'sucia', 'Día/Noche: segundo aseo');
        $this->assertEstado('102', 'aprobada', 'Noche: un solo aseo, no se barre');
        $this->assertSame('dirty', $this->cb->condicion('CB_101'));
    }

    public function testMarcarNocheDespuesDeLaHoraNoLaEnsucia(): void
    {
        $this->cb->estadia('CB_101');
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');

        $this->habs->marcarNochero($this->hab['101'], $this->hoy, $this->sofia, $this->sync, '17:00', Habitacion::NOCHERO_NOCHE);
        $this->assertEstado('101', 'aprobada');

        // Cambiarla a Día/Noche a esa hora sí la barre al tiro, como siempre.
        $this->habs->marcarNochero($this->hab['101'], $this->hoy, $this->sofia, $this->sync, '17:00', Habitacion::NOCHERO_DIA_NOCHE);
        $this->assertEstado('101', 'sucia');
    }

    // ─────────────────────────── La hora de la tarde ───────────────────────────

    public function testHoraDeLaTardePorDefectoYCambiada(): void
    {
        $svc = new HoraTardeService();
        $this->assertSame('16:00', $svc->hora());
        $this->assertSame('17:30', $svc->guardar(' 17:30 ', $this->sofia));
        $this->assertSame('17:30', $svc->hora());
        $this->assertSame(1, (int) Database::fetchColumn("SELECT COUNT(*) FROM audit_log WHERE accion = 'habitaciones.hora_tarde'"));

        foreach (['17:10', '11:45', '20:15', '7:00', 'tarde', ''] as $mala) {
            try {
                $svc->guardar($mala, $this->sofia);
                $this->fail("Debía rechazar «{$mala}»");
            } catch (HabitacionException $e) {
                $this->assertSame('HORA_INVALIDA', $e->codigo);
            }
        }
        $this->assertSame('17:30', $svc->hora(), 'un intento malo no cambia nada');

        // Un valor raro guardado a mano en la base no rompe nada: se usa la de siempre.
        Database::execute("UPDATE alertas_config SET valor = '25:99' WHERE clave = 'hora_tarde'");
        $this->assertSame('16:00', $svc->hora());
    }

    public function testElMarcadoInmediatoUsaLaHoraDeAjustes(): void
    {
        (new HoraTardeService())->guardar('18:00', $this->sofia);
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');

        $this->habs->marcarNochero($this->hab['101'], $this->hoy, $this->sofia, $this->sync, '17:00');
        $this->assertEstado('101', 'aprobada', 'a las 17:00 todavía no es la tarde si la hora es 18:00');

        $this->habs->marcarNochero($this->hab['101'], $this->hoy, $this->sofia, $this->sync, '18:00');
        $this->assertEstado('101', 'sucia');
    }

    // ─────────────────────────── Vencimiento y planificación ───────────────────────────

    public function testUnaMarcaVencidaVuelveADia(): void
    {
        $ayer = date('Y-m-d', (int) strtotime($this->hoy . ' -1 day'));
        Database::execute(
            "UPDATE habitaciones SET es_nochero = 1, nochero_hasta = ?, nochero_modo = 'noche' WHERE id = ?",
            [$ayer, $this->hab['101']]
        );
        $this->assertSame(1, $this->habs->desactivarNocherosVencidos($this->hoy));
        $this->assertSame(['es_nochero' => 0, 'nochero_hasta' => null, 'nochero_modo' => 'dia_noche'], array_map(
            static fn($v) => is_numeric($v) ? (int) $v : $v,
            $this->fila('101')
        ));
    }

    public function testChapaDeFilaRespetaLaVigenciaDelDia(): void
    {
        $fila = ['es_nochero' => '1', 'nochero_modo' => 'noche', 'nochero_hasta' => '2026-11-12'];
        $this->assertSame('noche', HabitacionService::chapaDeFila($fila, '2026-11-12'));
        $this->assertSame('dia', HabitacionService::chapaDeFila($fila, '2026-11-13'), 'vencida ese día');
        $this->assertSame('noche', HabitacionService::chapaDeFila($fila));
        $this->assertSame('dia', HabitacionService::chapaDeFila(['es_nochero' => 0, 'nochero_modo' => 'noche']));
        $this->assertSame('dia_noche', HabitacionService::chapaDeFila(['es_nochero' => 1, 'nochero_modo' => null]));
    }

    public function testLaChapaLlegaAAsignacionesEInspeccion(): void
    {
        $manana = date('Y-m-d', (int) strtotime($this->hoy . ' +1 day'));
        $this->habs->marcarNochero($this->hab['101'], $this->hoy, $this->sofia, null, '09:00', Habitacion::NOCHERO_NOCHE);
        $this->habs->marcarNochero($this->hab['102'], $manana, $this->sofia, null, '09:00', Habitacion::NOCHERO_DIA_NOCHE);

        // Cola de hoy.
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $cola = $this->asig->colaDelTrabajador($this->ana, $this->hoy);
        $this->assertSame('noche', $cola[0]['chapa']);

        // Pool de hoy: la 102 sin asignar es Día/Noche.
        $vista = $this->asig->vistaConsolidada('ambos', $this->hoy);
        $pool = array_column($vista['sin_asignar'], 'chapa', 'numero');
        $this->assertSame('dia_noche', $pool['102'] ?? null);
        $this->assertSame('dia', $pool['103'] ?? null, 'Día también viaja: la chapa se ve siempre');

        // Inspección: la 101 terminada muestra su chapa.
        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        $this->terminar($e->id, $this->ana);
        $bandeja = array_column((new AuditoriaService())->bandejaPendientes(), 'chapa', 'numero');
        $this->assertSame('noche', $bandeja['101'] ?? null);
    }

    public function testElAvisoDeMarcasQueVencenDiceQueVuelvenADia(): void
    {
        $this->habs->marcarNochero($this->hab['101'], $this->hoy, $this->sofia, null, '09:00', Habitacion::NOCHERO_NOCHE);
        $this->assertSame(1, $this->habs->avisarNocherosPorVencer($this->hoy));
        $cuerpo = (string) Database::fetchColumn("SELECT cuerpo FROM notificaciones WHERE tipo = 'nochero_por_vencer'");
        $this->assertStringContainsString('Desde mañana vuelven a Día', $cuerpo);
        $this->assertStringNotContainsString('16:00', $cuerpo);
    }
}
