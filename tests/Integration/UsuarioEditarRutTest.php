<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Kernel;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\AuthException;
use Atankalama\Limpieza\Services\AuthService;
use Atankalama\Limpieza\Services\UsuarioException;
use Atankalama\Limpieza\Services\UsuarioService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * v7.13 (30/10/2026): el RUT de un usuario se puede cambiar desde su ficha (docs/usuarios.md §3.4).
 */
final class UsuarioEditarRutTest extends TestCase
{
    /** 12.345.678-5: dígito verificador correcto. */
    private const RUT_NUEVO = '12345678-5';

    private int $adminId;
    private string $adminPwd;
    private int $supId;
    private int $anaId;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        [$this->adminId, $this->adminPwd] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');
        [$this->supId] = TestDatabase::crearUsuario('22222222-2', 'Sofía', 'Supervisora');
        [$this->anaId] = TestDatabase::crearUsuario('33333333-3', 'Ana', 'Aseo');
    }

    public function testConElRutNuevoEntraYConElViejoYaNo(): void
    {
        $u = (new UsuarioService())->actualizar($this->anaId, ['rut' => '12.345.678-5'], $this->adminId);

        $this->assertSame(self::RUT_NUEVO, $u->rut);
        $this->assertSame($this->anaId, $u->id);
        $login = (new AuthService())->login(self::RUT_NUEVO, 'Abc12345');
        $this->assertSame($this->anaId, $login['usuario']->id);
        try {
            (new AuthService())->login('33333333-3', 'Abc12345');
            $this->fail('El RUT viejo ya no debía entrar');
        } catch (AuthException $e) {
            $this->assertSame('CREDENCIALES_INVALIDAS', $e->codigo);
        }
    }

    public function testElCambioQuedaEnLaAuditoriaConElRutAnterior(): void
    {
        (new UsuarioService())->actualizar($this->anaId, ['rut' => '12.345.678-5'], $this->adminId);

        $detalle = json_decode((string) Database::fetchColumn(
            "SELECT detalles_json FROM audit_log WHERE accion = 'usuario.actualizar' AND entidad_id = ?",
            [$this->anaId]
        ), true);
        $this->assertSame(['rut' => self::RUT_NUEVO, 'rut_anterior' => '33333333-3'], $detalle);
    }

    public function testElMismoRutNoCambiaNadaNiQuedaEnLaAuditoria(): void
    {
        (new UsuarioService())->actualizar($this->anaId, ['rut' => '33.333.333-3'], $this->adminId);

        $this->assertSame('33333333-3', Database::fetchColumn('SELECT rut FROM usuarios WHERE id = ?', [$this->anaId]));
        $this->assertSame(0, (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM audit_log WHERE accion = 'usuario.actualizar'"
        ));
    }

    public function testUnRutConDigitoVerificadorMaloDa400(): void
    {
        $this->esperarError(['rut' => '12.345.678-9'], 'RUT_INVALIDO', 400);
        $this->esperarError(['rut' => ['12345678-5']], 'RUT_INVALIDO', 400);
        $this->assertSame('33333333-3', Database::fetchColumn('SELECT rut FROM usuarios WHERE id = ?', [$this->anaId]));
    }

    public function testElRutDeOtraPersonaDa409(): void
    {
        $this->esperarError(['rut' => '22.222.222-2'], 'RUT_DUPLICADO', 409);
        $this->assertSame('33333333-3', Database::fetchColumn('SELECT rut FROM usuarios WHERE id = ?', [$this->anaId]));
    }

    public function testUnUsuarioEliminadoNoRecuperaUnRut(): void
    {
        (new UsuarioService())->eliminar($this->anaId, $this->adminId);

        $this->esperarError(['rut' => self::RUT_NUEVO], 'USUARIO_ELIMINADO', 409);
        $this->assertStringStartsWith('eliminado-', (string) Database::fetchColumn('SELECT rut FROM usuarios WHERE id = ?', [$this->anaId]));
    }

    public function testNoSeCambiaElRutDeAlguienConMasPermisos(): void
    {
        $rolSup = (int) Database::fetchColumn("SELECT id FROM roles WHERE nombre = 'Supervisora'");
        Database::execute('INSERT INTO rol_permisos (rol_id, permiso_codigo) VALUES (?, ?)', [$rolSup, 'usuarios.editar']);

        try {
            (new UsuarioService())->actualizar($this->adminId, ['rut' => self::RUT_NUEVO], $this->supId);
            $this->fail('Debía lanzar PRIVILEGIOS_INSUFICIENTES');
        } catch (UsuarioException $e) {
            $this->assertSame('PRIVILEGIOS_INSUFICIENTES', $e->codigo);
            $this->assertSame(403, $e->httpStatus);
        }
        $this->assertSame('11111111-1', Database::fetchColumn('SELECT rut FROM usuarios WHERE id = ?', [$this->adminId]));
    }

    public function testPorLaApiElAdminCambiaElRutYLaTrabajadoraNo(): void
    {
        $resp = $this->pedirPut($this->anaId, ['rut' => '12.345.678-5'], (new AuthService())->login('11111111-1', $this->adminPwd)['token']);
        $this->assertSame(200, $resp->status);
        $this->assertSame(self::RUT_NUEVO, json_decode($resp->cuerpo, true)['data']['usuario']['rut']);

        $resp = $this->pedirPut($this->supId, ['rut' => '44.444.444-4'], (new AuthService())->login(self::RUT_NUEVO, 'Abc12345')['token']);
        $this->assertSame(403, $resp->status);
        $this->assertSame('22222222-2', Database::fetchColumn('SELECT rut FROM usuarios WHERE id = ?', [$this->supId]));
    }

    /** @param array<string, mixed> $datos */
    private function esperarError(array $datos, string $codigo, int $status): void
    {
        try {
            (new UsuarioService())->actualizar($this->anaId, $datos, $this->adminId);
            $this->fail("Debía lanzar {$codigo}");
        } catch (UsuarioException $e) {
            $this->assertSame($codigo, $e->codigo);
            $this->assertSame($status, $e->httpStatus);
        }
    }

    /** @param array<string, mixed> $cuerpo */
    private function pedirPut(int $usuarioId, array $cuerpo, string $token): Response
    {
        return Kernel::construirRouter()->despachar(new Request(
            metodo: 'PUT', path: "/api/usuarios/{$usuarioId}", cuerpo: $cuerpo, cookies: [AuthService::SESSION_COOKIE => $token]
        ));
    }
}
