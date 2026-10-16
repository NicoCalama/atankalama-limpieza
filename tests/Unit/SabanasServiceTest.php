<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Services\SabanasService;
use PHPUnit\Framework\TestCase;

/**
 * Regla de cambio de sábanas (cadencia desde la llegada). Ver docs/ocupacion-y-sabanas.md §3.1
 */
final class SabanasServiceTest extends TestCase
{
    private SabanasService $svc;

    protected function setUp(): void
    {
        $this->svc = new SabanasService();
    }

    public function testStayoverEnMultiploDeNToca(): void
    {
        // 4 noches, N=4 → toca (día 4)
        $this->assertTrue($this->svc->tocaCambioSabanas('stayover', '2026-07-06', 4, '2026-07-10'));
        // 8 noches, N=4 → toca (día 8)
        $this->assertTrue($this->svc->tocaCambioSabanas('stayover', '2026-07-02', 4, '2026-07-10'));
    }

    public function testStayoverFueraDeMultiploNoToca(): void
    {
        $this->assertFalse($this->svc->tocaCambioSabanas('stayover', '2026-07-07', 4, '2026-07-10')); // 3 noches
        $this->assertFalse($this->svc->tocaCambioSabanas('stayover', '2026-07-05', 4, '2026-07-10')); // 5 noches
    }

    public function testDiaDeLlegadaNoToca(): void
    {
        // 0 noches (llega hoy) → no, aunque 0 % N == 0
        $this->assertFalse($this->svc->tocaCambioSabanas('stayover', '2026-07-10', 4, '2026-07-10'));
    }

    /** v7.7 (Nicolás, 15/10/2026): el huésped que se va hoy, o el turnover, siempre lleva sábanas. */
    public function testSalidaYTurnoverSiempreTocan(): void
    {
        foreach (['check-out', 'turnover'] as $fs) {
            // Sin importar las noches ni si hay fecha de llegada.
            $this->assertTrue($this->svc->tocaCambioSabanas($fs, '2026-07-07', 4, '2026-07-10'), "{$fs} con 3 noches");
            $this->assertTrue($this->svc->tocaCambioSabanas($fs, null, 4, '2026-07-10'), "{$fs} sin llegada");
        }
    }

    public function testLlegadaYLibreNoTocan(): void
    {
        foreach (['check-in', 'unused', null] as $fs) {
            $this->assertFalse(
                $this->svc->tocaCambioSabanas($fs, '2026-07-06', 4, '2026-07-10'),
                "frontdeskStatus={$fs} no debería disparar sábanas"
            );
        }
    }

    public function testSinArrivalNoToca(): void
    {
        $this->assertFalse($this->svc->tocaCambioSabanas('stayover', null, 4, '2026-07-10'));
        $this->assertFalse($this->svc->tocaCambioSabanas('stayover', '', 4, '2026-07-10'));
    }

    public function testNDistintoDe4(): void
    {
        // N=3 → toca a los 3, 6, 9…
        $this->assertTrue($this->svc->tocaCambioSabanas('stayover', '2026-07-07', 3, '2026-07-10'));  // 3
        $this->assertFalse($this->svc->tocaCambioSabanas('stayover', '2026-07-06', 3, '2026-07-10')); // 4
    }

    /** America/Santiago adelanta la hora en septiembre: ese día dura 23 h y antes restaba una noche. */
    public function testElCambioDeHorarioNoRestaUnaNoche(): void
    {
        $this->assertSame(9, $this->svc->nochesEstadia('2026-09-01', '2026-09-10'));
        $this->assertSame(9, $this->svc->nochesEstadia('2026-03-30', '2026-04-08'), 'ni en abril');
    }

    public function testNochesEstadia(): void
    {
        $this->assertSame(4, $this->svc->nochesEstadia('2026-07-06', '2026-07-10'));
        $this->assertSame(0, $this->svc->nochesEstadia('2026-07-10', '2026-07-10'));
        $this->assertNull($this->svc->nochesEstadia(null, '2026-07-10'));
        $this->assertNull($this->svc->nochesEstadia('', '2026-07-10'));
    }

    public function testAnotarFilaAgregaCampos(): void
    {
        $fila = $this->svc->anotarFila([
            'cb_frontdesk_status' => 'stayover',
            'cb_arrival_date' => '2026-07-06',
            'sabanas_cada_n_dias' => 4,
        ], '2026-07-10');
        $this->assertTrue($fila['toca_sabanas']);
        $this->assertSame(4, $fila['noches_estadia']);
    }

    public function testAnotarFilaSinDatosDeOcupacion(): void
    {
        $fila = $this->svc->anotarFila(['numero' => '101'], '2026-07-10');
        $this->assertFalse($fila['toca_sabanas']);
        $this->assertNull($fila['noches_estadia']);
    }

    public function testAnotarFilaSalidaToca(): void
    {
        $fila = $this->svc->anotarFila([
            'cb_frontdesk_status' => 'check-out',
            'cb_arrival_date' => '2026-07-08',
            'sabanas_cada_n_dias' => 4,
        ], '2026-07-10');
        $this->assertTrue($fila['toca_sabanas']);
    }

    /**
     * Fila con ocupación leída hoy. La hora va en UTC, como la guarda la base: 15:00 UTC es de día en
     * Chile (11:00 o 12:00), así que la fecha local es la misma.
     *
     * @return array<string, mixed>
     */
    private function filaLeidaHoy(?string $frontdesk, ?string $arrival): array
    {
        return [
            'cb_frontdesk_status' => $frontdesk,
            'cb_arrival_date' => $arrival,
            'cb_ocupacion_sync_at' => '2026-07-10T15:00:00.000Z',
            'sabanas_cada_n_dias' => 4,
        ];
    }

    /** Checklist adaptativo (v7.7): misma regla que el aviso cuando la ocupación es de hoy. */
    public function testLlevaCambioSabanasSigueLaRegla(): void
    {
        $hoy = '2026-07-10';
        $this->assertTrue($this->svc->llevaCambioSabanas($this->filaLeidaHoy('stayover', '2026-07-06'), $hoy), 'día 4');
        $this->assertFalse($this->svc->llevaCambioSabanas($this->filaLeidaHoy('stayover', '2026-07-07'), $hoy), 'día 3');
        $this->assertTrue($this->svc->llevaCambioSabanas($this->filaLeidaHoy('check-out', '2026-07-07'), $hoy), 'se va hoy');
        $this->assertTrue($this->svc->llevaCambioSabanas($this->filaLeidaHoy('turnover', '2026-07-10'), $hoy), 'turnover');
        $this->assertFalse($this->svc->llevaCambioSabanas($this->filaLeidaHoy('check-in', '2026-07-10'), $hoy), 'llega hoy');
        $this->assertFalse($this->svc->llevaCambioSabanas($this->filaLeidaHoy('unused', null), $hoy), 'libre');
    }

    /** Ante la duda, sí lleva: es lo que pasaba antes de la v7.7 (todos los ítems). */
    public function testLlevaCambioSabanasSinDatoConfiableSiLleva(): void
    {
        $hoy = '2026-07-10';
        $this->assertTrue($this->svc->llevaCambioSabanas(['sabanas_cada_n_dias' => 4], $hoy), 'sin ocupación');
        $this->assertTrue($this->svc->llevaCambioSabanas($this->filaLeidaHoy(null, null), $hoy), 'sin frontdesk');
        $this->assertTrue($this->svc->llevaCambioSabanas($this->filaLeidaHoy('stayover', null), $hoy), 'sigue sin fecha de llegada');

        // Ocupación leída ayer (el sync no anduvo): «día 3» podría ser hoy una salida.
        $ayer = $this->filaLeidaHoy('stayover', '2026-07-07');
        $ayer['cb_ocupacion_sync_at'] = '2026-07-09T15:00:00.000Z';
        $this->assertTrue($this->svc->llevaCambioSabanas($ayer, $hoy), 'lectura de ayer');

        $sinHora = $this->filaLeidaHoy('stayover', '2026-07-07');
        $sinHora['cb_ocupacion_sync_at'] = null;
        $this->assertTrue($this->svc->llevaCambioSabanas($sinHora, $hoy), 'sin hora de lectura');
    }

    /** La fecha de la lectura es la de Chile: las 01:30 UTC del 11/07 todavía son el 10/07 allá. */
    public function testLecturaDeLaNocheCuentaComoElDiaDeChile(): void
    {
        $tzAnterior = date_default_timezone_get();
        date_default_timezone_set('America/Santiago');
        try {
            $fila = $this->filaLeidaHoy('stayover', '2026-07-07');
            $fila['cb_ocupacion_sync_at'] = '2026-07-11T01:30:00.000Z';
            $this->assertFalse($this->svc->llevaCambioSabanas($fila, '2026-07-10'), 'día 3 leído el 10/07 de Chile');
        } finally {
            date_default_timezone_set($tzAnterior);
        }
    }
}
