<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\PasswordService;
use Atankalama\Limpieza\Services\UsuarioException;
use Atankalama\Limpieza\Services\UsuarioService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class UsuarioServiceTest extends TestCase
{
    private UsuarioService $svc;
    private PasswordService $pwd;
    private int $adminId;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        [$this->adminId] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');
        $this->svc = new UsuarioService();
        $this->pwd = new PasswordService();
    }

    public function testCrearUsuarioGeneraPasswordTemporal(): void
    {
        $rolId = (int) Database::fetchOne("SELECT id FROM roles WHERE nombre='Trabajador'")['id'];
        $r = $this->svc->crear(
            ['rut' => '22222222-2', 'nombre' => 'Juan', 'email' => 'juan@ex.com', 'hotel_default' => '1_sur', 'roles' => [$rolId]],
            $this->adminId,
            $this->pwd
        );
        $this->assertSame('Juan', $r['usuario']->nombre);
        $this->assertNotSame('', $r['password_temporal']);
        $this->assertContains('Trabajador', $r['usuario']->roles);
    }

    public function testRutDuplicadoLanza(): void
    {
        $this->svc->crear(['rut' => '22222222-2', 'nombre' => 'Juan'], $this->adminId, $this->pwd);
        try {
            $this->svc->crear(['rut' => '22222222-2', 'nombre' => 'Otro'], $this->adminId, $this->pwd);
            $this->fail('Debía lanzar');
        } catch (UsuarioException $e) {
            $this->assertSame('RUT_DUPLICADO', $e->codigo);
        }
    }

    public function testRutInvalidoLanza(): void
    {
        try {
            $this->svc->crear(['rut' => '12345678-0', 'nombre' => 'X'], $this->adminId, $this->pwd);
            $this->fail('Debía lanzar');
        } catch (UsuarioException $e) {
            $this->assertSame('RUT_INVALIDO', $e->codigo);
        }
    }

    public function testNombreVacioLanza(): void
    {
        try {
            $this->svc->crear(['rut' => '22222222-2', 'nombre' => '  '], $this->adminId, $this->pwd);
            $this->fail('Debía lanzar');
        } catch (UsuarioException $e) {
            $this->assertSame('NOMBRE_INVALIDO', $e->codigo);
        }
    }

    public function testEmailInvalidoLanza(): void
    {
        try {
            $this->svc->crear(['rut' => '22222222-2', 'nombre' => 'Juan', 'email' => 'no-es-email'], $this->adminId, $this->pwd);
            $this->fail('Debía lanzar');
        } catch (UsuarioException $e) {
            $this->assertSame('EMAIL_INVALIDO', $e->codigo);
        }
    }

    public function testActualizarCambiaNombreYTema(): void
    {
        $r = $this->svc->crear(['rut' => '22222222-2', 'nombre' => 'Juan'], $this->adminId, $this->pwd);
        $u = $this->svc->actualizar($r['usuario']->id, ['nombre' => 'Juan Perez', 'tema_preferido' => 'oscuro'], $this->adminId);
        $this->assertSame('Juan Perez', $u->nombre);
        $this->assertSame('oscuro', $u->temaPreferido);
    }

    public function testDesactivarEliminaSesiones(): void
    {
        $r = $this->svc->crear(['rut' => '22222222-2', 'nombre' => 'Juan'], $this->adminId, $this->pwd);
        Database::execute(
            "INSERT INTO sesiones (usuario_id, token, expires_at) VALUES (?, 'tok', datetime('now', '+1 day'))",
            [$r['usuario']->id]
        );
        $u = $this->svc->activar($r['usuario']->id, false, $this->adminId);
        $this->assertFalse($u->activo);
        $count = (int) Database::fetchOne('SELECT COUNT(*) AS n FROM sesiones WHERE usuario_id = ?', [$r['usuario']->id])['n'];
        $this->assertSame(0, $count);
    }

    public function testListarFiltraPorBusquedaYRol(): void
    {
        $rolTrab = (int) Database::fetchOne("SELECT id FROM roles WHERE nombre='Trabajador'")['id'];
        $this->svc->crear(['rut' => '22222222-2', 'nombre' => 'Juan', 'roles' => [$rolTrab]], $this->adminId, $this->pwd);
        $this->svc->crear(['rut' => '33333333-3', 'nombre' => 'Maria', 'roles' => [$rolTrab]], $this->adminId, $this->pwd);

        $busqueda = $this->svc->listar(['busqueda' => 'Juan']);
        $this->assertCount(1, $busqueda);
        $this->assertSame('Juan', $busqueda[0]['nombre']);

        $trabajadores = $this->svc->listar(['rol' => 'Trabajador']);
        $nombres = array_column($trabajadores, 'nombre');
        $this->assertContains('Juan', $nombres);
        $this->assertContains('Maria', $nombres);
        $this->assertNotContains('Admin', $nombres);
    }
}
