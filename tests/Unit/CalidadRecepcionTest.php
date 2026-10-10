<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Services\ReportesService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Calidad de supervisión según Recepción (v7, fórmula de gerencia del 10/10/2026):
 * (aprobadas − rechazadas) ÷ (aprobadas + rechazadas) × 100 sobre lo que Recepción revisó, con mínimo 0 %.
 */
final class CalidadRecepcionTest extends TestCase
{
    /** @return array<string, array{int, int, ?float}> */
    public static function casos(): array
    {
        return [
            'ejemplo de la fórmula: 18 SÍ y 2 NO'  => [18, 2, 80.0],
            'todo SÍ'                              => [5, 0, 100.0],
            'tantos NO como SÍ'                    => [2, 2, 0.0],
            'más NO que SÍ no baja de 0'           => [1, 3, 0.0],
            'todo NO'                              => [0, 4, 0.0],
            'redondea a un decimal'                => [2, 1, 33.3],
            'sin revisiones de Recepción: sin dato' => [0, 0, null],
        ];
    }

    #[DataProvider('casos')]
    public function testCalidad(int $aprobadas, int $rechazadas, ?float $esperado): void
    {
        $this->assertSame($esperado, ReportesService::calidadRecepcionPct($aprobadas, $rechazadas));
    }
}
