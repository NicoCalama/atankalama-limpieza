<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Helpers\LogSanitizer;
use PHPUnit\Framework\TestCase;

final class LogSanitizerTest extends TestCase
{
    public function testRedactaPassword(): void
    {
        $out = LogSanitizer::sanitize(['password' => 'secreto']);
        $this->assertSame('[REDACTED]', $out['password']);
    }

    public function testRedactaApiKey(): void
    {
        $out = LogSanitizer::sanitize(['api_key' => 'abc', 'cloudbeds_api_key' => 'xyz']);
        $this->assertSame('[REDACTED]', $out['api_key']);
        $this->assertSame('[REDACTED]', $out['cloudbeds_api_key']);
    }

    public function testRedactaAuthorizationHeader(): void
    {
        $out = LogSanitizer::sanitize(['Authorization' => 'Bearer abc123']);
        $this->assertSame('[REDACTED]', $out['Authorization']);
    }

    public function testRedactaRecursivamente(): void
    {
        $out = LogSanitizer::sanitize([
            'usuario' => ['nombre' => 'Juan', 'password' => 'x'],
            'meta' => ['token' => 'abc'],
        ]);
        $this->assertSame('Juan', $out['usuario']['nombre']);
        $this->assertSame('[REDACTED]', $out['usuario']['password']);
        $this->assertSame('[REDACTED]', $out['meta']['token']);
    }

    public function testRedactaBearerEnValorString(): void
    {
        $out = LogSanitizer::sanitize(['header' => 'Bearer sk_live_xyz123']);
        $this->assertSame('[REDACTED]', $out['header']);
    }

    public function testRedactaPatronSkPrefix(): void
    {
        $out = LogSanitizer::sanitize(['clave' => 'sk-ant-abcdefghijklmnopqrstuvwxyz']);
        $this->assertSame('[REDACTED]', $out['clave']);
    }

    public function testPreservaCamposNoSensibles(): void
    {
        $out = LogSanitizer::sanitize([
            'nombre' => 'Juan',
            'email' => 'juan@example.com',
            'edad' => 30,
            'activo' => true,
        ]);
        $this->assertSame('Juan', $out['nombre']);
        $this->assertSame('juan@example.com', $out['email']);
        $this->assertSame(30, $out['edad']);
        $this->assertTrue($out['activo']);
    }

    public function testRedactaKeysCaseInsensitive(): void
    {
        $out = LogSanitizer::sanitize(['PASSWORD' => 'x', 'Api_Key' => 'y']);
        $this->assertSame('[REDACTED]', $out['PASSWORD']);
        $this->assertSame('[REDACTED]', $out['Api_Key']);
    }
}
