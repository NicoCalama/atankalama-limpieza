<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit\VistaGuiada;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Support\TourResolver;
use Atankalama\Limpieza\Support\Tours;
use PHPUnit\Framework\TestCase;

/**
 * Seguridad de la Vista Guiada:
 *   1. El payload que viaja al navegador NO filtra datos del usuario (solo su id).
 *   2. El motor JS no usa innerHTML / eval / new Function / onX= / document.write
 *      (piso anti-XSS). Este es el canario que mira el MOTOR, no una ruta al azar.
 */
final class ToursSecurityTest extends TestCase
{
    public function test_el_payload_no_filtra_datos_del_usuario(): void
    {
        $can = static fn (string $cap): bool => true; // usuario con todos los permisos
        $prohibidos = ['nombre', 'name', 'email', 'correo', 'rol', 'role', 'roles', 'rut', 'password', 'remember_token', 'hotel_default'];

        foreach (array_keys(Tours::catalog()) as $pantalla) {
            $payload = TourResolver::payload($pantalla, $can, 7);
            if ($payload === null) {
                continue;
            }
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $this->assertNotFalse($json);

            // Solo debe existir el id numérico del usuario.
            $this->assertSame(7, $payload['u']);
            $this->assertArrayNotHasKey('user', $payload);
            $this->assertArrayNotHasKey('usuario', $payload);

            $data = json_decode($json, true);
            $this->assertClavesNoContienen($data, $prohibidos, $pantalla);
        }
    }

    public function test_el_motor_no_usa_innerHTML_ni_eval_ni_onclick(): void
    {
        $ruta = Config::basePath() . '/public/assets/vista-guiada/vista-guiada.js';
        $this->assertFileExists($ruta, 'No encontré el motor vista-guiada.js');

        // Escanea SOLO el código ejecutable: quita comentarios primero. Si no, la
        // propia documentación del motor ("pinta sin innerHTML") daría falso positivo.
        $js = $this->sinComentarios((string) file_get_contents($ruta));

        $this->assertStringNotContainsString('innerHTML', $js, 'El motor no debe usar innerHTML (pinta con textContent).');
        $this->assertStringNotContainsString('eval(', $js, 'El motor no debe usar eval().');
        $this->assertStringNotContainsString('new Function', $js, 'El motor no debe usar new Function().');
        $this->assertDoesNotMatchRegularExpression('/\bon\w+\s*=/', $js, 'El motor no debe cablear con onclick=; usa addEventListener.');
        $this->assertStringNotContainsString('document.write', $js, 'El motor no debe usar document.write.');
    }

    /** Quita comentarios de bloque y de línea (aprox., suficiente para el canario). */
    private function sinComentarios(string $js): string
    {
        $js = (string) preg_replace('#/\*.*?\*/#s', '', $js);       // bloques /* ... */
        $js = (string) preg_replace('#(^|\s)//[^\n]*#', '$1', $js); // línea // ...
        return $js;
    }

    private function assertClavesNoContienen(mixed $nodo, array $prohibidos, string $pantalla): void
    {
        if (is_array($nodo)) {
            foreach ($nodo as $clave => $valor) {
                if (is_string($clave)) {
                    $this->assertNotContains(
                        mb_strtolower($clave),
                        $prohibidos,
                        "[$pantalla] el payload expone la clave sensible \"$clave\""
                    );
                }
                $this->assertClavesNoContienen($valor, $prohibidos, $pantalla);
            }
        }
    }
}
