<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Helpers\Fechas;
use PHPUnit\Framework\TestCase;

final class FechasTest extends TestCase
{
    protected function setUp(): void
    {
        // Los tests corren con APP_TIMEZONE=America/Santiago (tests/bootstrap.php); lo
        // fijamos igual acá porque el punto de estos casos ES la zona horaria.
        date_default_timezone_set('America/Santiago');
    }

    public function testUnDiaDeInviernoAbarcaDeLas04UtcALas04UtcDelDiaSiguiente(): void
    {
        // En invierno Chile es UTC-4: el 15/07 local empieza a las 04:00Z.
        $this->assertSame(
            ['2026-07-15T04:00:00.000Z', '2026-07-16T04:00:00.000Z'],
            Fechas::rangoUtcDelDia('2026-07-15')
        );
    }

    public function testEnVeranoElRangoUsaElOffsetDelHorarioDeVerano(): void
    {
        // En verano Chile es UTC-3. Un offset fijo en SQL se rompería justo acá.
        $this->assertSame(
            ['2026-01-15T03:00:00.000Z', '2026-01-16T03:00:00.000Z'],
            Fechas::rangoUtcDelDia('2026-01-15')
        );
    }

    public function testElRangoDeVariosDiasVaDelPrimeroAlSiguienteDelUltimo(): void
    {
        $this->assertSame(
            ['2026-07-01T04:00:00.000Z', '2026-08-01T04:00:00.000Z'],
            Fechas::rangoUtc('2026-07-01', '2026-07-31')
        );
    }

    public function testElLimiteSuperiorEsExclusivoYCubreTodoElUltimoDia(): void
    {
        // Una limpieza a las 23:59:59.999 del 15/07 local = 03:59:59.999Z del 16/07:
        // tiene que caer DENTRO del rango del 15.
        [$desde, $hasta] = Fechas::rangoUtcDelDia('2026-07-15');
        $ultimoInstante = '2026-07-16T03:59:59.999Z';

        $this->assertTrue($ultimoInstante >= $desde && $ultimoInstante < $hasta);
    }

    public function testLasNueveDeLaNocheCaenEnElDiaLocalQueCorresponde(): void
    {
        // El bug: el turno de tarde termina a las 22:00 y en UTC ya es el día siguiente.
        // 21:00 del 15/07 en Santiago = 01:00Z del 16/07.
        [$desde, $hasta] = Fechas::rangoUtcDelDia('2026-07-15');
        $lasNueveDeLaNoche = '2026-07-16T01:00:00.000Z';

        $this->assertTrue(
            $lasNueveDeLaNoche >= $desde && $lasNueveDeLaNoche < $hasta,
            'Una limpieza de las 21:00 debe contar para el día local en que se hizo.'
        );
        $this->assertSame('2026-07-15', Fechas::fechaLocalDeUtc($lasNueveDeLaNoche));
    }

    public function testUnaLimpiezaDeLaManianaNoSeVaAlDiaAnterior(): void
    {
        // Simétrico: las 09:00 del 15/07 local = 13:00Z del mismo día.
        $this->assertSame('2026-07-15', Fechas::fechaLocalDeUtc('2026-07-15T13:00:00.000Z'));
    }

    public function testLaMedianocheLocalPerteneceAlDiaQueEmpieza(): void
    {
        [$desde, $hasta] = Fechas::rangoUtcDelDia('2026-07-15');

        $this->assertTrue('2026-07-15T04:00:00.000Z' >= $desde, 'El primer instante del día entra…');
        $this->assertTrue('2026-07-16T04:00:00.000Z' >= $hasta, '…y el primero del día siguiente ya no.');
    }
}
