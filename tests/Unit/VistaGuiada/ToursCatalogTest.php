<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit\VistaGuiada;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Support\Tours;
use PHPUnit\Framework\TestCase;

/**
 * Hace cumplir las REGLAS DURAS del catálogo de la Vista Guiada (ver el docblock
 * de Tours.php). Si un texto crece de más o un título colisiona, la suite se pone
 * roja ANTES de que un usuario final lea algo confuso. Control de calidad que no
 * depende de la firma humana.
 *
 * Portado del kit; adaptado a nuestro PHPUnit y a nuestro RBAC (capacidadesValidas
 * lee el catálogo real de permisos en database/seeds/permisos.php).
 */
final class ToursCatalogTest extends TestCase
{
    private const TITULO_MAX   = 28;
    private const PREGUNTA_MAX = 60;
    private const TEXTO_MAX    = 220;
    private const PASOS_MAX    = 5;
    private const FRASES_MAX   = 3;   // ideal 2; 3 es el techo tolerado
    private const SIEMPRE_MAX  = Tours::MAX_DISPONIBLES; // recorridos sin `requiere`

    /** Voseo argentino que NO debe aparecer en textos de usuario final (tuteo chileno). */
    private const VOSEO = [
        'subí', 'actualizá', 'corregí', 'elegí', 'abrí', 'guardá', 'ingresá',
        'seleccioná', 'hacé', 'hacés', 'tenés', 'podés', 'mirá', 'fijate',
        'apretá', 'andá', 'poné', 'sacá', 'dale',
    ];

    public function test_estructura_y_limites_de_cada_recorrido(): void
    {
        foreach (Tours::catalog() as $pantalla => $entrada) {
            $this->assertArrayHasKey('recorridos', $entrada, "[$pantalla] sin recorridos");

            $primerasPalabras = [];
            $sinRequisito = 0;

            foreach ($entrada['recorridos'] as $r) {
                $ctx = "[$pantalla/{$r['id']}]";

                $this->assertNotEmpty($r['id'] ?? null, "$ctx sin id");
                $this->assertIsInt($r['v'] ?? 1, "$ctx v debe ser int");

                // título: <= 28 y primera palabra única en la pantalla
                $this->assertLessThanOrEqual(
                    self::TITULO_MAX,
                    mb_strlen($r['titulo']),
                    "$ctx título > " . self::TITULO_MAX . " caracteres: \"{$r['titulo']}\""
                );
                $primera = mb_strtolower(explode(' ', trim($r['titulo']))[0]);
                $this->assertNotContains(
                    $primera,
                    $primerasPalabras,
                    "$ctx la primera palabra \"$primera\" ya se usó en esta pantalla (se elige por reconocimiento)"
                );
                $primerasPalabras[] = $primera;

                // pregunta <= 60
                $this->assertLessThanOrEqual(
                    self::PREGUNTA_MAX,
                    mb_strlen($r['pregunta'] ?? ''),
                    "$ctx pregunta > " . self::PREGUNTA_MAX . " caracteres"
                );

                if (empty($r['requiere'])) {
                    $sinRequisito++;
                }

                // pasos
                $this->assertLessThanOrEqual(
                    self::PASOS_MAX,
                    count($r['pasos']),
                    "$ctx más de " . self::PASOS_MAX . " pasos"
                );
                foreach ($r['pasos'] as $i => $p) {
                    $pctx = "$ctx paso $i";
                    $this->assertNotEmpty($p['sel'] ?? null, "$pctx sin selector");
                    $this->assertStringStartsWith('[data-tour=', $p['sel'], "$pctx el ancla debe ser un [data-tour=...]");

                    $texto = $p['texto'] ?? '';
                    $this->assertLessThanOrEqual(
                        self::TEXTO_MAX,
                        mb_strlen($texto),
                        "$pctx texto > " . self::TEXTO_MAX . " caracteres"
                    );
                    $frases = count(preg_split('/[.!?]+(\s|$)/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY));
                    $this->assertLessThanOrEqual(self::FRASES_MAX, $frases, "$pctx más de " . self::FRASES_MAX . " frases");

                    // Anti-XSS / anti-jerga: sin '<' crudo
                    $this->assertStringNotContainsString('<', $texto, "$pctx contiene '<'");

                    // 'dialogo' exige 'abrir' en el PRIMER paso de diálogo del recorrido
                    if (($p['modo'] ?? null) === 'dialogo' && $i > 0) {
                        $anterior = $r['pasos'][$i - 1] ?? [];
                        if (($anterior['modo'] ?? null) !== 'dialogo') {
                            $this->assertNotEmpty($p['abrir'] ?? null, "$pctx primer paso de diálogo sin 'abrir'");
                        }
                    }
                }
            }

            $this->assertLessThanOrEqual(
                self::SIEMPRE_MAX,
                $sinRequisito,
                "[$pantalla] tiene $sinRequisito recorridos SIN precondición; el selector solo muestra " . self::SIEMPRE_MAX
            );
        }
    }

    public function test_sin_voseo_argentino(): void
    {
        foreach ($this->todosLosTextos() as $ctx => $texto) {
            $low = mb_strtolower($texto);
            foreach (self::VOSEO as $palabra) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\b' . preg_quote($palabra, '/') . '\b/u',
                    $low,
                    "$ctx usa voseo \"$palabra\" (tuteo chileno, por favor)"
                );
            }
        }
    }

    public function test_capacidades_declaradas_existen(): void
    {
        $validas = $this->capacidadesValidas();
        foreach (Tours::catalog() as $pantalla => $entrada) {
            if (!empty($entrada['capacidad'])) {
                $this->assertContains($entrada['capacidad'], $validas, "[$pantalla] capacidad inexistente: {$entrada['capacidad']}");
            }
            foreach ($entrada['recorridos'] as $r) {
                if (!empty($r['capacidad'])) {
                    $this->assertContains($r['capacidad'], $validas, "[$pantalla/{$r['id']}] capacidad inexistente: {$r['capacidad']}");
                }
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return iterable<string, string> ctx => texto */
    private function todosLosTextos(): iterable
    {
        foreach (Tours::catalog() as $pantalla => $entrada) {
            foreach ($entrada['recorridos'] as $r) {
                yield "[$pantalla/{$r['id']}] titulo" => $r['titulo'];
                yield "[$pantalla/{$r['id']}] pregunta" => $r['pregunta'] ?? '';
                foreach (($r['motivos'] ?? []) as $m) {
                    yield "[$pantalla/{$r['id']}] motivo" => $m;
                }
                foreach ($r['pasos'] as $i => $p) {
                    yield "[$pantalla/{$r['id']}] paso $i titulo" => $p['titulo'];
                    yield "[$pantalla/{$r['id']}] paso $i texto" => $p['texto'];
                    if (!empty($p['abrir'])) {
                        yield "[$pantalla/{$r['id']}] paso $i abrir" => $p['abrir'];
                    }
                }
            }
        }
    }

    /**
     * Permisos reales de la app (database/seeds/permisos.php). Cada fila es
     * [codigo, descripcion, grupo, alcance]; nos quedamos con el código.
     *
     * @return array<int, string>
     */
    private function capacidadesValidas(): array
    {
        $seed = require Config::basePath() . '/database/seeds/permisos.php';
        return array_map(static fn (array $fila): string => $fila[0], $seed);
    }
}
