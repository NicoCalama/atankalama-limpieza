<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Url;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    protected function tearDown(): void
    {
        // Volver siempre a raíz para no contaminar otros tests.
        Config::sobrescribir('BASE_PATH', '');
        unset($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
    }

    public function testBaseVacioPorDefecto(): void
    {
        Config::sobrescribir('BASE_PATH', '');
        $this->assertSame('', Url::base());
        $this->assertSame('/home', Url::a('/home'));
    }

    public function testBaseSeNormalizaConSlashInicialYSinFinal(): void
    {
        foreach (['/limpieza', 'limpieza', '/limpieza/', 'limpieza/'] as $crudo) {
            Config::sobrescribir('BASE_PATH', $crudo);
            $this->assertSame('/limpieza', Url::base(), "BASE_PATH crudo: {$crudo}");
        }
    }

    public function testAPrefijaConBase(): void
    {
        Config::sobrescribir('BASE_PATH', '/limpieza');
        $this->assertSame('/limpieza/home', Url::a('/home'));
        $this->assertSame('/limpieza/api/auth/login', Url::a('/api/auth/login'));
    }

    public function testQuitarBaseStripeaElPrefijo(): void
    {
        Config::sobrescribir('BASE_PATH', '/limpieza');
        $this->assertSame('/home', Url::quitarBase('/limpieza/home'));
        $this->assertSame('/api/health', Url::quitarBase('/limpieza/api/health'));
        // El subpath solo (con o sin representación exacta) resuelve a raíz de la app.
        $this->assertSame('/', Url::quitarBase('/limpieza'));
        $this->assertSame('/', Url::quitarBase('/limpieza/'));
    }

    public function testQuitarBaseNoStripeaPrefijosParciales(): void
    {
        Config::sobrescribir('BASE_PATH', '/limpieza');
        // '/limpiezax' NO es el subpath: se devuelve tal cual (404 esperado).
        $this->assertSame('/limpiezax/foo', Url::quitarBase('/limpiezax/foo'));
        $this->assertSame('/otro/limpieza/home', Url::quitarBase('/otro/limpieza/home'));
    }

    public function testQuitarBaseEsPassthroughEnRaiz(): void
    {
        Config::sobrescribir('BASE_PATH', '');
        $this->assertSame('/home', Url::quitarBase('/home'));
        $this->assertSame('/', Url::quitarBase('/'));
    }

    public function testRutaActualQuitaElPrefijo(): void
    {
        Config::sobrescribir('BASE_PATH', '/limpieza');
        $_SERVER['REQUEST_URI'] = '/limpieza/habitaciones/42?tab=checklist';
        $this->assertSame('/habitaciones/42', Url::rutaActual());
    }

    public function testRequestDesdeGlobalesStripeaBasePath(): void
    {
        Config::sobrescribir('BASE_PATH', '/limpieza');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/limpieza/api/health';

        $request = Request::desdeGlobales();
        $this->assertSame('/api/health', $request->path);
    }

    public function testRequestDesdeGlobalesSinBaseNoAltera(): void
    {
        Config::sobrescribir('BASE_PATH', '');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/health';

        $request = Request::desdeGlobales();
        $this->assertSame('/api/health', $request->path);
    }
}
