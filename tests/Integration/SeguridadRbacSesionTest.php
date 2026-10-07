<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\HomeController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Kernel;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Core\Router;
use Atankalama\Limpieza\Middleware\AuthCheck;
use Atankalama\Limpieza\Models\AlertaActiva;
use Atankalama\Limpieza\Services\AlertasService;
use Atankalama\Limpieza\Services\AuthException;
use Atankalama\Limpieza\Services\AuthService;
use Atankalama\Limpieza\Services\ModoEspiaService;
use Atankalama\Limpieza\Services\PasswordService;
use Atankalama\Limpieza\Services\RbacException;
use Atankalama\Limpieza\Services\RbacService;
use Atankalama\Limpieza\Services\UsuarioException;
use Atankalama\Limpieza\Services\UsuarioService;
use Atankalama\Limpieza\Support\EspiaContext;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tanda 2 de la auditoría de código (07/10/2026): RBAC y sesión.
 *  - Nadie da ni toca permisos de gestión de cuentas que no tiene (no más autopromoverse a Admin).
 *  - Asignar roles exige usuarios.asignar_rol, no usuarios.editar.
 *  - En modo espía se puede cerrar sesión.
 *  - La cookie de sesión se renueva con el uso.
 *  - El estado «en riesgo» solo lo ve quien recibe alertas predictivas.
 */
final class SeguridadRbacSesionTest extends TestCase
{
    private int $adminId;
    private string $adminPwd;
    private int $supId;
    private int $anaId;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        [$this->adminId, $this->adminPwd] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');
        [$this->supId] = TestDatabase::crearUsuario('22222222-2', 'Sofía', 'Supervisora');
        [$this->anaId] = TestDatabase::crearUsuario('33333333-3', 'Ana', 'Trabajador');

        // Escenario del hallazgo: el Admin le delega a la supervisora la gestión de usuarios.
        $rolSup = $this->rolId('Supervisora');
        foreach (['usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'usuarios.asignar_rol',
                  'usuarios.resetear_password', 'usuarios.activar_desactivar'] as $permiso) {
            Database::execute('INSERT INTO rol_permisos (rol_id, permiso_codigo) VALUES (?, ?)', [$rolSup, $permiso]);
        }
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(EspiaContext::class);
        $ref->setStaticPropertyValue('activo', false);
        $ref->setStaticPropertyValue('adminNombre', null);
    }

    // --- Anti-escalada ---------------------------------------------------------------------

    public function testNoSePuedeAsignarseElRolAdmin(): void
    {
        try {
            (new RbacService())->asignarRolAUsuario($this->supId, $this->rolId('Admin'), $this->supId);
            $this->fail('Debía lanzar PRIVILEGIOS_INSUFICIENTES');
        } catch (RbacException $e) {
            $this->assertSame('PRIVILEGIOS_INSUFICIENTES', $e->codigo);
            $this->assertSame(403, $e->httpStatus);
        }
        $this->assertNull(Database::fetchOne(
            'SELECT 1 FROM usuarios_roles WHERE usuario_id = ? AND rol_id = ?',
            [$this->supId, $this->rolId('Admin')]
        ));
    }

    public function testSiPuedeAsignarYCrearTrabajadoras(): void
    {
        // El rol Trabajador trae permisos de terreno que la supervisora no tiene: eso no es escalar.
        (new RbacService())->asignarRolAUsuario($this->anaId, $this->rolId('Trabajador'), $this->supId);

        $creado = (new UsuarioService())->crear(
            ['rut' => '44444444-4', 'nombre' => 'Berta', 'roles' => [$this->rolId('Trabajador')]],
            $this->supId,
            new PasswordService()
        );
        $this->assertSame(['Trabajador'], $creado['usuario']->roles);
    }

    public function testNoSePuedeCrearUnaCuentaAdmin(): void
    {
        try {
            (new UsuarioService())->crear(
                ['rut' => '44444444-4', 'nombre' => 'Cuenta propia', 'roles' => [$this->rolId('Admin')]],
                $this->supId,
                new PasswordService()
            );
            $this->fail('Debía lanzar PRIVILEGIOS_INSUFICIENTES');
        } catch (UsuarioException $e) {
            $this->assertSame('PRIVILEGIOS_INSUFICIENTES', $e->codigo);
            $this->assertSame(403, $e->httpStatus);
        }
        $this->assertNull(Database::fetchOne("SELECT id FROM usuarios WHERE rut = '44444444-4'"));
    }

    public function testNoSePuedeTomarLaCuentaDelAdmin(): void
    {
        $usuarios = new UsuarioService();

        // Ni cambiarle el email (para pedir «recuperar contraseña»)...
        try {
            $usuarios->actualizar($this->adminId, ['email' => 'otra@ejemplo.cl'], $this->supId);
            $this->fail('Debía lanzar al editar al Admin');
        } catch (UsuarioException $e) {
            $this->assertSame('PRIVILEGIOS_INSUFICIENTES', $e->codigo);
        }
        // ...ni resetearle la contraseña y recibir la temporal...
        try {
            (new AuthService())->resetearContrasenaTemporal($this->adminId, $this->supId);
            $this->fail('Debía lanzar al resetear al Admin');
        } catch (AuthException $e) {
            $this->assertSame('PRIVILEGIOS_INSUFICIENTES', $e->codigo);
            $this->assertSame(403, $e->httpStatus);
        }
        // ...ni desactivarlo, ni quitarle el rol.
        try {
            $usuarios->activar($this->adminId, false, $this->supId);
            $this->fail('Debía lanzar al desactivar al Admin');
        } catch (UsuarioException $e) {
            $this->assertSame('PRIVILEGIOS_INSUFICIENTES', $e->codigo);
        }
        try {
            (new RbacService())->quitarRolAUsuario($this->adminId, $this->rolId('Admin'), $this->supId);
            $this->fail('Debía lanzar al quitarle el rol al Admin');
        } catch (RbacException $e) {
            $this->assertSame('PRIVILEGIOS_INSUFICIENTES', $e->codigo);
        }

        // El Admin sigue intacto y puede entrar con su clave.
        $this->assertNotNull((new AuthService())->login('11111111-1', $this->adminPwd)['token']);
    }

    public function testSiPuedeGestionarATrabajadorasYASiMisma(): void
    {
        $usuarios = new UsuarioService();
        $this->assertSame('ana@ejemplo.cl', $usuarios->actualizar($this->anaId, ['email' => 'ana@ejemplo.cl'], $this->supId)->email);
        $this->assertNotSame('', (new AuthService())->resetearContrasenaTemporal($this->anaId, $this->supId));
        $this->assertSame('Sofía P.', $usuarios->actualizar($this->supId, ['nombre' => 'Sofía P.'], $this->supId)->nombre);
    }

    public function testElAdminSigueGestionandoATodos(): void
    {
        (new RbacService())->asignarRolAUsuario($this->supId, $this->rolId('Admin'), $this->adminId);
        $this->assertTrue((new UsuarioService())->buscarPorId($this->supId)->tienePermiso('permisos.asignar_a_rol'));
    }

    public function testAsignarRolesExigeUsuariosAsignarRol(): void
    {
        // Solo usuarios.editar (sin asignar_rol): la ruta lo frena antes del servicio.
        Database::execute(
            "DELETE FROM rol_permisos WHERE rol_id = ? AND permiso_codigo = 'usuarios.asignar_rol'",
            [$this->rolId('Supervisora')]
        );
        $resp = $this->pedir(Kernel::construirRouter(), 'POST', "/api/usuarios/{$this->anaId}/roles", $this->login('22222222-2'), [
            'rol_id' => $this->rolId('Trabajador'),
        ]);
        $this->assertSame(403, $resp->status);
        $this->assertSame('PERMISO_INSUFICIENTE', json_decode($resp->cuerpo, true)['error']['codigo']);
    }

    // --- Sesión ----------------------------------------------------------------------------

    public function testEnModoEspiaSePuedeCerrarSesion(): void
    {
        $token = $this->login('11111111-1');
        $admin = (new UsuarioService())->buscarPorId($this->adminId);
        (new ModoEspiaService())->activar($admin, $token, $this->anaId);

        $resp = $this->pedir(Kernel::construirRouter(), 'POST', '/api/auth/logout', $token);

        $this->assertSame(200, $resp->status);
        $this->assertNull(Database::fetchOne('SELECT 1 FROM sesiones WHERE token = ?', [$token]), 'la sesión del admin se cerró');
        // El cierre queda a nombre del admin real, no de la espiada.
        $this->assertNotNull(Database::fetchOne(
            "SELECT 1 FROM audit_log WHERE accion = 'auth.logout' AND usuario_id = ?",
            [$this->adminId]
        ));
    }

    public function testEnModoEspiaLasDemasEscriturasSiguenBloqueadas(): void
    {
        $token = $this->login('11111111-1');
        (new ModoEspiaService())->activar((new UsuarioService())->buscarPorId($this->adminId), $token, $this->anaId);

        $resp = $this->pedir(Kernel::construirRouter(), 'POST', '/api/disponibilidad/avisar', $token);
        $this->assertSame(403, $resp->status);
        $this->assertSame('MODO_ESPIA_SOLO_LECTURA', json_decode($resp->cuerpo, true)['error']['codigo']);
    }

    public function testCadaRequestRenuevaLaCookieDeSesion(): void
    {
        $token = $this->login('11111111-1');
        $req = new Request(metodo: 'GET', path: '/api/test', cookies: [AuthService::SESSION_COOKIE => $token]);

        $resp = (new AuthCheck())->handle($req, fn() => Response::ok([]));

        $cookie = $this->cookieDeSesion($resp);
        $this->assertNotNull($cookie, 'la respuesta vuelve a emitir la cookie');
        $this->assertSame($token, $cookie['valor']);
        $this->assertGreaterThan(time() + 7 * 3600, $cookie['opciones']['expires']);
        $this->assertTrue($cookie['opciones']['httponly']);
    }

    public function testElLogoutNoReEmiteLaCookie(): void
    {
        $token = $this->login('11111111-1');
        $resp = $this->pedir(Kernel::construirRouter(), 'POST', '/api/auth/logout', $token);

        $cookies = array_values(array_filter($resp->cookies(), fn(array $c): bool => $c['nombre'] === AuthService::SESSION_COOKIE));
        $this->assertCount(1, $cookies);
        $this->assertSame('', $cookies[0]['valor'], 'solo la cookie que la borra');
    }

    // --- Home de supervisora ---------------------------------------------------------------

    public function testElEstadoEnRiesgoSoloLoVeQuienRecibeAlertasPredictivas(): void
    {
        [$recepcionId] = TestDatabase::crearUsuario('55555555-5', 'Rita', 'Recepción');
        $hoy = date('Y-m-d');
        Database::execute("INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES ('mañana', '00:00', '23:59')");
        Database::execute('INSERT INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)', [$this->anaId, Database::lastInsertId(), $hoy]);
        (new AlertasService())->levantar(
            AlertaActiva::TIPO_TRABAJADOR_EN_RIESGO,
            'Ana podría no alcanzar',
            'Le quedan piezas.',
            ['usuario_id' => $this->anaId],
            null,
            "trabajador:{$this->anaId}:fecha:{$hoy}",
        );

        $this->assertSame('en_riesgo', $this->estadoEnHomeSupervisora($this->supId));
        $this->assertNotSame('en_riesgo', $this->estadoEnHomeSupervisora($recepcionId));
    }

    // --- Helpers ---------------------------------------------------------------------------

    private function rolId(string $nombre): int
    {
        return (int) Database::fetchOne('SELECT id FROM roles WHERE nombre = ?', [$nombre])['id'];
    }

    private function login(string $rut): string
    {
        $pwd = $rut === '11111111-1' ? $this->adminPwd : 'Abc12345';
        return (new AuthService())->login($rut, $pwd)['token'];
    }

    /** @param array<string, mixed> $cuerpo */
    private function pedir(Router $router, string $metodo, string $path, string $token, array $cuerpo = []): Response
    {
        return $router->despachar(new Request(
            metodo: $metodo, path: $path, cuerpo: $cuerpo, cookies: [AuthService::SESSION_COOKIE => $token]
        ));
    }

    /** @return array{nombre:string, valor:string, opciones:array<string, mixed>}|null */
    private function cookieDeSesion(Response $resp): ?array
    {
        foreach ($resp->cookies() as $c) {
            if ($c['nombre'] === AuthService::SESSION_COOKIE) {
                return $c;
            }
        }
        return null;
    }

    private function estadoEnHomeSupervisora(int $quienMira): string
    {
        $req = new Request(metodo: 'GET', path: '/api/home/supervisora');
        $req->usuario = (new UsuarioService())->buscarPorId($quienMira);
        $equipo = json_decode((new HomeController())->supervisora($req)->cuerpo, true)['data']['equipo'];
        $ana = array_values(array_filter($equipo, fn(array $t): bool => $t['usuario']['id'] === $this->anaId));
        $this->assertCount(1, $ana);
        return $ana[0]['estado'];
    }
}
