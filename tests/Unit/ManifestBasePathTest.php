<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Controllers\PaginasController;
use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Request;
use PHPUnit\Framework\TestCase;

final class ManifestBasePathTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::sobrescribir('BASE_PATH', '');
    }

    private function manifest(): array
    {
        $request = new Request(metodo: 'GET', path: '/manifest');
        $response = (new PaginasController())->manifest($request);
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('application/manifest+json', $response->contentType);
        return json_decode($response->cuerpo, true);
    }

    public function testManifestEnRaiz(): void
    {
        Config::sobrescribir('BASE_PATH', '');
        $m = $this->manifest();
        $this->assertSame('/home', $m['start_url']);
        $this->assertSame('/', $m['scope']);
        $this->assertSame('/assets/img/icon-192.png', $m['icons'][0]['src']);
        $this->assertSame('/habitaciones', $m['shortcuts'][0]['url']);
    }

    public function testManifestBajoSubpath(): void
    {
        Config::sobrescribir('BASE_PATH', '/limpieza');
        $m = $this->manifest();
        $this->assertSame('/limpieza/home', $m['start_url']);
        $this->assertSame('/limpieza/', $m['scope']);
        $this->assertSame('/limpieza/assets/img/icon-192.png', $m['icons'][0]['src']);
        $this->assertSame('/limpieza/assets/img/icon-512.png', $m['icons'][1]['src']);
        $this->assertSame('/limpieza/habitaciones', $m['shortcuts'][0]['url']);
        $this->assertSame('/limpieza/auditoria', $m['shortcuts'][1]['url']);
    }
}
