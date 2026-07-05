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

    public function testSoloAplicaAStayover(): void
    {
        foreach (['check-in', 'check-out', 'turnover', 'unused', null] as $fs) {
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
}
