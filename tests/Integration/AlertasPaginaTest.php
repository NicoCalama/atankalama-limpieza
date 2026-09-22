<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\PaginasController;
use Atankalama\Limpieza\Core\Kernel;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Models\Usuario;
use PHPUnit\Framework\TestCase;

/**
 * Pantalla «Todas las alertas» (GET /alertas).
 *
 * Regresión: los dos Inicios enlazaban a /alertas cuando hay más de 5 alertas, pero la
 * ruta nunca existió → el router caía en su 404 genérico y el navegador mostraba el JSON
 * {"ok":false,"error":{"codigo":"NO_ENCONTRADO"}} en vez de una pantalla.
 */
final class AlertasPaginaTest extends TestCase
{
    private function request(?Usuario $usuario = null): Request
    {
        $r = new Request(
            metodo: 'GET',
            path: '/alertas',
            cuerpo: [],
            ruta: [],
            query: [],
            cookies: [],
            headers: [],
        );
        $r->usuario = $usuario;
        return $r;
    }

    private function usuario(array $permisos, bool $requiereCambioPwd = false): Usuario
    {
        return new Usuario(
            id: 1,
            rut: '11111111-1',
            nombre: 'Supervisora Prueba',
            email: null,
            activo: true,
            requiereCambioPwd: $requiereCambioPwd,
            hotelDefault: null,
            temaPreferido: 'claro',
            permisos: $permisos,
            roles: ['Supervisora'],
        );
    }

    /** El bug original: /alertas no estaba registrada y caía en el 404 del router. */
    public function testLaRutaAlertasEstaRegistrada(): void
    {
        $resp = Kernel::construirRouter()->despachar($this->request());

        $this->assertNotSame(404, $resp->status, '/alertas volvió a quedar sin ruta');
        $this->assertStringNotContainsString('NO_ENCONTRADO', $resp->cuerpo);
    }

    /** El enlace de los dos Inicios y la ruta tienen que seguir apuntando al mismo lugar. */
    public function testLosInicioEnlazanALaRutaQueExiste(): void
    {
        foreach (['home-admin.php', 'home-supervisora.php'] as $vista) {
            $html = (string) file_get_contents(__DIR__ . '/../../views/' . $vista);
            $this->assertStringContainsString(
                "u('/alertas')",
                $html,
                "$vista dejó de enlazar a /alertas: si cambió el destino, hay que mover la ruta también"
            );
        }
    }

    public function testConPermisoRenderizaLaPantalla(): void
    {
        $resp = (new PaginasController())->alertas(
            $this->request($this->usuario(['alertas.recibir_predictivas']))
        );

        $this->assertSame(200, $resp->status);
        $this->assertStringContainsString('alertasApp()', $resp->cuerpo);
    }

    public function testSinPermisoRedirigeAlInicio(): void
    {
        $resp = (new PaginasController())->alertas(
            $this->request($this->usuario(['habitaciones.ver_todas']))
        );

        $this->assertSame(302, $resp->status);
        $this->assertStringEndsWith('/home', $resp->headers()['Location'] ?? '');
    }

    public function testSinSesionRedirigeAlLogin(): void
    {
        $resp = (new PaginasController())->alertas($this->request(null));

        $this->assertSame(302, $resp->status);
        $this->assertStringEndsWith('/login', $resp->headers()['Location'] ?? '');
    }

    public function testConCambioDePasswordPendienteRedirige(): void
    {
        $resp = (new PaginasController())->alertas(
            $this->request($this->usuario(['alertas.recibir_predictivas'], requiereCambioPwd: true))
        );

        $this->assertSame(302, $resp->status);
        $this->assertStringEndsWith('/cambiar-contrasena', $resp->headers()['Location'] ?? '');
    }
}
