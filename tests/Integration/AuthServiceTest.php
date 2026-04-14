<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\AuthException;
use Atankalama\Limpieza\Services\AuthService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    private AuthService $auth;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        $this->auth = new AuthService();
    }

    public function testLoginExitosoAdmin(): void
    {
        [$id, $pwd] = TestDatabase::crearUsuario('11111111-1', 'Admin Test', 'Admin');
        $resultado = $this->auth->login('11.111.111-1', $pwd);

        $this->assertSame($id, $resultado['usuario']->id);
        $this->assertSame('/home-admin', $resultado['home_target']);
        $this->assertNotEmpty($resultado['token']);

        $fila = Database::fetchOne('SELECT usuario_id FROM sesiones WHERE token = ?', [$resultado['token']]);
        $this->assertNotNull($fila);
    }

    public function testLoginRechazaPasswordIncorrecta(): void
    {
        TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('RUT o contraseña incorrectos.');
        $this->auth->login('11111111-1', 'pwd_mala');
    }

    public function testLoginRechazaRutInvalido(): void
    {
        $this->expectException(AuthException::class);
        try {
            $this->auth->login('abc', 'x');
        } catch (AuthException $e) {
            $this->assertSame('RUT_INVALIDO', $e->codigo);
            throw $e;
        }
    }

    public function testLoginRechazaUsuarioInactivo(): void
    {
        TestDatabase::crearUsuario('11111111-1', 'Inactivo', 'Trabajador', activo: false);

        try {
            $this->auth->login('11111111-1', 'Abc12345');
            $this->fail('Debía lanzar AuthException');
        } catch (AuthException $e) {
            $this->assertSame('USUARIO_INACTIVO', $e->codigo);
            $this->assertSame(403, $e->httpStatus);
        }
    }

    public function testHomeTargetTrabajador(): void
    {
        [, $pwd] = TestDatabase::crearUsuario('22222222-2', 'Juan', 'Trabajador');
        $r = $this->auth->login('22222222-2', $pwd);
        $this->assertSame('/home-trabajador', $r['home_target']);
    }

    public function testHomeTargetSupervisora(): void
    {
        [, $pwd] = TestDatabase::crearUsuario('33333333-3', 'Ana', 'Supervisora');
        $r = $this->auth->login('33333333-3', $pwd);
        $this->assertSame('/home-supervisora', $r['home_target']);
    }

    public function testHomeTargetRecepcion(): void
    {
        [, $pwd] = TestDatabase::crearUsuario('44444444-4', 'Carla', 'Recepción');
        $r = $this->auth->login('44444444-4', $pwd);
        $this->assertSame('/home-recepcion', $r['home_target']);
    }

    public function testValidarSesionRenuevaExpiracion(): void
    {
        [, $pwd] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');
        $r = $this->auth->login('11111111-1', $pwd);

        $usuario = $this->auth->validarSesion($r['token']);
        $this->assertNotNull($usuario);
        $this->assertSame('Admin', $usuario->roles[0]);
    }

    public function testValidarSesionExpiradaRetornaNull(): void
    {
        [, $pwd] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');
        $r = $this->auth->login('11111111-1', $pwd);

        Database::execute('UPDATE sesiones SET expires_at = ? WHERE token = ?', ['2020-01-01T00:00:00.000Z', $r['token']]);

        $this->assertNull($this->auth->validarSesion($r['token']));
        $this->assertNull(Database::fetchOne('SELECT 1 FROM sesiones WHERE token = ?', [$r['token']]));
    }

    public function testLogoutEliminaSesion(): void
    {
        [, $pwd] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');
        $r = $this->auth->login('11111111-1', $pwd);

        $this->auth->logout($r['token'], $r['usuario']->id);
        $this->assertNull(Database::fetchOne('SELECT 1 FROM sesiones WHERE token = ?', [$r['token']]));
    }

    public function testCambiarContrasenaExitoso(): void
    {
        [$id] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin', requiereCambio: true);
        Database::execute(
            'INSERT INTO contrasenas_temporales (usuario_id, motivo) VALUES (?, ?)',
            [$id, 'creacion']
        );

        $this->auth->cambiarContrasena($id, 'Abc12345', 'NuevaPwd123', 'NuevaPwd123');

        $fila = Database::fetchOne('SELECT requiere_cambio_pwd FROM usuarios WHERE id = ?', [$id]);
        $this->assertSame(0, (int) $fila['requiere_cambio_pwd']);

        $temp = Database::fetchOne('SELECT usada FROM contrasenas_temporales WHERE usuario_id = ?', [$id]);
        $this->assertSame(1, (int) $temp['usada']);
    }

    public function testCambiarContrasenaRechazaConfirmacionDistinta(): void
    {
        [$id] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');

        try {
            $this->auth->cambiarContrasena($id, 'Abc12345', 'nueva123A', 'diferente123A');
            $this->fail('Debía lanzar');
        } catch (AuthException $e) {
            $this->assertSame('PWD_NO_COINCIDE', $e->codigo);
        }
    }

    public function testCambiarContrasenaRechazaDebil(): void
    {
        [$id] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');
        try {
            $this->auth->cambiarContrasena($id, 'Abc12345', 'corta', 'corta');
            $this->fail('Debía lanzar');
        } catch (AuthException $e) {
            $this->assertSame('PWD_DEBIL', $e->codigo);
        }
    }

    public function testCambiarContrasenaRechazaActualIncorrecta(): void
    {
        [$id] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');
        try {
            $this->auth->cambiarContrasena($id, 'pwd_mala', 'NuevaPwd123', 'NuevaPwd123');
            $this->fail('Debía lanzar');
        } catch (AuthException $e) {
            $this->assertSame('PWD_ACTUAL_INCORRECTA', $e->codigo);
        }
    }

    public function testResetearTemporalGeneraPasswordYRequiereCambio(): void
    {
        [$adminId] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');
        [$targetId] = TestDatabase::crearUsuario('22222222-2', 'Target', 'Trabajador');

        $temporal = $this->auth->resetearContrasenaTemporal($targetId, $adminId);
        $this->assertSame(8, strlen($temporal));

        $fila = Database::fetchOne('SELECT requiere_cambio_pwd FROM usuarios WHERE id = ?', [$targetId]);
        $this->assertSame(1, (int) $fila['requiere_cambio_pwd']);

        $r = $this->auth->login('22222222-2', $temporal);
        $this->assertTrue($r['usuario']->requiereCambioPwd);
    }
}
