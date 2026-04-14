<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Middleware\AuthCheck;
use Atankalama\Limpieza\Middleware\PermissionCheck;
use Atankalama\Limpieza\Services\AuthService;
use Atankalama\Limpieza\Services\UsuarioService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class MiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        TestDatabase::recrear();
    }

    private function request(array $cookies = [], array $ruta = []): Request
    {
        return new Request(
            metodo: 'GET',
            path: '/api/test',
            cuerpo: [],
            ruta: $ruta,
            query: [],
            cookies: $cookies,
            headers: [],
        );
    }

    public function testAuthCheckSinCookieRetorna401(): void
    {
        $mw = new AuthCheck();
        $resp = $mw->handle($this->request(), fn() => Response::ok([]));
        $this->assertSame(401, $resp->status);
        $this->assertStringContainsString('NO_AUTENTICADO', $resp->cuerpo);
    }

    public function testAuthCheckConSesionValidaLlamaAlNext(): void
    {
        [$id, $pwd] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');
        $r = (new AuthService())->login('11111111-1', $pwd);

        $mw = new AuthCheck();
        $req = $this->request(['session' => $r['token']]);

        $llamado = false;
        $resp = $mw->handle($req, function (Request $r) use (&$llamado) {
            $llamado = true;
            $this->assertNotNull($r->usuario);
            $this->assertSame('Admin', $r->usuario->roles[0]);
            return Response::ok(['ok' => 1]);
        });

        $this->assertTrue($llamado);
        $this->assertSame(200, $resp->status);
    }

    public function testPermissionCheckBloqueaSinPermiso(): void
    {
        [$usuarioId] = TestDatabase::crearUsuario('22222222-2', 'Trabajador', 'Trabajador');
        $usuario = (new UsuarioService())->buscarPorId($usuarioId);

        $req = $this->request();
        $req->usuario = $usuario;
        $req->permisos = $usuario->permisos;

        $mw = new PermissionCheck('ajustes.acceder');
        $resp = $mw->handle($req, fn() => Response::ok([]));

        $this->assertSame(403, $resp->status);
        $this->assertStringContainsString('PERMISO_INSUFICIENTE', $resp->cuerpo);
    }

    public function testPermissionCheckPermiteConPermiso(): void
    {
        [$adminId] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');
        $usuario = (new UsuarioService())->buscarPorId($adminId);

        $req = $this->request();
        $req->usuario = $usuario;
        $req->permisos = $usuario->permisos;

        $mw = new PermissionCheck('ajustes.acceder');
        $resp = $mw->handle($req, fn() => Response::ok(['ok' => 1]));
        $this->assertSame(200, $resp->status);
    }

    public function testPermissionCheckOrMultiplesPermisos(): void
    {
        [$id] = TestDatabase::crearUsuario('33333333-3', 'Rec', 'Recepción');
        $usuario = (new UsuarioService())->buscarPorId($id);

        $req = $this->request();
        $req->usuario = $usuario;
        $req->permisos = $usuario->permisos;

        $mw = new PermissionCheck(['ajustes.acceder', 'auditoria.aprobar']);
        $resp = $mw->handle($req, fn() => Response::ok([]));
        $this->assertSame(200, $resp->status);
    }
}
