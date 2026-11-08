<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Services\BonoAseoService;
use PHPUnit\Framework\TestCase;

/**
 * Fórmulas de la planilla «KPI ASEO» de RRHH (docs/kpis-sueldos.md), con casos de la planilla de
 * julio 2026 (corte 18). Las filas de jornada completa no cambian con las correcciones de RRHH, así
 * que deben dar exactamente lo que daba la planilla; las de jornada parcial cambian por las dos
 * correcciones (base = la mitad en la eficacia; extras × días trabajados).
 */
final class BonoAseoServiceTest extends TestCase
{
    public function testJornadaCompletaCalzaConLaPlanillaDeRrhh(): void
    {
        // 74 hab. hechas, 19 días, 52 observaciones. Planilla: F 3,895 · L 0,216 · M 0,703 · N 0,064 · O 0,027 · J 0,002 · I 0.
        $b = BonoAseoService::calcular(74, 19, 'completa', 52, 18.0);
        $this->assertSame(18.0, $b['base']);
        $this->assertSame(3.9, $b['act_dia']);
        $this->assertSame(21.6, $b['eficacia_pct']);
        $this->assertSame(70.3, $b['observadas_pct']);
        $this->assertSame(6.4, $b['logro_pct']);
        $this->assertSame(0.027, $b['factor_peso']);
        $this->assertSame(0.2, $b['resultado_pct']);
        $this->assertSame(0.0, $b['extras']);
    }

    public function testParcialUsaLaMitadDelCorteYExtrasPorDias(): void
    {
        // 341 hab. hechas en 24 días, parcial, sin observaciones: 14,2 por día contra una base de 9.
        $b = BonoAseoService::calcular(341, 24, 'parcial', 0, 18.0);
        $this->assertSame(9.0, $b['base']);
        $this->assertSame(14.2, $b['act_dia']);
        $this->assertSame(100.0, $b['eficacia_pct'], 'eficacia con la base de su jornada, tope 100 %');
        $this->assertSame(100.0, $b['logro_pct']);
        $this->assertSame(1.0, $b['factor_peso']);
        $this->assertSame(100.0, $b['resultado_pct']);
        $this->assertSame(125.0, $b['extras'], '(341/24 − 9) × 24 = 341 − 216');
    }

    public function testFactorDePesoBajoYSobreSetentaPorCiento(): void
    {
        // Parcial, 120 hab. en 17 días, 10 observaciones: L 0,784 · M 0,083 · N 0,719 (> 0,7).
        $b = BonoAseoService::calcular(120, 17, 'parcial', 10, 18.0);
        $this->assertSame(78.4, $b['eficacia_pct']);
        $this->assertSame(8.3, $b['observadas_pct']);
        $this->assertSame(71.9, $b['logro_pct']);
        $this->assertSame(0.344, $b['factor_peso'], '2,333 × 0,719 − 1,333');
        $this->assertSame(0.0, $b['extras'], '7,1 por día no supera la base de 9');

        // Logro bajo 70 %: tramo lineal 0,427 × N.
        $bajo = BonoAseoService::calcular(90, 10, 'completa', 0, 18.0); // L = 0,5
        $this->assertSame(50.0, $bajo['logro_pct']);
        $this->assertSame(0.214, $bajo['factor_peso']);
        $this->assertSame(10.7, $bajo['resultado_pct']);
    }

    public function testElCorteCambiaLaBase(): void
    {
        $b = BonoAseoService::calcular(150, 10, 'completa', 0, 12.0);
        $this->assertSame(12.0, $b['base']);
        $this->assertSame(100.0, $b['eficacia_pct']);
        $this->assertSame(30.0, $b['extras'], '150 − 12 × 10');
    }

    public function testSinJornadaNoInventaLaBase(): void
    {
        $b = BonoAseoService::calcular(100, 20, null, 5, 18.0);
        $this->assertNull($b['base']);
        $this->assertSame(5.0, $b['act_dia']);
        $this->assertSame(5.0, $b['observadas_pct']);
        $this->assertNull($b['eficacia_pct']);
        $this->assertNull($b['logro_pct']);
        $this->assertNull($b['factor_peso']);
        $this->assertNull($b['resultado_pct']);
        $this->assertNull($b['extras']);
    }

    public function testSinDiasNiHabitacionesQuedaVacio(): void
    {
        $b = BonoAseoService::calcular(0, 0, 'completa', 2, 18.0);
        $this->assertNull($b['act_dia']);
        $this->assertNull($b['observadas_pct']);
        $this->assertNull($b['eficacia_pct']);
        $this->assertNull($b['resultado_pct']);
        $this->assertNull($b['extras']);
    }

    public function testObservacionesTienenTopeDeCienPorCiento(): void
    {
        // 2 hab. bien y 5 rechazadas: sin tope M sería 250 % y el logro, negativo.
        $b = BonoAseoService::calcular(2, 3, 'completa', 5, 18.0);
        $this->assertSame(100.0, $b['observadas_pct']);
        $this->assertSame(0.0, $b['logro_pct']);
        $this->assertSame(0.0, $b['resultado_pct']);
    }

    // ── Por hora, desde noviembre de 2026 (v7.18) ──────────────────────────

    public function testPorHoraUsaLasMismasFormulasQueLaPlanilla(): void
    {
        // 90 hab. en 75 h con corte 2,4 equivale a 90 hab. en 10 días con corte 18: eficacia 50 %.
        $b = BonoAseoService::calcularPorHora(90, 4500, 0, 2.4);
        $this->assertSame(2.4, $b['base']);
        $this->assertSame(1.2, $b['act_hora']);
        $this->assertSame(50.0, $b['eficacia_pct']);
        $this->assertSame(50.0, $b['logro_pct']);
        $this->assertSame(0.214, $b['factor_peso']);
        $this->assertSame(10.7, $b['resultado_pct']);
        $this->assertSame(0.0, $b['extras']);

        $porDia = BonoAseoService::calcular(90, 10, 'completa', 0, 18.0);
        foreach (['eficacia_pct', 'logro_pct', 'factor_peso', 'resultado_pct'] as $campo) {
            $this->assertSame($porDia[$campo], $b[$campo], $campo);
        }
    }

    public function testPorHoraLaActividadVaConDosDecimales(): void
    {
        // 37 hab. en 15,6 h = 2,371…: con un decimal se vería como 2,4, que es justo un corte típico.
        $this->assertSame(2.37, BonoAseoService::calcularPorHora(37, 936, 0, 2.4)['act_hora']);
    }

    public function testPorHoraLasExtrasSonLoQuePasaDelCortePorLasHoras(): void
    {
        $b = BonoAseoService::calcularPorHora(100, 2400, 0, 2.0);
        $this->assertSame(100.0, $b['eficacia_pct'], 'tope 100 %');
        $this->assertSame(20.0, $b['extras'], '100 − 2 × 40 h');
    }

    public function testPorHoraSinHorasNoInventaNada(): void
    {
        foreach ([null, 0, -30] as $minutos) {
            $b = BonoAseoService::calcularPorHora(10, $minutos, 2, 2.4);
            $this->assertNull($b['act_hora'], var_export($minutos, true));
            $this->assertNull($b['eficacia_pct']);
            $this->assertNull($b['logro_pct']);
            $this->assertNull($b['factor_peso']);
            $this->assertNull($b['resultado_pct']);
            $this->assertNull($b['extras']);
            $this->assertSame(20.0, $b['observadas_pct'], 'las observaciones no dependen de las horas');
        }
    }

    public function testPorHoraSinHabitacionesDejaElBonoVacioComoElCalculoPorDia(): void
    {
        $b = BonoAseoService::calcularPorHora(0, 450, 0, 2.4);
        $this->assertSame(0.0, $b['act_hora']);
        $this->assertNull($b['observadas_pct']);
        $this->assertNull($b['logro_pct']);
        $this->assertNull($b['resultado_pct']);
    }

    public function testQueMesesYPeriodosSeMidenPorHora(): void
    {
        $this->assertFalse(BonoAseoService::esPorHora(2026, 10));
        $this->assertTrue(BonoAseoService::esPorHora(2026, 11));
        $this->assertTrue(BonoAseoService::esPorHora(2027, 1));

        $this->assertFalse(BonoAseoService::periodoPorHora('2026-10-31'), 'si empieza antes de noviembre sigue por día');
        $this->assertTrue(BonoAseoService::periodoPorHora('2026-11-01'));
    }
}
