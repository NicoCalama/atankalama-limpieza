<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Helpers\Fechas;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Tests\Support\EscenarioCicloLimpieza;
use PHPUnit\Framework\TestCase;

/**
 * RED DE SEGURIDAD del ciclo de limpieza con Cloudbeds (01/10/2026), antes de los arreglos de la
 * v6.17. Cada test es una de las combinaciones que el documento «Ciclo de limpieza y Cloudbeds:
 * casos posibles y soluciones» simuló y marcó como «funcionan bien», más el flujo que confirmó
 * jefatura: a las 15:50 el cierre aprueba lo que nadie inspeccionó y a las 16:00 el barrido
 * devuelve los nocheros a sucia para el turno de tarde.
 *
 * Ningún arreglo puede romperlos. Si uno falla después de un cambio, el cambio está mal o la
 * decisión de negocio cambió: en ese caso se actualiza el test Y el documento, nunca solo el test.
 *
 * Corren el código real de punta a punta (checklist, auditoría, sync, cierre de día, barrido de
 * nocheros) contra un Cloudbeds simulado con memoria (tests/Support/CloudbedsSimulado.php).
 */
final class CicloLimpiezaRedTest extends TestCase
{
    use EscenarioCicloLimpieza;

    protected function setUp(): void
    {
        $this->prepararEscenario();
    }

    // ── Casos que funcionan bien ─────────────────────────────────────────────

    public function testElHuespedHaceCheckOutMientrasSeLimpia(): void
    {
        $this->cb->estadia('CB_101');
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);

        $this->cb->seVa('CB_101');
        $this->sincronizar();
        $this->assertEstado('101', 'en_progreso', 'el sync no interrumpe una limpieza en curso');

        $this->terminar($e->id, $this->ana);
        $this->aprobar('101');
        $this->sincronizar();

        $this->assertEstado('101', 'aprobada');
        $this->assertSame('clean', $this->cb->condicion('CB_101'));
        $this->assertSinAlertasDeshechas();
    }

    public function testLlegaUnHuespedAUnaPiezaAprobadaHoyYAlDiaSiguienteVuelveALaCola(): void
    {
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');

        $this->cb->llega('CB_101');
        $this->sincronizar();
        $this->assertEstado('101', 'aprobada', 'conserva la aprobación todo el día (pieza 706, 22/09)');

        // Al día siguiente el huésped ya no «llega»: sigue (stayover), y en la madrugada Cloudbeds
        // marca sucias las piezas ocupadas. Desde la v6.20 la llegada sobre una aprobación de un día
        // anterior se conserva, así que el escenario tiene que cambiar el día también en Cloudbeds.
        $this->pasarDia();
        $this->cb->madrugada();
        $this->sincronizar();
        $this->assertEstado('101', 'sucia', 'al día siguiente entra al aseo del día');
        $this->assertSinAlertasDeshechas();
    }

    public function testAprobadaConElHuespedAlojadoYCheckOutAnticipadoVuelveALaCola(): void
    {
        // El sync ya leyó la pieza con el huésped alojado (en producción corre cada 10 minutos):
        // de ahí sale la anotación «había un huésped alojado» al terminar la limpieza.
        $this->cb->estadia('CB_101');
        $this->sincronizar();
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $this->sincronizar();
        $this->assertEstado('101', 'aprobada');

        $this->cb->seVa('CB_101');
        $this->sincronizar();
        $this->assertEstado('101', 'sucia', 'se fue en la tarde: hay que limpiarla como salida (la excepción de la v6.22)');
        $this->assertSinAlertasDeshechas('desde la v6.22 vuelve a la cola sin aviso');

        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $this->assertEstado('101', 'aprobada');
    }

    public function testLateCheckOutDespuesDeUnaAprobacionAutomatica(): void
    {
        $this->cb->estadia('CB_101');
        $this->sincronizar();
        $this->limpiar('101', $this->ana);
        $this->cierreDeDia();
        $this->assertEstado('101', 'aprobada_automatica');

        $this->cb->pieza('CB_101', 'dirty', 'check-out', true); // le toca salir, pero sigue adentro
        $this->sincronizar();
        $this->assertEstado('101', 'aprobada_automatica', 'se conserva mientras está ocupada');

        $this->cb->seVa('CB_101');
        $this->sincronizar();
        $this->assertEstado('101', 'sucia');
        $this->assertSinAlertasDeshechas();
    }

    public function testElHuespedSeCambiaDePieza(): void
    {
        // Origen: la 101, limpiada con el huésped alojado. Destino: la 102, limpiada vacía.
        $this->cb->estadia('CB_101');
        $this->sincronizar();
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $this->limpiar('102', $this->ana);
        $this->aprobar('102');

        $this->cb->seVa('CB_101');
        $this->cb->llega('CB_102');
        $this->sincronizar();

        $this->assertEstado('101', 'sucia', 'la de origen vuelve a la cola');
        $this->assertEstado('102', 'aprobada', 'la de destino, recién hecha, se conserva');
    }

    public function testUnaAprobacionALas2230EsDeHoyTambienEnLosCambiosDeHorario(): void
    {
        // 22:30 de Chile ya es el día siguiente en UTC (el reloj del servidor).
        // 04/04 y 05/09/2026: vísperas del fin y del inicio del horario de verano.
        foreach ([$this->hoy, '2026-04-04', '2026-09-05'] as $fecha) {
            Database::execute('DELETE FROM audit_log');
            Database::execute(
                "INSERT INTO audit_log (usuario_id, accion, entidad, entidad_id, detalles_json, origen, created_at)
                 VALUES (NULL, 'habitacion.cambiar_estado', 'habitacion', ?, ?, 'ui', ?)",
                [$this->hab['101'], json_encode(['desde' => 'completada_pendiente_auditoria', 'hasta' => 'aprobada']), Fechas::instanteLocalUtc($fecha, '22:30')]
            );
            $manana = date('Y-m-d', (int) strtotime($fecha . ' +1 day'));
            $this->assertTrue($this->habs->cambioDeEstadoHoy($this->hab['101'], $fecha), "22:30 del {$fecha} es de ese día");
            $this->assertFalse($this->habs->cambioDeEstadoHoy($this->hab['101'], $manana), "y no del {$manana}");
        }
    }

    public function testNadieInspeccionaYElHuespedSigue(): void
    {
        $this->cb->estadia('CB_101');
        $this->limpiar('101', $this->ana);
        $this->sincronizar();
        $this->assertEstado('101', 'completada_pendiente_auditoria', 'el sync no toca una pieza en espera de inspección');

        $this->cierreDeDia();
        $this->assertEstado('101', 'aprobada_automatica');
        $this->assertSame(
            Auditoria::VEREDICTO_APROBADO_AUTOMATICO,
            Database::fetchColumn('SELECT veredicto FROM auditorias WHERE habitacion_id = ?', [$this->hab['101']])
        );

        $this->pasarDia();
        $this->cb->madrugada();
        $this->sincronizar();
        $this->assertEstado('101', 'sucia', 'en la madrugada vuelve a la cola para el servicio del día');
        $this->assertSinAlertasDeshechas();
    }

    public function testEntraUnHuespedMientrasLaPiezaEsperaInspeccion(): void
    {
        $this->limpiar('101', $this->ana);
        $this->cb->llega('CB_101');
        $this->sincronizar();
        $this->assertEstado('101', 'completada_pendiente_auditoria');

        $this->aprobar('101');
        $this->sincronizar();
        $this->assertEstado('101', 'aprobada');

        $this->pasarDia();
        $this->cb->madrugada();
        $this->sincronizar();
        $this->assertEstado('101', 'sucia', 'al día siguiente sigue el ciclo normal');
        $this->assertSinAlertasDeshechas();
    }

    public function testReasignarUnaPiezaSuciaQueNadieEmpezoNoEscribeACloudbeds(): void
    {
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $this->asig->asignarManual($this->hab['101'], $this->berta, $this->hoy);

        $this->assertCount(0, $this->asig->colaDelTrabajador($this->ana, $this->hoy));
        $this->assertCount(1, $this->asig->colaDelTrabajador($this->berta, $this->hoy));
        $this->assertEstado('101', 'sucia');
        $this->assertSame([], $this->cb->escrituras);
    }

    public function testElCierreDeLas1550NoTocaPiezasEnCursoNiSucias(): void
    {
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        $this->limpiar('103', $this->berta);

        $this->cierreDeDia();

        $this->assertEstado('101', 'en_progreso');
        $this->assertEstado('102', 'sucia');
        $this->assertEstado('103', 'aprobada_automatica');
    }

    public function testDarPorLimpiaDuranteUnaLimpiezaLaCierraConLoMarcadoYLaTrabajadoraQuedaLibre(): void
    {
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        $primero = $this->obligatorios($e->templateId)[0];
        $this->chk->marcarItem($e->id, $primero, true, $this->ana);

        $this->chk->marcarLimpiaManual($this->hab['101'], $this->sofia);

        $this->assertEstado('101', 'completada_pendiente_auditoria', 'queda para inspección');
        $this->assertSame('completada', Database::fetchColumn('SELECT estado FROM ejecuciones_checklist WHERE id = ?', [$e->id]));
        $this->assertSame(
            $this->ana,
            (int) Database::fetchColumn('SELECT marcado_por FROM ejecuciones_items WHERE ejecucion_id = ? AND item_id = ?', [$e->id, $primero]),
            'conserva lo que Ana alcanzó a marcar'
        );
        // Ana queda libre: puede empezar otra pieza.
        $this->asig->asignarManual($this->hab['102'], $this->ana, $this->hoy);
        $this->chk->iniciarEjecucion($this->hab['102'], $this->ana, $this->hoy);
        $this->assertEstado('102', 'en_progreso');
    }

    public function testNocheroAseoDeLaMananaBarridoDeLas1600YSegundoAseo(): void
    {
        $this->cb->estadia('CB_101');
        $this->habs->marcarNochero($this->hab['101'], $this->hoy);

        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $this->barridoNocheros();
        $this->assertEstado('101', 'sucia', 'el barrido de las 16:00 pide la limpieza de la tarde');
        $this->assertSame('dirty', $this->cb->condicion('CB_101'), 'y avisa a Cloudbeds para que su sync no la cierre');

        $this->sincronizar();
        $this->assertEstado('101', 'sucia');

        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $this->barridoNocheros();
        $this->sincronizar();
        $this->assertEstado('101', 'aprobada', 'el barrido no se repite el mismo día');
        $this->assertSame(2, (int) Database::fetchColumn('SELECT COUNT(*) FROM ejecuciones_checklist WHERE habitacion_id = ?', [$this->hab['101']]));
        $this->assertSinAlertasDeshechas();
    }

    public function testReLimpiezaDeUnaRechazadaQueApruebaElCierreAutomatico(): void
    {
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $e = $this->limpiar('101', $this->ana);
        $this->aud->emitirVeredicto($this->hab['101'], $this->sofia, Auditoria::VEREDICTO_RECHAZADO, 'Rehacer estos ítems.', array_slice($this->obligatorios($this->templateDe($e)), 0, 2));
        $this->assertEstado('101', 'rechazada');

        $this->limpiar('101', $this->ana);
        $this->cierreDeDia();

        $this->assertEstado('101', 'aprobada_automatica', 'la marca «nadie la inspeccionó» se conserva');
        $ficha = $this->filaDe('Ana');
        $this->assertSame(1, $ficha['rechazadas_hab']);
        $this->assertSame(
            (int) round($this->creditosObligatorios($this->templateDe($e)) * 0.5),
            $ficha['creditos'],
            'escalera 100/50/0: la segunda vez vale la mitad'
        );
    }

    public function testFallaLaEscrituraACloudbedsEnElCierreConLaPiezaOcupada(): void
    {
        $this->cb->estadia('CB_101');
        $this->limpiar('101', $this->ana);

        $this->cb->fallarEscrituras = true;
        $this->cierreDeDia();
        $this->cb->fallarEscrituras = false;
        $this->assertEstado('101', 'aprobada_automatica');
        $this->assertSame('dirty', $this->cb->condicion('CB_101'), 'Cloudbeds no se enteró');

        $this->sincronizar();
        $this->assertEstado('101', 'aprobada_automatica', 'ocupada y de hoy: se conserva');

        $this->pasarDia();
        $this->cb->madrugada();
        $this->sincronizar();
        $this->assertEstado('101', 'sucia', 'en la madrugada vuelve a la cola del día');
        $this->assertSinAlertasDeshechas();
    }

    // ── Flujo confirmado por jefatura (01/10/2026) ───────────────────────────

    public function testJefaturaCierreDeLas1550YBarridoDeNocherosDeLas1600ParaElTurnoDeTarde(): void
    {
        // Mañana: Ana limpia la 101 (nochero) y la 102 (normal), las dos con huésped. Nadie inspecciona.
        foreach (['101', '102'] as $n) {
            $this->cb->estadia("CB_{$n}");
        }
        $this->habs->marcarNochero($this->hab['101'], $this->hoy);
        $this->limpiar('101', $this->ana);
        $this->limpiar('102', $this->ana);

        // 15:50: el cierre aprueba solas las dos y avisa «limpia» a Cloudbeds.
        $this->cierreDeDia();
        $this->assertEstado('101', 'aprobada_automatica');
        $this->assertEstado('102', 'aprobada_automatica');
        $this->assertSame('clean', $this->cb->condicion('CB_101'));
        $this->assertSame('clean', $this->cb->condicion('CB_102'));

        // 16:00: el barrido devuelve SOLO el nochero a sucia, y avisa «sucia» a Cloudbeds.
        $this->barridoNocheros();
        $this->assertEstado('101', 'sucia', 'el turno de tarde sabe que hay que limpiarla');
        $this->assertEstado('102', 'aprobada_automatica', 'la pieza normal no se toca');
        $this->assertSame('dirty', $this->cb->condicion('CB_101'));

        // El sync siguiente no deshace nada: ni re-aprueba el nochero ni ensucia la normal.
        $this->sincronizar();
        $this->assertEstado('101', 'sucia');
        $this->assertEstado('102', 'aprobada_automatica');

        // Tarde: la 101 aparece entre las sucias y se limpia otra vez; esa sí se inspecciona.
        $sucias = array_map(static fn(array $h): int => (int) $h['id'], $this->habs->listar('ambos', 'sucia'));
        $this->assertContains($this->hab['101'], $sucias);
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $this->barridoNocheros();
        $this->sincronizar();
        $this->assertEstado('101', 'aprobada', 'una sola reversión por día');
        $this->assertSinAlertasDeshechas();
    }
}
