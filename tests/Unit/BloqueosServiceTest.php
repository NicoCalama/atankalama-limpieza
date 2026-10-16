<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Services\BloqueosService;
use PHPUnit\Framework\TestCase;

/**
 * Cómo se traduce lo que manda Cloudbeds (getRoomBlocks + roomBlocked) a un bloqueo de la app (v7.8).
 * Ver docs/cloudbeds.md §4.5.
 */
final class BloqueosServiceTest extends TestCase
{
    public function testTiposDeCloudbeds(): void
    {
        $this->assertSame('mantencion', BloqueosService::tipoDesdeCloudbeds('out_of_service'));
        $this->assertSame('cerrada', BloqueosService::tipoDesdeCloudbeds('blocked_dates'));
        $this->assertSame('cerrada', BloqueosService::tipoDesdeCloudbeds('blocked'));
        $this->assertNull(BloqueosService::tipoDesdeCloudbeds('courtesy_hold'), 'la reserva provisoria no es un bloqueo');
        $this->assertSame('bloqueada', BloqueosService::tipoDesdeCloudbeds('algo_nuevo'), 'un tipo desconocido igual bloquea');
    }

    public function testMapaPorPiezaConFechasYMantencionPrimero(): void
    {
        $mapa = BloqueosService::mapaPorPieza([
            ['roomBlockType' => 'blocked_dates', 'roomBlockReason' => 'Bodega', 'startDate' => '2026-10-15', 'endDate' => '2026-10-20',
             'rooms' => [['roomID' => 'R1'], ['roomID' => 'R2']]],
            ['roomBlockType' => 'out_of_service', 'roomBlockReason' => '  Gasfitería ', 'startDate' => '2026-10-16 00:00:00', 'endDate' => '2026-10-18',
             'rooms' => [['roomID' => 'R2']]],
            ['roomBlockType' => 'courtesy_hold', 'rooms' => [['roomID' => 'R3'], ['roomID' => '']]],
        ]);

        $this->assertSame(['tipo' => 'cerrada', 'motivo' => 'Bodega', 'desde' => '2026-10-15', 'hasta' => '2026-10-20'], $mapa['R1']);
        $this->assertSame(['tipo' => 'mantencion', 'motivo' => 'Gasfitería', 'desde' => '2026-10-16', 'hasta' => '2026-10-18'], $mapa['R2'], 'gana la mantención');
        $this->assertNull($mapa['R3']['tipo']);
        $this->assertArrayNotHasKey('', $mapa);
    }

    public function testBloqueoDeLaPieza(): void
    {
        $mapa = BloqueosService::mapaPorPieza([
            ['roomBlockType' => 'out_of_service', 'roomBlockReason' => 'Pintura', 'rooms' => [['roomID' => 'R1']]],
            ['roomBlockType' => 'courtesy_hold', 'rooms' => [['roomID' => 'R3']]],
        ]);

        $this->assertSame('mantencion', BloqueosService::bloqueoDeLaPieza('R1', $mapa, true)['tipo'] ?? null);
        $this->assertNull(BloqueosService::bloqueoDeLaPieza('R3', $mapa, true), 'reserva provisoria: no, aunque roomBlocked diga que sí');
        $this->assertSame('bloqueada', BloqueosService::bloqueoDeLaPieza('R9', $mapa, true)['tipo'] ?? null, 'bloqueada sin estar en la lista');
        $this->assertNull(BloqueosService::bloqueoDeLaPieza('R9', $mapa, false));
        // Sin la lista (getRoomBlocks falló): solo roomBlocked.
        $this->assertSame('bloqueada', BloqueosService::bloqueoDeLaPieza('R1', null, true)['tipo'] ?? null);
        $this->assertNull(BloqueosService::bloqueoDeLaPieza('R1', null, false));
    }

    public function testTextos(): void
    {
        $this->assertSame('en mantención', BloqueosService::comoEsta('mantencion'));
        $this->assertSame('cerrada', BloqueosService::comoEsta('cerrada'));
        $this->assertSame('bloqueada en Cloudbeds', BloqueosService::comoEsta('bloqueada'));
        $this->assertSame('h.cb_bloqueo_tipo IS NULL', BloqueosService::sqlSinBloqueo());
    }
}
