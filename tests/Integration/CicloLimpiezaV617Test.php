<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\AlertaActiva;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Tests\Support\EscenarioCicloLimpieza;
use PHPUnit\Framework\TestCase;

/**
 * Arreglos de la v6.17 sobre el ciclo de limpieza (documento «Ciclo de limpieza y Cloudbeds:
 * casos posibles y soluciones»), los que no dependen de las consultas en producción:
 *   R4 · la alerta de rechazo se resuelve sola, la herencia de ítems no cruza de día y el
 *        barrido de nocheros espera a la rechazada;
 *   R5 · «Volver a limpiar» incluye lo que aprobó el cierre automático (el candado nocturno del
 *        cierre quedó fuera: jefatura mantiene el cron de las 15:50, 01/10/2026);
 *   R6 · los reseteos a sucia al reasignar/desasignar quedan en el historial, y el sync no
 *        decide sobre una pieza que cambió después de leer Cloudbeds.
 * La red de seguridad (CicloLimpiezaRedTest) tiene que seguir verde con todos.
 */
final class CicloLimpiezaV617Test extends TestCase
{
    use EscenarioCicloLimpieza;

    protected function setUp(): void
    {
        $this->prepararEscenario();
    }

    // ── R4 · Rechazos ────────────────────────────────────────────────────────

    public function testLaAlertaDeRechazoSeResuelveSolaCuandoLaMismaTrabajadoraLaRehaceYSeAprueba(): void
    {
        $this->limpiar('101', $this->ana);
        $this->rechazar('101');
        $this->assertSame(1, $this->alertasDeRechazo());

        // Ana la retoma directo desde «rechazada», sin que nadie la reasigne.
        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        $this->terminar($e->id, $this->ana);
        $this->aprobar('101');

        $this->assertSame(0, $this->alertasDeRechazo(), 'antes quedaba colgada para siempre');
    }

    public function testLaAlertaDeRechazoTambienSeResuelveSiLaApruebaElCierreAutomatico(): void
    {
        $this->limpiar('101', $this->ana);
        $this->rechazar('101');
        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        $this->terminar($e->id, $this->ana);
        $this->cierreDeDia();

        $this->assertSame(0, $this->alertasDeRechazo());
    }

    public function testLaHerenciaDeItemsNoCruzaDeDia(): void
    {
        $ayer = date('Y-m-d', (int) strtotime($this->hoy . ' -1 day'));
        $this->asig->asignarManual($this->hab['101'], $this->ana, $ayer);
        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $ayer);
        $this->terminar($e->id, $this->ana);
        $this->rechazar('101');

        // Hoy: es otro aseo. La limpieza nueva parte de cero.
        $this->asig->asignarManual($this->hab['101'], $this->berta, $this->hoy);
        $hoy = $this->chk->iniciarEjecucion($this->hab['101'], $this->berta, $this->hoy);

        $this->assertSame(0, $this->itemsMarcados($hoy->id), 'un rechazo de ayer no le regala ítems a la limpieza de hoy');
    }

    public function testLaHerenciaDeItemsSigueValiendoDentroDelMismoDia(): void
    {
        $this->limpiar('101', $this->ana);
        $this->rechazar('101');

        $this->asig->asignarManual($this->hab['101'], $this->berta, $this->hoy);
        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->berta, $this->hoy);

        $total = count($this->obligatorios($e->templateId));
        $this->assertSame($total - 2, $this->itemsMarcados($e->id), 'hereda lo que quedó bien (el auditor desmarcó 2)');
    }

    public function testElBarridoDeNocherosEsperaQueLaRechazadaSeRehagaYApruebe(): void
    {
        $this->cb->estadia('CB_101');
        $this->habs->marcarNochero($this->hab['101'], $this->hoy);
        $this->limpiar('101', $this->ana);
        $this->rechazar('101');

        $this->barridoNocheros();
        $this->assertEstado('101', 'rechazada', 'la limpieza de la mañana todavía no está hecha');

        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        $this->terminar($e->id, $this->ana);
        $this->aprobar('101');
        $this->barridoNocheros();
        $this->assertEstado('101', 'sucia', 'recién aprobada la de la mañana, pide la de la tarde');

        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $this->barridoNocheros();
        $this->assertEstado('101', 'aprobada', 'una sola reversión por día');
    }

    // ── R5 · Volver a limpiar ────────────────────────────────────────────────

    public function testVolverALimpiarIncluyeLasPiezasQueAproboElCierre(): void
    {
        $this->limpiar('101', $this->ana);
        $this->cierreDeDia();
        $this->limpiar('102', $this->ana);
        $this->aprobar('102');

        $ids = array_map(static fn(array $f): int => (int) $f['id'], $this->asig->vistaConsolidada('ambos', $this->hoy)['re_limpiar']);
        sort($ids);
        $this->assertSame([$this->hab['101'], $this->hab['102']], $ids);

        // Y se le puede pedir otra limpieza: vuelve a sucia y arranca de cero.
        $this->asig->asignarManual($this->hab['101'], $this->berta, $this->hoy, $this->sofia);
        $this->assertEstado('101', 'sucia');
    }

    // ── R6 · Historial de reseteos y sync que no pisa cambios recientes ──────

    public function testReasignarUnaPiezaAprobadaQuedaEnElHistorialConQuienLoHizo(): void
    {
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');

        $this->asig->asignarManual($this->hab['101'], $this->berta, $this->hoy, $this->sofia);

        $ultimo = $this->habs->obtenerMovimientos($this->hab['101'])[0];
        $this->assertSame('aprobada', $ultimo['desde']);
        $this->assertSame('sucia', $ultimo['hasta']);
        $this->assertSame('Sofia', $ultimo['usuario_nombre']);
    }

    public function testDesasignarUnaPiezaEnProgresoQuedaEnElHistorial(): void
    {
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);

        $this->asig->desasignar($this->hab['101'], $this->hoy, $this->sofia, true);

        $ultimo = $this->habs->obtenerMovimientos($this->hab['101'])[0];
        $this->assertSame('en_progreso', $ultimo['desde']);
        $this->assertSame('sucia', $ultimo['hasta']);
        $this->assertSame('Sofia', $ultimo['usuario_nombre']);
    }

    public function testElSyncNoDeshaceUnaReasignacionHechaMientrasLeiaCloudbeds(): void
    {
        $this->cb->estadia('CB_101');
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $this->assertSame('clean', $this->cb->condicion('CB_101'));

        // Mientras el sync recorre la foto («clean»), la supervisora pide otra limpieza.
        $this->cb->alLeer = function (): void {
            $this->asig->asignarManual($this->hab['101'], $this->berta, $this->hoy, $this->sofia);
        };
        $this->sincronizar();
        $this->assertEstado('101', 'sucia', 'antes la foto vieja la re-aprobaba y Berta perdía la pieza');

        $this->sincronizar(); // foto nueva: Cloudbeds ya la tiene sucia
        $this->assertEstado('101', 'sucia');
        $this->assertCount(1, $this->asig->colaDelTrabajador($this->berta, $this->hoy));
    }

    public function testAprobarMientrasElSyncLeeNoDeshaceLaAprobacionNiLevantaUnaAlertaFalsa(): void
    {
        $this->limpiar('101', $this->ana); // pieza vacía, Cloudbeds la tiene sucia (salida)

        $this->cb->alLeer = function (): void {
            $this->aprobar('101'); // la app avisa «limpia», pero la foto del sync dice «sucia»
        };
        $this->sincronizar();
        $this->assertEstado('101', 'aprobada');
        $this->assertSinAlertasDeshechas('antes: alerta falsa y la pieza de vuelta a la cola');

        $this->sincronizar();
        $this->assertEstado('101', 'aprobada');
    }

    // ── Ayudas ───────────────────────────────────────────────────────────────

    private function rechazar(string $numero): void
    {
        $e = (int) Database::fetchColumn(
            'SELECT id FROM ejecuciones_checklist WHERE habitacion_id = ? ORDER BY id DESC LIMIT 1',
            [$this->hab[$numero]]
        );
        $this->aud->emitirVeredicto(
            $this->hab[$numero],
            $this->sofia,
            Auditoria::VEREDICTO_RECHAZADO,
            'Rehacer estos ítems.',
            array_slice($this->obligatorios($this->templateDe($e)), 0, 2)
        );
    }

    private function alertasDeRechazo(): int
    {
        return (int) Database::fetchColumn('SELECT COUNT(*) FROM alertas_activas WHERE tipo = ?', [AlertaActiva::TIPO_HABITACION_RECHAZADA]);
    }

    private function itemsMarcados(int $ejecucionId): int
    {
        return (int) Database::fetchColumn('SELECT COUNT(*) FROM ejecuciones_items WHERE ejecucion_id = ? AND marcado = 1', [$ejecucionId]);
    }
}
