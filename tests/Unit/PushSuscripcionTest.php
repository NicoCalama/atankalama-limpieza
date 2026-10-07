<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Services\PushService;
use PHPUnit\Framework\TestCase;

/**
 * Validación de una suscripción Web Push antes de guardarla (auditoría de código, 07/10/2026):
 * antes se guardaba cualquier endpoint y cualquier clave.
 */
final class PushSuscripcionTest extends TestCase
{
    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public function testAceptaUnaSuscripcionDelNavegador(): void
    {
        $this->assertTrue(PushService::suscripcionValida(
            'https://fcm.googleapis.com/fcm/send/abc123',
            self::b64url(random_bytes(65)),
            self::b64url(random_bytes(16))
        ));
    }

    public function testRechazaEndpointsQueNoSonHttps(): void
    {
        foreach (['http://10.0.0.5/admin', 'file:///etc/passwd', 'no es una url', ''] as $endpoint) {
            $this->assertFalse(PushService::suscripcionValida($endpoint, self::b64url(random_bytes(65)), self::b64url(random_bytes(16))), $endpoint);
        }
    }

    public function testRechazaClavesRotas(): void
    {
        $ep = 'https://fcm.googleapis.com/fcm/send/abc123';
        $this->assertFalse(PushService::suscripcionValida($ep, 'x', self::b64url(random_bytes(16))));
        $this->assertFalse(PushService::suscripcionValida($ep, self::b64url(random_bytes(65)), 'no-es-base64!'));
        $this->assertFalse(PushService::suscripcionValida($ep, self::b64url(random_bytes(64)), self::b64url(random_bytes(16))));
    }
}
