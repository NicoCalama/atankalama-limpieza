<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Services\ImagenAdjuntoService;
use Atankalama\Limpieza\Services\ImagenException;
use PHPUnit\Framework\TestCase;

/**
 * Sin GD, imagecreatefrom*() no existe y PHP lanzaba \Error: el ticket o la inspección pre-entrega se
 * caían con un 500 en vez de guardarse sin la foto. Ahora es una ImagenException que los llamadores ya
 * manejan (revisión de la v7). Solo corre donde falta GD (el PHP local de dev); con GD se salta.
 */
final class ImagenAdjuntoSinGdTest extends TestCase
{
    public function testSinGdLanzaUnaExcepcionManejable(): void
    {
        if (function_exists('imagecreatefromjpeg') && function_exists('imagewebp')) {
            $this->markTestSkipped('Este PHP tiene GD: el resguardo no se dispara.');
        }

        try {
            (new ImagenAdjuntoService())->guardarComoWebp(__FILE__, 1000, 'revision-entrega');
            $this->fail('se esperaba ImagenException');
        } catch (ImagenException $e) {
            $this->assertSame('SIN_GD', $e->codigo);
        }
    }
}
