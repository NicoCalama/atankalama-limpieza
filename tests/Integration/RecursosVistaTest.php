<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\PaginasController;
use Atankalama\Limpieza\Core\Request;
use PHPUnit\Framework\TestCase;

/**
 * GET /views/recursos/{ruta*} (PaginasController::servirRecurso): endpoint PÚBLICO que entrega
 * el CSS/JS extraído de las vistas. Solo .css/.js de views/recursos/, nada fuera de esa carpeta.
 */
final class RecursosVistaTest extends TestCase
{
    private function pedir(string $ruta): \Atankalama\Limpieza\Core\Response
    {
        $req = new Request(
            metodo: 'GET',
            path: '/views/recursos/' . $ruta,
            cuerpo: [],
            ruta: ['ruta' => $ruta],
            query: [],
            cookies: [],
            headers: [],
        );
        return (new PaginasController())->servirRecurso($req);
    }

    public function testEntregaElJsYElCssConSuTipo(): void
    {
        $js = $this->pedir('tickets/tickets.js');
        $this->assertSame(200, $js->status);
        $this->assertStringStartsWith('application/javascript', $js->contentType);
        $this->assertStringContainsString('function ticketsApp()', $js->cuerpo);

        $css = $this->pedir('tickets/tickets.css');
        $this->assertSame(200, $css->status);
        $this->assertStringStartsWith('text/css', $css->contentType);
    }

    /** @return array<string, array{string}> */
    public static function rutasInvalidas(): array
    {
        return [
            'sube de carpeta'      => ['../../src/Core/Config.php'],
            'sube con js al final' => ['../../public/assets/js/app.js'],
            'php de la carpeta'    => ['tickets/tickets.php'],
            'no existe'            => ['tickets/nada.js'],
            'ruta absoluta'        => ['/etc/passwd.js'],
            'vacía'                => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rutasInvalidas')]
    public function testRechazaRutasFueraDeRecursos(string $ruta): void
    {
        $this->assertSame(404, $this->pedir($ruta)->status);
    }
}
