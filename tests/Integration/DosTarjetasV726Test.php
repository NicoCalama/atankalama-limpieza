<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Services\AsignacionException;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\ChecklistException;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Tests\Support\EscenarioCicloLimpieza;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v7.26 (reunión del 08/11/2026; plan aprobado el 09/11, decisiones de Nicolás del 10/11): dos tarjetas por pieza
 * Día/Noche, con el interruptor TARJETA_NOCHE_DOBLE. La de noche se puede asignar desde la mañana y espera su hora;
 * nunca hay dos limpiezas vivas; si nadie limpió en la mañana, la toma la noche y la de día queda «No limpiada»;
 * una misma persona puede tener las dos. Con el interruptor apagado, todo es como la v7.25.
 */
final class DosTarjetasV726Test extends TestCase
{
    use EscenarioCicloLimpieza;

    protected function setUp(): void
    {
        $this->prepararEscenario();
        Config::sobrescribir('TARJETA_NOCHE_DOBLE', true);
        // 103: Día/Noche. La hora de la tarde es la de siempre (16:00).
        $this->habs->marcarNochero($this->hab['103'], $this->hoy, $this->sofia, null, '09:00', Habitacion::NOCHERO_DIA_NOCHE);
    }

    protected function tearDown(): void
    {
        Config::sobrescribir('TARJETA_NOCHE_DOBLE', false);
    }

    private function asigALas(int $hora): AsignacionService
    {
        return new AsignacionService(cloudbeds: $this->sync, horaActual: $hora);
    }

    private function chkALas(int $hora): ChecklistService
    {
        return new ChecklistService(
            asignaciones: $this->asigALas($hora),
            cloudbeds: $this->sync,
            reloj: static fn(): string => sprintf('%02d:00', $hora),
        );
    }

    /** Estado de la tarjeta de la persona en la 103, según su cola. */
    private function estadoEnCola(int $hora, int $usuario): ?string
    {
        foreach ($this->asigALas($hora)->colaDelTrabajador($usuario, $this->hoy) as $h) {
            if ($h['numero'] === '103') {
                return (string) $h['estado'];
            }
        }
        return null;
    }

    private function limpiarALas(int $hora, int $usuario): int
    {
        $e = $this->chkALas($hora)->iniciarEjecucion($this->hab['103'], $usuario, $this->hoy);
        foreach ($this->obligatorios($this->templateDe($e->id)) as $itemId) {
            $this->chk->marcarItem($e->id, $itemId, true, $usuario);
        }
        $this->chk->completar($e->id, $usuario);
        return $e->id;
    }

    private function activas(): int
    {
        return (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM asignaciones WHERE habitacion_id = ? AND fecha = ? AND activa = 1',
            [$this->hab['103'], $this->hoy]
        );
    }

    private function idTarjeta(string $ventana): int
    {
        return (int) Database::fetchColumn(
            "SELECT id FROM asignaciones WHERE habitacion_id = ? AND fecha = ? AND activa = 1
                AND (CASE WHEN franja = 'noche' THEN 'noche' ELSE 'dia' END) = ?",
            [$this->hab['103'], $this->hoy, $ventana]
        );
    }

    /** @return array<string, array<string, mixed>> por nombre */
    private function registro(): array
    {
        $r = [];
        foreach ((new ReportesService())->registroDelDia($this->hoy, 'ambos') as $p) {
            $r[$p['nombre']] = $p;
        }
        return $r;
    }

    /** @return iterable<string, array{0: string, 1: string, 2: bool, 3: bool, 4: bool, 5: ?string, 6: bool, 7: string}> */
    public static function casos(): iterable
    {
        //                                   ventana  pieza                     hora   barrida otra   propia      rehacer  →
        yield 'noche antes de la hora'      => ['noche', 'sucia',                  false, false, true,  null,       false, 'programada'];
        yield 'noche, mañana aprobada'      => ['noche', 'aprobada',               true,  false, true,  null,       false, 'sucia'];
        yield 'noche, mañana en curso'      => ['noche', 'en_progreso',            true,  false, true,  null,       false, 'programada'];
        yield 'noche, mañana por inspecc.'  => ['noche', 'completada_pendiente_auditoria', true, false, true, null, false, 'programada'];
        yield 'noche, mañana a rehacer'     => ['noche', 'sucia',                  true,  false, true,  null,       true,  'programada'];
        yield 'noche, mañana sin limpiar'   => ['noche', 'sucia',                  true,  false, true,  null,       false, 'sucia'];
        yield 'noche ya barrida'            => ['noche', 'en_progreso',            true,  true,  true,  'en_progreso', false, 'en_progreso'];
        yield 'día en la mañana'            => ['dia',   'sucia',                  false, false, true,  null,       false, 'sucia'];
        yield 'día barrida sin noche'       => ['dia',   'sucia',                  true,  true,  false, 'aprobada', false, 'sucia'];
        yield 'día barrida, con su veredic' => ['dia',   'sucia',                  true,  true,  true,  'aprobada', false, 'aprobada'];
        yield 'día barrida, sin limpiar'    => ['dia',   'sucia',                  true,  true,  true,  null,       false, 'no_limpiada'];
        yield 'día sin barrer, sin limpiar' => ['dia',   'sucia',                  true,  false, true,  null,       false, 'no_limpiada'];
        yield 'día sin barrer, a rehacer'   => ['dia',   'sucia',                  true,  false, true,  null,       true,  'sucia'];
        yield 'día sin barrer, en curso'    => ['dia',   'en_progreso',            true,  false, true,  'en_progreso', false, 'en_progreso'];
        yield 'día sin barrer, aprobada'    => ['dia',   'aprobada',               true,  false, true,  'aprobada', false, 'aprobada'];
    }

    #[DataProvider('casos')]
    public function testEstadoDeTarjeta(string $ventana, string $pieza, bool $hora, bool $barrida, bool $otra, ?string $propia, bool $rehacer, string $esperado): void
    {
        $this->assertSame($esperado, AsignacionService::estadoDeTarjeta($ventana, $pieza, $hora, $barrida, $otra, $propia, $rehacer));
    }

    public function testLaDeNocheSeAsignaDesdeLaMananaYEsperaSuHora(): void
    {
        $this->asigALas(10)->asignarManual($this->hab['103'], $this->ana, $this->hoy);
        $this->asigALas(10)->asignarManual($this->hab['103'], $this->berta, $this->hoy, $this->sofia, 'noche');

        $this->assertSame(2, $this->activas(), 'las dos tarjetas conviven');
        $this->assertSame('sucia', $this->estadoEnCola(10, $this->ana));
        $this->assertSame('programada', $this->estadoEnCola(10, $this->berta));
        try {
            $this->chkALas(10)->iniciarEjecucion($this->hab['103'], $this->berta, $this->hoy);
            $this->fail('La de noche no se empieza en la mañana');
        } catch (ChecklistException $e) {
            $this->assertSame('TARJETA_PROGRAMADA', $e->codigo);
            $this->assertStringContainsString('16:00', $e->getMessage());
        }
    }

    public function testMananaYNocheCadaUnaConSuLimpieza(): void
    {
        $this->asigALas(10)->asignarManual($this->hab['103'], $this->ana, $this->hoy);
        $this->asigALas(10)->asignarManual($this->hab['103'], $this->berta, $this->hoy, $this->sofia, 'noche');
        $e = $this->chkALas(10)->iniciarEjecucion($this->hab['103'], $this->ana, $this->hoy);
        $this->assertSame('programada', $this->estadoEnCola(17, $this->berta), 'con la limpieza de la mañana en curso, espera');
        foreach ($this->obligatorios($this->templateDe($e->id)) as $itemId) {
            $this->chk->marcarItem($e->id, $itemId, true, $this->ana);
        }
        $this->chk->completar($e->id, $this->ana);
        $this->assertSame('programada', $this->estadoEnCola(17, $this->berta), 'por inspeccionar, espera');
        $this->aprobar('103');

        $this->barridoNocheros();
        $this->assertSame('aprobada', $this->estadoEnCola(17, $this->ana), 'la de día queda con su resultado');
        $this->assertSame('sucia', $this->estadoEnCola(17, $this->berta));

        $this->limpiarALas(17, $this->berta);
        $this->aprobar('103');
        $this->assertSame(0, $this->habs->barrerNocheros($this->hoy, $this->sync)['revertidas'], 'no se vuelve a barrer');

        $r = $this->registro();
        $this->assertSame(1, $r['Ana']['totales']['piezas']);
        $this->assertSame(1, $r['Berta']['totales']['piezas']);
        $this->assertSame('Noche', $r['Berta']['limpiezas'][0]['pasada']);
    }

    public function testLaNocheNoEsperaAlCronParaEmpezar(): void
    {
        $this->asigALas(10)->asignarManual($this->hab['103'], $this->ana, $this->hoy);
        $this->asigALas(10)->asignarManual($this->hab['103'], $this->berta, $this->hoy, $this->sofia, 'noche');
        $this->limpiarALas(10, $this->ana);
        $this->aprobar('103');

        // 17:00, el cron todavía no barre: la de noche ya se puede empezar y se barre al empezar.
        $this->assertSame('sucia', $this->estadoEnCola(17, $this->berta));
        $this->chkALas(17)->iniciarEjecucion($this->hab['103'], $this->berta, $this->hoy);
        $pieza = Database::fetchOne('SELECT estado, nochero_ultima_reversion FROM habitaciones WHERE id = ?', [$this->hab['103']]);
        $this->assertSame('en_progreso', $pieza['estado']);
        $this->assertSame($this->hoy, $pieza['nochero_ultima_reversion']);
        $this->assertSame('aprobada', $this->estadoEnCola(17, $this->ana));
    }

    public function testSiNadieLimpioEnLaMananaLaTomaLaNoche(): void
    {
        $this->asigALas(10)->asignarManual($this->hab['103'], $this->ana, $this->hoy);
        $this->asigALas(10)->asignarManual($this->hab['103'], $this->berta, $this->hoy, $this->sofia, 'noche');

        $this->assertSame('no_limpiada', $this->estadoEnCola(17, $this->ana));
        $this->assertSame('sucia', $this->estadoEnCola(17, $this->berta));
        $this->limpiarALas(17, $this->berta);
        $this->aprobar('103');

        try {
            $this->chkALas(17)->iniciarEjecucion($this->hab['103'], $this->ana, $this->hoy);
            $this->fail('La de la mañana ya pasó a la noche');
        } catch (ChecklistException $e) {
            $this->assertSame('TARJETA_PASO_A_LA_NOCHE', $e->codigo);
        }
        // Sigue activa (no la retira el cierre ni la reconciliación) y cuenta como asignada sin hacer.
        $this->asigALas(17)->vistaConsolidada('ambos', $this->hoy);
        $this->assertSame('no_limpiada', $this->estadoEnCola(17, $this->ana));
        $this->assertSame(['103'], array_column($this->registro()['Ana']['sin_limpiar'], 'numero'));
    }

    public function testElRechazoDeLaMananaSeRehaceAntesQueLaNoche(): void
    {
        $this->asigALas(10)->asignarManual($this->hab['103'], $this->ana, $this->hoy);
        $this->asigALas(10)->asignarManual($this->hab['103'], $this->berta, $this->hoy, $this->sofia, 'noche');
        $e = $this->limpiarALas(10, $this->ana);
        $item = (int) Database::fetchColumn('SELECT item_id FROM ejecuciones_items WHERE ejecucion_id = ? LIMIT 1', [$e]);
        $this->aud->emitirVeredicto($this->hab['103'], $this->sofia, 'rechazado', 'Rehacer', [$item]);

        $this->assertSame('rechazada', $this->estadoEnCola(17, $this->ana));
        $this->assertSame('programada', $this->estadoEnCola(17, $this->berta));

        // La supervisora le devuelve la de día a Ana: se rehace primero, la noche sigue esperando.
        $this->asigALas(17)->reasignar($this->hab['103'], $this->ana, $this->hoy, 'Rehacer', $this->sofia, asignacionId: $this->idTarjeta('dia'));
        $this->assertSame('sucia', $this->estadoEnCola(17, $this->ana));
        $this->assertSame('programada', $this->estadoEnCola(17, $this->berta));
        $this->assertSame(2, $this->activas());
    }

    public function testQuitarOMoverUnaTarjetaNoTocaLaOtra(): void
    {
        $this->asigALas(10)->asignarManual($this->hab['103'], $this->ana, $this->hoy);
        $this->asigALas(10)->asignarManual($this->hab['103'], $this->berta, $this->hoy, $this->sofia, 'noche');
        $dia = $this->idTarjeta('dia');

        $this->asigALas(10)->reasignar($this->hab['103'], $this->ana, $this->hoy, 'Cambio', $this->sofia, asignacionId: $this->idTarjeta('noche'));
        $this->assertSame($dia, $this->idTarjeta('dia'), 'mover la de noche no toca la de día');
        $this->assertSame(
            ['sucia', 'programada'],
            array_column($this->asigALas(10)->colaDelTrabajador($this->ana, $this->hoy), 'estado'),
            'Ana queda con las dos'
        );

        $this->asigALas(10)->desasignar($this->hab['103'], $this->hoy, $this->sofia, asignacionId: $this->idTarjeta('noche'));
        $this->assertSame(1, $this->activas());
        $this->assertSame($dia, $this->idTarjeta('dia'));
    }

    public function testUnaPersonaPuedeTenerLasDos(): void
    {
        $this->asigALas(10)->asignarManual($this->hab['103'], $this->ana, $this->hoy);
        $this->asigALas(10)->asignarManual($this->hab['103'], $this->ana, $this->hoy, $this->sofia, 'noche');
        $estados = array_column($this->asigALas(10)->colaDelTrabajador($this->ana, $this->hoy), 'estado');
        $this->assertSame(['sucia', 'programada'], $estados);

        $this->limpiarALas(10, $this->ana);
        $this->aprobar('103');
        $this->barridoNocheros();
        $this->assertSame(
            ['aprobada', 'sucia'],
            array_column($this->asigALas(17)->colaDelTrabajador($this->ana, $this->hoy), 'estado')
        );
        $this->limpiarALas(17, $this->ana);
        $this->aprobar('103');
        $this->assertSame(2, $this->registro()['Ana']['totales']['piezas'], 'dos limpiezas distintas');
    }

    public function testElPoolDeNocheTraeLasDiaNocheSinTarjetaDeNoche(): void
    {
        $vista = $this->asigALas(10)->vistaConsolidada('ambos', $this->hoy);
        $this->assertTrue($vista['tarjeta_noche_doble']);
        $fila = array_values(array_filter($vista['sin_asignar'], fn(array $h): bool => $h['numero'] === '103'))[0];
        $this->assertFalse($fila['tiene_tarjeta_noche']);

        // Con la de día: sale del pool y queda en la lista de Noche, con quién la tiene.
        $this->asigALas(10)->asignarManual($this->hab['103'], $this->ana, $this->hoy);
        $vista = $this->asigALas(10)->vistaConsolidada('ambos', $this->hoy);
        $this->assertNotContains('103', array_column($vista['sin_asignar'], 'numero'));
        $this->assertSame(['103'], array_column($vista['dia_noche_barridas'], 'numero'));
        $this->assertSame('Ana', $vista['dia_noche_barridas'][0]['asignada_a']);

        // Con las dos: ya no está en ninguna.
        $this->asigALas(10)->asignarManual($this->hab['103'], $this->berta, $this->hoy, $this->sofia, 'noche');
        $vista = $this->asigALas(10)->vistaConsolidada('ambos', $this->hoy);
        $this->assertSame([], $vista['dia_noche_barridas']);
    }

    public function testSoloConLaDeNocheSigueEnElPoolDeLaMananaHastaLaHora(): void
    {
        $this->asigALas(10)->asignarManual($this->hab['103'], $this->berta, $this->hoy, $this->sofia, 'noche');

        $manana = $this->asigALas(10)->vistaConsolidada('ambos', $this->hoy)['sin_asignar'];
        $fila = array_values(array_filter($manana, fn(array $h): bool => $h['numero'] === '103'))[0];
        $this->assertTrue($fila['tiene_tarjeta_noche'], 'necesita la de día; la pantalla no la muestra en Noche');

        $tarde = $this->asigALas(17)->vistaConsolidada('ambos', $this->hoy)['sin_asignar'];
        $this->assertNotContains('103', array_column($tarde, 'numero'), 'desde la hora es de la noche');

        // «Auto» de la mañana también la ve.
        $this->darTurnoAna();
        $this->asigALas(10)->autoAsignar('ambos', $this->hoy);
        $this->assertSame(2, $this->activas());
    }

    public function testLaTarjetaDeNocheNoSeRetiraConLaPiezaAprobada(): void
    {
        $this->asigALas(10)->asignarManual($this->hab['103'], $this->berta, $this->hoy, $this->sofia, 'noche');
        Database::execute("UPDATE habitaciones SET estado = 'aprobada' WHERE id = ?", [$this->hab['103']]);
        $this->asigALas(10)->vistaConsolidada('ambos', $this->hoy);
        $this->asigALas(17)->vistaConsolidada('ambos', $this->hoy);
        $this->assertSame(1, $this->activas());
    }

    public function testConElInterruptorApagadoLaDeNocheReemplazaALaDeDia(): void
    {
        Config::sobrescribir('TARJETA_NOCHE_DOBLE', false);
        $this->asigALas(17)->asignarManual($this->hab['103'], $this->ana, $this->hoy);
        $this->asigALas(17)->asignarManual($this->hab['103'], $this->berta, $this->hoy, $this->sofia, 'noche');
        $this->assertSame(1, $this->activas());
        $this->assertSame($this->berta, (int) Database::fetchColumn(
            'SELECT usuario_id FROM asignaciones WHERE habitacion_id = ? AND fecha = ? AND activa = 1',
            [$this->hab['103'], $this->hoy]
        ));
        $this->assertFalse($this->asigALas(17)->vistaConsolidada('ambos', $this->hoy)['tarjeta_noche_doble']);
    }

    private function darTurnoAna(): void
    {
        Database::execute("INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES ('mañana', '08:00', '16:00')");
        Database::execute('INSERT INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)', [$this->ana, Database::lastInsertId(), $this->hoy]);
    }
}
