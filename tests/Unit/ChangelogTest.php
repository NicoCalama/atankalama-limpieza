<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Helpers\Changelog;
use PHPUnit\Framework\TestCase;

final class ChangelogTest extends TestCase
{
    protected function tearDown(): void
    {
        Changelog::limpiarCache();
    }

    private const TABLA = <<<'MD'
        # Historial de versiones

        | Versión | Qué cambió |
        |---|---|
        | **v2** · sin publicar | Cambio nuevo · Otro cambio |
        | **v1.1** · 07/07/2026 | Editor de checklists por tipo · Créditos por peso de cada ítem |
        | **v1** · 07/07/2026 | Primera versión en producción |
        MD;

    public function testParseaLasFilasDeVersionEnOrden(): void
    {
        $versiones = Changelog::parsear(self::TABLA);

        $this->assertCount(3, $versiones);
        $this->assertSame(['2', '1.1', '1'], array_column($versiones, 'version'));
    }

    public function testIgnoraElEncabezadoYLaLineaDeGuiones(): void
    {
        // El encabezado y |---|---| son filas de tabla, pero no de versión.
        $versiones = Changelog::parsear("| Versión | Qué cambió |\n|---|---|\n");

        $this->assertSame([], $versiones);
    }

    public function testSeparaLosCambiosPorElPuntoMedio(): void
    {
        $versiones = Changelog::parsear(self::TABLA);

        $this->assertSame(
            ['Editor de checklists por tipo', 'Créditos por peso de cada ítem'],
            $versiones[1]['cambios']
        );
        $this->assertSame(['Primera versión en producción'], $versiones[2]['cambios']);
    }

    public function testLaFechaDeUnaVersionPublicadaQuedaComoViene(): void
    {
        $versiones = Changelog::parsear(self::TABLA);

        $this->assertTrue($versiones[1]['publicada']);
        $this->assertSame('07/07/2026', $versiones[1]['fecha']);
    }

    public function testSinPublicarNoTieneFechaNiCuentaComoPublicada(): void
    {
        $versiones = Changelog::parsear(self::TABLA);

        $this->assertFalse($versiones[0]['publicada']);
        $this->assertNull($versiones[0]['fecha']);
    }

    public function testUnaFechaImposibleNoSeTomaComoPublicada(): void
    {
        // El patrón DD/MM/YYYY calza, pero 32 de febrero no existe: es un typo,
        // no una versión publicada.
        $versiones = Changelog::parsear('| **v9** · 32/02/2026 | Algo |');

        $this->assertFalse($versiones[0]['publicada']);
    }

    public function testLaFilaDeEjemploDentroDeUnBloqueDeCodigoSeIgnora(): void
    {
        // El CHANGELOG.md documenta su propio formato con una fila de ejemplo entre
        // ``` — si el parser la tomara, saldría como una versión publicada más (y
        // hasta pisaría a la real con el mismo número).
        $md = <<<'MD'
            ## Formato

            ```
            | **v1.1** · 07/07/2026 | Cambio uno · Cambio dos |
            ```

            ## Versiones

            | **v1.1** · 07/07/2026 | Editor de checklists por tipo |
            MD;

        $versiones = Changelog::parsear($md);

        $this->assertCount(1, $versiones);
        $this->assertSame(['Editor de checklists por tipo'], $versiones[0]['cambios']);
    }

    public function testUnaFilaConFormatoRotoSeIgnora(): void
    {
        $roto = "| v3 · 07/07/2026 | Sin los asteriscos |\n| **v3** 07/07/2026 | Sin el punto medio |\n";

        $this->assertSame([], Changelog::parsear($roto));
    }

    public function testActualEsLaUltimaPublicadaIgnorandoLaSinPublicar(): void
    {
        // Una versión mergeada pero no deployada no es "la que estás usando".
        $versiones = Changelog::parsear(self::TABLA);
        $publicadas = array_values(array_filter($versiones, static fn(array $v): bool => $v['publicada']));

        $this->assertSame('1.1', $publicadas[0]['version']);
    }

    public function testElChangelogRealDelRepoParseaYTieneUnaVersionEnUso(): void
    {
        // Guarda del formato: si alguien edita CHANGELOG.md y rompe la tabla, la
        // pantalla /ajustes/versiones se vaciaría en silencio. Que falle acá.
        $versiones = Changelog::versiones();

        $this->assertNotSame([], $versiones, 'CHANGELOG.md no parseó ninguna versión: revisá el formato de la tabla.');
        $this->assertNotNull(Changelog::actual(), 'CHANGELOG.md no tiene ninguna versión publicada con fecha válida.');
        foreach ($versiones as $v) {
            $this->assertNotSame([], $v['cambios'], "La v{$v['version']} no enumera ningún cambio.");
        }

        // Cada versión aparece una sola vez. Un duplicado significa que se coló una fila
        // que no es del historial (un ejemplo de formato, típicamente) o un typo de número.
        $numeros = array_column($versiones, 'version');
        $this->assertSame(array_unique($numeros), $numeros, 'CHANGELOG.md tiene versiones duplicadas: ' . implode(', ', $numeros));

        // Toda fila de versión de la tabla tiene que parsear. El 16/10/2026 la v7.7 y la v7.8 salieron
        // a producción sin el «|» final: el parser las saltó en silencio y el badge siguió en la v7.6.
        $filas = 0;
        $enBloqueDeCodigo = false;
        foreach (preg_split('/\R/u', (string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md')) ?: [] as $linea) {
            $linea = trim($linea);
            if (str_starts_with($linea, '```')) {
                $enBloqueDeCodigo = !$enBloqueDeCodigo;
                continue;
            }
            if (!$enBloqueDeCodigo && str_starts_with($linea, '| **v')) {
                $filas++;
            }
        }
        $this->assertSame($filas, count($versiones), 'Hay filas de versión en CHANGELOG.md que el parser no reconoce (¿falta el «|» final?).');
    }
}
