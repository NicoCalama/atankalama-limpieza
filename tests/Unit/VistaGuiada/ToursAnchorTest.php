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
 * el HTML inicial. Nuestras vistas no los usan (todas las anclas van a contenedores
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
        // La ruta /asignaciones resuelve directo a esta pantalla del catálogo.
        $this->verificarAnclas('asignaciones', 'asignaciones');
    }

    public function test_anclas_de_home_supervisora(): void
    {
        // /home incluye home-supervisora.php para la supervisora (ver home.php);
        // TourResolver::resolveHome() mapea ese caso a 'home.supervisora'.
        $this->verificarAnclas('home.supervisora', 'home-supervisora');
    }

    public function test_anclas_de_habitacion_detalle(): void
    {
        // /habitaciones/{id} → 'habitacion.detalle' (TourResolver por patrón).
        // La vista necesita $habitacionId además de $usuario.
        $this->verificarAnclas('habitacion.detalle', 'habitacion-detalle', ['habitacionId' => 1]);
    }

    public function test_anclas_de_auditoria_bandeja(): void
    {
        // /auditoria → 'auditoria.bandeja' (ruta fija en MAP).
        $this->verificarAnclas('auditoria.bandeja', 'auditoria-bandeja');
    }

    public function test_anclas_de_auditoria_detalle(): void
    {
        // /auditoria/{id} → 'auditoria.detalle' (TourResolver por patrón).
        $this->verificarAnclas('auditoria.detalle', 'auditoria-detalle', ['habitacionId' => 1]);
    }

    public function test_anclas_de_espacios(): void
    {
        // /espacios → 'espacios' (ruta fija en MAP).
        $this->verificarAnclas('espacios', 'espacios');
    }

    public function test_anclas_de_tickets(): void
    {
        // /tickets → 'tickets' (ruta fija en MAP).
        $this->verificarAnclas('tickets', 'tickets');
    }

    public function test_anclas_de_habitaciones(): void
    {
        // /habitaciones → 'habitaciones' (ruta fija en MAP). Los filtros están
        // gateados por PHP (habitaciones.ver_todas), que el stub tiene.
        $this->verificarAnclas('habitaciones', 'habitaciones');
    }

    public function test_anclas_de_ajustes_turnos(): void
    {
        // /ajustes/turnos → 'ajustes.turnos' (ruta fija en MAP).
        $this->verificarAnclas('ajustes.turnos', 'ajustes-turnos');
    }

    public function test_anclas_de_home_trabajador(): void
    {
        // /home incluye home-trabajador.php para el encargado (fallback de
        // resolveHome). El bloque «Reportar» está gateado por PHP (tickets.crear),
        // que el stub tiene.
        $this->verificarAnclas('home.trabajador', 'home-trabajador');
    }

    public function test_anclas_de_habitaciones_trabajador(): void
    {
        // /habitaciones para el trabajador (SIN ver_todas): ve la grilla hb.grid;
        // los filtros hb.filtros están gateados por PHP y NO deben aparecer (por
        // eso se renderiza con un stub trabajador).
        $this->verificarAnclas('habitaciones.trabajador', 'habitaciones', [], $this->usuarioTrabajadorStub());
    }

    public function test_anclas_de_home_recepcion(): void
    {
        $this->verificarAnclas('home.recepcion', 'home-recepcion');
    }

    public function test_anclas_de_home_admin(): void
    {
        $this->verificarAnclas('home.admin', 'home-admin');
    }

    public function test_anclas_de_usuarios(): void
    {
        $this->verificarAnclas('usuarios', 'usuarios');
    }

    public function test_anclas_de_reportes(): void
    {
        $this->verificarAnclas('reportes', 'reportes');
    }

    public function test_anclas_de_ajustes_rbac(): void
    {
        $this->verificarAnclas('ajustes.rbac', 'ajustes-rbac');
    }

    public function test_anclas_de_ajustes_checklists(): void
    {
        $this->verificarAnclas('ajustes.checklists', 'ajustes-checklists');
    }

    public function test_anclas_de_ajustes_alertas(): void
    {
        $this->verificarAnclas('ajustes.alertas', 'ajustes-alertas');
    }

    public function test_anclas_de_ajustes_importar_turnos(): void
    {
        $this->verificarAnclas('ajustes.importar_turnos', 'ajustes/importar-turnos');
    }

    public function test_anclas_de_ajustes(): void
    {
        $this->verificarAnclas('ajustes', 'ajustes');
    }

    public function test_anclas_de_ajustes_colores(): void
    {
        $this->verificarAnclas('ajustes.colores', 'ajustes-colores');
    }

    public function test_anclas_de_ajustes_mi_cuenta(): void
    {
        $this->verificarAnclas('ajustes.mi_cuenta', 'ajustes-mi-cuenta');
    }

    public function test_anclas_de_ajustes_versiones(): void
    {
        // La vista necesita $versiones (del Changelog) para renderizar la lista.
        $this->verificarAnclas('ajustes.versiones', 'ajustes-versiones', [
            'versiones' => \Atankalama\Limpieza\Helpers\Changelog::versiones(),
        ]);
    }

    /**
     * Renderiza el template real con un usuario stub y verifica anclas ↔ pasos.
     *
     * @param array<string, mixed> $datosExtra
     */
    private function verificarAnclas(string $pantalla, string $template, array $datosExtra = [], ?Usuario $usuario = null): void
    {
        $usuario ??= $this->usuarioStub();
        $catalogo = Tours::catalog();
        $this->assertArrayHasKey($pantalla, $catalogo, "El catálogo no tiene la pantalla $pantalla");

        $html = View::renderizar($template, ['usuario' => $usuario] + $datosExtra)->cuerpo;
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
                "data-tour huérfano en la vista $pantalla (sin paso que lo use): $enHtml"
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
            permisos: [
                'asignaciones.asignar_manual',
                'asignaciones.auto_asignar',
                'alertas.recibir_predictivas',
                'habitaciones.marcar_completada',
                'habitaciones.ver_todas',
                'auditoria.ver_bandeja',
                'espacios.ver',
                'espacios.crear_editar',
                'tickets.ver_todos',
                'tickets.crear',
                'usuarios.crear',
                'turnos.ver',
                'turnos.crear_editar',
                'turnos.asignar_a_usuario',
            ],
            roles: ['Supervisora'],
        );
    }

    /** Trabajador: SIN habitaciones.ver_todas (no ve los filtros de la supervisora). */
    private function usuarioTrabajadorStub(): Usuario
    {
        return new Usuario(
            id: 3,
            rut: '16000001-5',
            nombre: 'Ana Trabajadora',
            email: null,
            activo: true,
            requiereCambioPwd: false,
            hotelDefault: 'inn',
            temaPreferido: 'claro',
            permisos: [
                'habitaciones.ver_asignadas_propias',
                'habitaciones.marcar_completada',
                'tickets.crear',
            ],
            roles: ['Trabajador'],
        );
    }
}
