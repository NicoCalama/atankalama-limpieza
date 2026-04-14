<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Services\PasswordService;
use PHPUnit\Framework\TestCase;

final class PasswordServiceTest extends TestCase
{
    private PasswordService $service;

    protected function setUp(): void
    {
        $this->service = new PasswordService();
    }

    public function testHashProduceBcrypt(): void
    {
        $hash = $this->service->hash('secreto123');
        $this->assertStringStartsWith('$2y$', $hash);
    }

    public function testVerificarAceptaPasswordCorrecta(): void
    {
        $hash = $this->service->hash('secreto123');
        $this->assertTrue($this->service->verificar('secreto123', $hash));
    }

    public function testVerificarRechazaPasswordIncorrecta(): void
    {
        $hash = $this->service->hash('secreto123');
        $this->assertFalse($this->service->verificar('otraPwd', $hash));
    }

    public function testGenerarTemporalTieneLongitud8(): void
    {
        $this->assertSame(8, strlen($this->service->generarTemporal()));
    }

    public function testGenerarTemporalSinCaracteresAmbiguos(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $pwd = $this->service->generarTemporal();
            $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $pwd);
        }
    }

    public function testGenerarTemporalIncluyeMayusMinusYDigito(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $pwd = $this->service->generarTemporal();
            $this->assertMatchesRegularExpression('/[A-Z]/', $pwd);
            $this->assertMatchesRegularExpression('/[a-z]/', $pwd);
            $this->assertMatchesRegularExpression('/[0-9]/', $pwd);
        }
    }

    public function testValidarFortalezaRechazaCortas(): void
    {
        $this->assertFalse($this->service->validarFortaleza('abc12'));
    }

    public function testValidarFortalezaRechazaSoloLetras(): void
    {
        $this->assertFalse($this->service->validarFortaleza('abcdefgh'));
    }

    public function testValidarFortalezaRechazaSoloDigitos(): void
    {
        $this->assertFalse($this->service->validarFortaleza('12345678'));
    }

    public function testValidarFortalezaAceptaValida(): void
    {
        $this->assertTrue($this->service->validarFortaleza('abc12345'));
    }
}
