<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Helpers\Colores;
use Atankalama\Limpieza\Services\UiConfigException;
use Atankalama\Limpieza\Services\UiConfigService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Apariencia configurable (Ajustes → Colores): ui_config + derivación de variantes.
 */
final class UiConfigServiceTest extends TestCase
{
    private UiConfigService $svc;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        $this->svc = new UiConfigService();
    }

    public function testColoresDevuelveDefaultsSinFilas(): void
    {
        $this->assertSame(UiConfigService::DEFAULTS, $this->svc->colores());
    }

    public function testGuardarColoresPisaElDefaultYNormalizaMinusculas(): void
    {
        $this->svc->guardarColores(['color_estado_sucia' => '#FF8800'], null);

        $colores = $this->svc->colores();
        $this->assertSame('#ff8800', $colores['color_estado_sucia']);
        // El resto sigue en default
        $this->assertSame(UiConfigService::DEFAULTS['color_estado_aprobada'], $colores['color_estado_aprobada']);

        // Upsert: guardar de nuevo actualiza la misma fila
        $this->svc->guardarColores(['color_estado_sucia' => '#112233'], null);
        $this->assertSame('#112233', $this->svc->colores()['color_estado_sucia']);
        $filas = Database::fetchAll("SELECT * FROM ui_config WHERE clave = 'color_estado_sucia'");
        $this->assertCount(1, $filas);
    }

    public function testGuardarColoresRechazaClaveDesconocida(): void
    {
        try {
            $this->svc->guardarColores(['color_fondo_login' => '#112233'], null);
            $this->fail('Debía lanzar CLAVE_INVALIDA');
        } catch (UiConfigException $e) {
            $this->assertSame('CLAVE_INVALIDA', $e->codigo);
        }
    }

    public function testGuardarColoresRechazaHexInvalido(): void
    {
        foreach (['red', '#fff', '112233', '#11223g', ''] as $malo) {
            try {
                $this->svc->guardarColores(['color_estado_sucia' => $malo], null);
                $this->fail("Debía rechazar '{$malo}'");
            } catch (UiConfigException $e) {
                $this->assertSame('COLOR_INVALIDO', $e->codigo);
            }
        }
    }

    public function testValorCorruptoEnBdCaeAlDefault(): void
    {
        // Si alguien mete un valor no-hex directo en la BD, colores() lo ignora.
        Database::execute(
            "INSERT INTO ui_config (clave, valor) VALUES ('color_estado_sucia', 'javascript:alert(1)')"
        );
        $this->assertSame(
            UiConfigService::DEFAULTS['color_estado_sucia'],
            $this->svc->colores()['color_estado_sucia']
        );
    }

    public function testCssVarsContieneVariablesClaroYOscuro(): void
    {
        $this->svc->guardarColores(['color_estado_rechazada' => '#cc0000', 'color_hotel_inn' => '#123456'], null);
        $css = $this->svc->cssVars();

        $this->assertStringContainsString(':root {', $css);
        $this->assertStringContainsString('.dark {', $css);
        // Estado: bg pastel derivado del rojo elegido
        $this->assertStringContainsString('--ce-rechazada-bg:', $css);
        $this->assertStringContainsString('--ce-rechazada-fg:', $css);
        $this->assertStringContainsString('rgba(204, 0, 0, 0.28)', $css); // variante dark
        // Hotel: borde exacto + tinte
        $this->assertStringContainsString('--ch-inn-borde: #123456;', $css);
        $this->assertStringContainsString('--ch-inn-tinte:', $css);
        // Slug compuesto del estado largo
        $this->assertStringContainsString('--ce-completada_pendiente_auditoria-bg:', $css);
    }

    public function testVariantesDerivadasTienenFormatoValido(): void
    {
        $v = Colores::variantes('#eab308');
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $v['bg']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $v['fg']);
        $this->assertMatchesRegularExpression('/^rgba\(\d+, \d+, \d+, 0\.28\)$/', $v['bgDark']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $v['fgDark']);

        // El fondo claro es más claro que el base y el texto claro más oscuro
        $this->assertNotSame($v['bg'], $v['fg']);

        $a = Colores::variantesAcento('#14b8a6');
        $this->assertSame('#14b8a6', $a['borde']);
        $this->assertStringStartsWith('rgba(20, 184, 166,', $a['tinte']);
    }

    public function testValidarHex(): void
    {
        $this->assertTrue(Colores::validarHex('#eab308'));
        $this->assertTrue(Colores::validarHex('#EAB308'));
        $this->assertFalse(Colores::validarHex('eab308'));
        $this->assertFalse(Colores::validarHex('#fff'));
        $this->assertFalse(Colores::validarHex('#12345g'));
        $this->assertFalse(Colores::validarHex('red'));
    }
}
