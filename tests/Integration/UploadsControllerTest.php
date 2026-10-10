<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\UploadsController;
use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Request;
use PHPUnit\Framework\TestCase;

/**
 * GET /uploads/{ruta}: la allowlist acepta las fotos de tickets y, desde la v7, las de la
 * revisión de entrega. Cualquier otra carpeta sigue siendo 404.
 */
final class UploadsControllerTest extends TestCase
{
    private const NOMBRE = 'ffffffffffff0018.webp';

    /** @var list<string> */
    private array $creados = [];

    protected function setUp(): void
    {
        foreach (['tickets', 'revision-entrega'] as $sub) {
            $dir = Config::basePath() . "/public/uploads/{$sub}/2026/10";
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $archivo = $dir . '/' . self::NOMBRE;
            file_put_contents($archivo, 'RIFF-prueba');
            $this->creados[] = $archivo;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->creados as $archivo) {
            @unlink($archivo);
        }
    }

    private function servir(string $ruta): \Atankalama\Limpieza\Core\Response
    {
        $r = new Request(metodo: 'GET', path: '/uploads/' . $ruta, cuerpo: [], ruta: ['ruta' => $ruta], query: [], cookies: [], headers: []);
        return (new UploadsController())->servir($r);
    }

    public function testSirveLasFotosDeTicketsYDeLaRevisionDeEntrega(): void
    {
        foreach (['tickets', 'revision-entrega'] as $sub) {
            $resp = $this->servir("{$sub}/2026/10/" . self::NOMBRE);
            $this->assertSame(200, $resp->status, $sub);
            $this->assertSame('RIFF-prueba', $resp->cuerpo);
        }
    }

    public function testOtraCarpetaSigueSiendo404(): void
    {
        $resp = $this->servir('otra/2026/10/' . self::NOMBRE);
        $this->assertSame(404, $resp->status);
        $this->assertStringContainsString('RUTA_INVALIDA', $resp->cuerpo);
    }
}
