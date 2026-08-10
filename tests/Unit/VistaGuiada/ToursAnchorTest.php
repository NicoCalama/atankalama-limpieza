<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit\VistaGuiada;

use Atankalama\Limpieza\Core\View;
use Atankalama\Limpieza\Models\Usuario;
use Atankalama\Limpieza\Support\Tours;
use PHPUnit\Framework\TestCase;

/**
 * Anti tour-drift: por cada pantalla con ayuda, renderiza la vista REAL y verifica
 * que cada ancla (`data-tour="..."`) del catálogo exista, y —al revés— que no haya
 * anclas huérfanas sin paso que las use. Se pone rojo cuando alguien refactoriza
 * una vista, en vez de apagarse en silencio (un tour cuyo ancla ya no está).
 *
 * Los pasos `modo => 'dialogo'` viven dentro de un modal cerrado → no se exigen en
 * el HTML inicial. Nuestra vista no los usa (todas las anclas van a contenedores
 * estables), pero el filtro queda por si un recorrido futuro los agrega.
 *
 * Nota: las anclas viven en markup de Alpine (a veces dentro de <template>), que el
 * servidor SÍ emite tal cual (Alpine solo evalúa en el navegador) → basta buscar la
 * cadena literal en el HTML renderizado.
 */
final class ToursAnchorTest extends TestCase
{
    public function test_anclas_de_asignaciones(): void
    {
        $pantalla = 'asignaciones';
        $catalogo = Tours::catalog();
        $this->assertArrayHasKey($pantalla, $catalogo);

        $html = View::renderizar($pantalla, ['usuario' => $this->usuarioStub()])->cuerpo;

        $entrada = $catalogo[$pantalla];

        // 1) Cada ancla NO-diálogo del catálogo existe en el HTML.
        $usadas = [];
        foreach ($entrada['recorridos'] as $r) {
            foreach ($r['pasos'] as $p) {
                if (!preg_match('/data-tour="([^"]+)"/', $p['sel'], $m)) {
                    continue;
                }
                $nombre = $m[1];
                $usadas[$nombre] = true;
                if (($p['modo'] ?? null) === 'dialogo') {
                    continue; // vive dentro de un modal cerrado
                }
                $this->assertStringContainsString(
                    'data-tour="' . $nombre . '"',
                    $html,
                    "Ancla ausente en la vista de $pantalla: $nombre"
                );
            }
        }

        // 2) INVERSO: ningún data-tour del HTML queda huérfano (sin paso que lo use).
        preg_match_all('/data-tour="([^"]+)"/', $html, $encontradas);
        foreach (array_unique($encontradas[1]) as $enHtml) {
            $this->assertArrayHasKey(
                $enHtml,
                $usadas,
                "data-tour huérfano en la vista (sin paso que lo use): $enHtml"
            );
        }
    }

    private function usuarioStub(): Usuario
    {
        return new Usuario(
            id: 1,
            rut: '11111111-1',
            nombre: 'Supervisora Prueba',
            email: null,
            activo: true,
            requiereCambioPwd: false,
            hotelDefault: null,
            temaPreferido: 'claro',
            permisos: ['asignaciones.asignar_manual', 'asignaciones.auto_asignar'],
            roles: ['Supervisora'],
        );
    }
}
