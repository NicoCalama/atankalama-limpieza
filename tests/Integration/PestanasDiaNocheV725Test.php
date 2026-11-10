<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Services\AsignacionException;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Tests\Support\EscenarioCicloLimpieza;
use PHPUnit\Framework\TestCase;

/**
 * v7.25 (reunión del 08/11/2026; plan aprobado el 09/11, detalles con Nicolás el 10/11): pestañas Día | Noche en
 * Asignaciones. Las piezas Noche se asignan hoy recién desde la hora de la tarde (también en el backend) y no entran
 * a «Auto»; las tarjetas de noche no se retiran en la mañana; las Día/Noche barridas salen en el pool de Noche y, al
 * asignarlas, la tarjeta pasa de la persona de la mañana a la de noche sin que su limpieza se pierda del Registro.
 */
final class PestanasDiaNocheV725Test extends TestCase
{
    use EscenarioCicloLimpieza;

    protected function setUp(): void
    {
        $this->prepararEscenario();
        // 101 Noche, 102 Día (sin marca), 103 Día/Noche. La hora de la tarde es la de siempre (16:00).
        $this->habs->marcarNochero($this->hab['101'], $this->hoy, $this->sofia, null, '09:00', Habitacion::NOCHERO_NOCHE);
        $this->habs->marcarNochero($this->hab['103'], $this->hoy, $this->sofia, null, '09:00', Habitacion::NOCHERO_DIA_NOCHE);
    }

    /** Un servicio con la hora fija: a las HH en punto (vale para el retiro de las 06:00 y para la hora de la tarde). */
    private function asigALas(int $hora): AsignacionService
    {
        return new AsignacionService(cloudbeds: $this->sync, horaActual: $hora);
    }

    private function darTurno(int $usuarioId): void
    {
        Database::execute("INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES ('mañana', '08:00', '16:00')");
        $turno = Database::lastInsertId();
        Database::execute('INSERT INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)', [$usuarioId, $turno, $this->hoy]);
    }

    private function activa(string $numero): ?array
    {
        return Database::fetchOne(
            'SELECT usuario_id, franja FROM asignaciones WHERE habitacion_id = ? AND fecha = ? AND activa = 1',
            [$this->hab[$numero], $this->hoy]
        );
    }

    /** @return list<string> números de la cola de la persona */
    private function colaDe(AsignacionService $svc, int $usuario): array
    {
        return array_map(static fn(array $h): string => (string) $h['numero'], $svc->colaDelTrabajador($usuario, $this->hoy));
    }

    public function testPiezaNocheSeAsignaRecienDesdeLaHoraDeLaTarde(): void
    {
        try {
            $this->asigALas(10)->asignarManual($this->hab['101'], $this->ana, $this->hoy);
            $this->fail('Una pieza Noche no se asigna en la mañana');
        } catch (AsignacionException $e) {
            $this->assertSame('NOCHE_ANTES_DE_HORA', $e->codigo);
            $this->assertSame(409, $e->httpStatus);
            $this->assertStringContainsString('16:00', $e->getMessage());
        }
        $this->assertNull($this->activa('101'));

        // Las otras sí, en la mañana; y la Noche, desde la hora.
        $this->asigALas(10)->asignarManual($this->hab['102'], $this->ana, $this->hoy);
        $this->asigALas(10)->asignarManual($this->hab['103'], $this->ana, $this->hoy);
        $this->asigALas(16)->asignarManual($this->hab['101'], $this->ana, $this->hoy, null, 'noche');
        $this->assertSame('noche', $this->activa('101')['franja']);

        // Para un día futuro se planifica a cualquier hora.
        $manana = date('Y-m-d', strtotime('+1 day'));
        $this->asigALas(10)->asignarManual($this->hab['101'], $this->berta, $manana, null, 'noche');
        $this->assertTrue($this->asigALas(10)->nocheDisponible($manana));
        $this->assertFalse($this->asigALas(10)->nocheDisponible($this->hoy));
    }

    public function testUnaNocheQueYaEstaEnUnaColaSePuedeMoverEnLaManana(): void
    {
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy); // a las 17:00 (escenario)
        $this->asigALas(10)->reasignar($this->hab['101'], $this->berta, $this->hoy, 'Cambio de turno', $this->sofia);
        $this->assertSame($this->berta, (int) $this->activa('101')['usuario_id']);
    }

    public function testElLoteNoQuedaAMediasSiTraeUnaNocheAntesDeLaHora(): void
    {
        try {
            $this->asigALas(10)->asignarMultiple([$this->hab['102'], $this->hab['101']], $this->ana, $this->hoy);
            $this->fail('El lote trae una pieza Noche antes de la hora');
        } catch (AsignacionException $e) {
            $this->assertSame('NOCHE_ANTES_DE_HORA', $e->codigo);
        }
        $this->assertNull($this->activa('102'), 'no se asigna ninguna del lote');
    }

    public function testAutoNoRepartePiezasNocheNiEnLaTarde(): void
    {
        $this->darTurno($this->ana);
        $r = $this->asigALas(17)->autoAsignar('ambos', $this->hoy);

        $this->assertSame(2, $r['habitaciones']);
        $this->assertNull($this->activa('101'), 'la Noche se asigna a mano en la pestaña Noche');
        $this->assertNotNull($this->activa('102'));
        $this->assertNotNull($this->activa('103'));
    }

    public function testLaTarjetaDeNocheNoSeRetiraEnLaMananaYSiDesdeLaHora(): void
    {
        // Preasignadas para hoy; amanecen aprobadas y nadie las trabajó.
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy, null, 'noche');
        $this->asig->asignarManual($this->hab['102'], $this->ana, $this->hoy);
        Database::execute("UPDATE habitaciones SET estado = 'aprobada' WHERE id IN (?, ?)", [$this->hab['101'], $this->hab['102']]);

        $this->asigALas(10)->vistaConsolidada('ambos', $this->hoy);
        $this->assertNotNull($this->activa('101'), 'la de noche espera a la tarde');
        $this->assertNull($this->activa('102'), 'la de día se retira como siempre desde las 06:00');

        $this->asigALas(17)->vistaConsolidada('ambos', $this->hoy);
        $this->assertNull($this->activa('101'), 'desde la hora, si sigue aprobada no necesitó limpieza');
    }

    public function testLaVistaDiceDesdeCuandoSeAsignaLaNoche(): void
    {
        $manana = $this->asigALas(10)->vistaConsolidada('ambos', $this->hoy);
        $this->assertSame(['hora' => '16:00', 'disponible' => false], $manana['ventana_noche']);

        $tarde = $this->asigALas(17)->vistaConsolidada('ambos', $this->hoy);
        $this->assertTrue($tarde['ventana_noche']['disponible']);

        // El pool viaja entero, cada pieza con su chapa: la pantalla lo reparte en las pestañas.
        $chapas = array_column($tarde['sin_asignar'], 'chapa', 'numero');
        $this->assertSame(['101' => 'noche', '102' => 'dia', '103' => 'dia_noche'], $chapas);
    }

    public function testDiaNocheBarridaSaleEnNocheYPasaALaNocheSinBorrarLaManana(): void
    {
        // En la mañana Ana limpia la 103 (Día/Noche) y se aprueba. En la tarde, el barrido la vuelve a sucia
        // y sigue en la cola de Ana.
        $this->limpiar('103', $this->ana);
        $this->aprobar('103');
        $this->barridoNocheros();
        $this->assertSame('sucia', Database::fetchColumn('SELECT estado FROM habitaciones WHERE id = ?', [$this->hab['103']]));

        $this->assertSame([], $this->asigALas(10)->vistaConsolidada('ambos', $this->hoy)['dia_noche_barridas'], 'antes de la hora, no');
        $barridas = $this->asigALas(17)->vistaConsolidada('ambos', $this->hoy)['dia_noche_barridas'];
        $this->assertSame(['103'], array_column($barridas, 'numero'));
        $this->assertSame('Ana', $barridas[0]['asignada_a']);
        $this->assertSame('dia_noche', $barridas[0]['chapa']);

        // Desde Noche se le da a Berta: la tarjeta pasa a la cola de noche de Berta.
        $tarde = $this->asigALas(17);
        $tarde->asignarManual($this->hab['103'], $this->berta, $this->hoy, $this->sofia, 'noche');
        $this->assertSame([], $this->colaDe($tarde, $this->ana));
        $this->assertSame(['103'], $this->colaDe($tarde, $this->berta));
        $this->assertSame([], $tarde->vistaConsolidada('ambos', $this->hoy)['dia_noche_barridas'], 'ya tiene tarjeta de noche');

        // Berta la limpia en la noche. El Registro guarda las dos limpiezas, cada una con su persona.
        $e = $this->chk->iniciarEjecucion($this->hab['103'], $this->berta, $this->hoy);
        $this->terminar($e->id, $this->berta);
        $registro = [];
        foreach ((new ReportesService())->registroDelDia($this->hoy, 'ambos') as $p) {
            $registro[$p['nombre']] = $p;
        }
        $this->assertSame(['103'], array_column($registro['Ana']['limpiezas'], 'numero'), 'la limpieza de la mañana no se pierde');
        $this->assertSame([], $registro['Ana']['sin_limpiar']);
        $this->assertSame(1, $registro['Ana']['totales']['piezas']);
        $this->assertSame('Noche', $registro['Berta']['limpiezas'][0]['pasada']);
        $this->assertSame(1, $registro['Berta']['totales']['piezas']);
    }

    public function testCadaPersonaTraeSuProgresoPorVentana(): void
    {
        $this->darTurno($this->ana);
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy, null, 'noche');
        $this->asig->asignarManual($this->hab['102'], $this->ana, $this->hoy);
        $this->asig->asignarManual($this->hab['103'], $this->ana, $this->hoy, null, 'tarde');

        $vista = $this->asigALas(17)->vistaConsolidada('ambos', $this->hoy);
        $ana = array_values(array_filter($vista['trabajadores'], fn(array $t): bool => $t['usuario']['id'] === $this->ana))[0];

        $this->assertSame(3, $ana['progreso']['total']);
        $this->assertSame(2, $ana['progreso_ventana']['dia']['total'], 'sin franja y «tarde» son de día');
        $this->assertSame(1, $ana['progreso_ventana']['noche']['total']);
        $this->assertSame(1, $ana['progreso_ventana']['noche']['pendientes']);
    }
}
