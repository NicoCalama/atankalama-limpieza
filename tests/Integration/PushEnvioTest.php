<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\PushService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\TestCase;

/**
 * Un push que falla no tumba la acción que lo dispara (rechazo de inspección, nota de Recepción…):
 * quien llama ya escribió en la BD, y un 500 hacía creer que no se guardó.
 *
 * En web-push v10, flush() es un generador: el cifrado con las claves de cada dispositivo y el
 * envío corren recién al recorrerlo. El arreglo de la auditoría del 07/10/2026 envolvía solo la
 * llamada a flush() y el error se escapaba igual en el recorrido.
 */
final class PushEnvioTest extends TestCase
{
    protected function setUp(): void
    {
        TestDatabase::recrear();
    }

    public function testUnFalloAlCifrarOEnviarNoTumbaLaAccionNiBorraLaSuscripcion(): void
    {
        [$ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        Database::execute(
            'INSERT INTO push_subscriptions (usuario_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)',
            [$ana, 'https://updates.push.services.mozilla.com/wpush/v2/abc', self::b64url("\x04" . str_repeat("\x01", 64)), self::b64url(str_repeat("\x02", 16))]
        );

        // Doble de la librería: como la real, el error salta al RECORRER el resultado de flush().
        $falso = new class () extends WebPush {
            public function flush(?int $batchSize = null): \Generator
            {
                if ($batchSize !== -1) {
                    throw new \ErrorException('No se pudo cifrar con las claves del dispositivo.');
                }
                yield;
            }
        };
        $push = new PushService();
        (new \ReflectionProperty(PushService::class, 'webPush'))->setValue($push, $falso);

        $push->notificar([$ana], 'Habitación 101 rechazada', 'Hay que repasar el baño.');

        // Llegó a la campanita (se guarda antes del push) y no lanzó nada.
        $this->assertSame(1, (int) Database::fetchColumn('SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ?', [$ana]));
        // La suscripción no se borra por un error que no es del dispositivo.
        $this->assertSame(1, (int) Database::fetchColumn('SELECT COUNT(*) FROM push_subscriptions WHERE usuario_id = ?', [$ana]));
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
