<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Helpers\Rut;
use PHPUnit\Framework\TestCase;

final class RutTest extends TestCase
{
    public function testNormalizaEliminaPuntosYConservaGuion(): void
    {
        $this->assertSame('12345678-9', Rut::normalizar('12.345.678-9'));
    }

    public function testNormalizaAgregaGuionSiFalta(): void
    {
        $this->assertSame('12345678-9', Rut::normalizar('123456789'));
    }

    public function testNormalizaKEnMayuscula(): void
    {
        $this->assertSame('12345678-K', Rut::normalizar('12345678-k'));
    }

    public function testValidaRutCorrecto(): void
    {
        $this->assertTrue(Rut::validar('11111111-1'));
        $this->assertTrue(Rut::validar('12.345.678-5'));
    }

    public function testValidaRutConK(): void
    {
        $this->assertTrue(Rut::validar('12345670-k'));
    }

    public function testRechazaDvIncorrecto(): void
    {
        $this->assertFalse(Rut::validar('12345678-0'));
    }

    public function testRechazaFormatoInvalido(): void
    {
        $this->assertFalse(Rut::validar('abc'));
        $this->assertFalse(Rut::validar(''));
        $this->assertFalse(Rut::validar('123-X'));
    }

    public function testCalculaDvCorrecto(): void
    {
        $this->assertSame('1', Rut::calcularDigitoVerificador('11111111'));
        $this->assertSame('K', Rut::calcularDigitoVerificador('12345670'));
    }
}
