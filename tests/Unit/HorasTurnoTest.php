<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Helpers\HorasTurno;
use PHPUnit\Framework\TestCase;

/**
 * v7.16: horas de trabajo de un turno. Jornada completa = turno menos 30 min de colación no pagada
 * (Nicolás, 07/11/2026): 08:00–16:00 son 7,5 h, para lo que está pensado el corte del bono.
 */
final class HorasTurnoTest extends TestCase
{
    public function testJornadaCompletaDescuentaLaColacion(): void
    {
        $this->assertSame(450, HorasTurno::minutosNetos('08:00', '16:00', 'completa'));
        $this->assertSame(450, HorasTurno::minutosNetos('14:00', '22:00', 'completa'));
    }

    public function testParcialYSinJornadaCuentanElTurnoEntero(): void
    {
        $this->assertSame(240, HorasTurno::minutosNetos('08:00', '12:00', 'parcial'));
        $this->assertSame(480, HorasTurno::minutosNetos('08:00', '16:00', null));
    }

    public function testMinutosEntreAceptaSegundosYNoCruzaMedianoche(): void
    {
        $this->assertSame(510, HorasTurno::minutosEntre('08:00:00', '16:30'));
        $this->assertSame(0, HorasTurno::minutosEntre('22:00', '06:00'));
        $this->assertSame(0, HorasTurno::minutosEntre('08:00', '08:00'));
        $this->assertSame(0, HorasTurno::minutosEntre('8 am', '16:00'));
    }

    public function testUnTurnoMuyCortoNoQuedaNegativo(): void
    {
        $this->assertSame(0, HorasTurno::minutosNetos('08:00', '08:20', 'completa'));
    }
}
