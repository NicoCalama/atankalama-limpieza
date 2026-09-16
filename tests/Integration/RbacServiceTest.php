<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\RbacException;
use Atankalama\Limpieza\Services\RbacService;
use Atankalama\Limpieza\Services\UsuarioService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class RbacServiceTest extends TestCase
{
    private RbacService $rbac;
    private int $adminId;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        $this->rbac = new RbacService();
        [$this->adminId] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');
    }

    public function testListarRolesIncluyeLos4DeSistema(): void
    {
        $roles = $this->rbac->listarRoles();
        $nombres = array_column($roles, 'nombre');
        $this->assertContains('Trabajador', $nombres);
        $this->assertContains('Supervisora', $nombres);
        $this->assertContains('Recepción', $nombres);
        $this->assertContains('Admin', $nombres);
    }

    public function testListarPermisosDevuelveCatalogo(): void
    {
        $permisos = $this->rbac->listarPermisos();
        $this->assertGreaterThan(40, count($permisos));
        $codigos = array_column($permisos, 'codigo');
        $this->assertContains('ajustes.acceder', $codigos);
    }

    public function testCrearRolPersonalizadoConPermisos(): void
    {
        $id = $this->rbac->crearRol('Auditor Externo', 'Rol custom', ['auditoria.ver_bandeja', 'auditoria.aprobar'], $this->adminId);
        $rol = $this->rbac->obtenerRol($id);
        $this->assertSame('Auditor Externo', $rol['nombre']);
        $this->assertSame(0, $rol['es_sistema']);
        $this->assertContains('auditoria.aprobar', $rol['permisos']);
    }

    public function testCrearRolRechazaNombreDuplicado(): void
    {
        $this->expectException(RbacException::class);
        $this->rbac->crearRol('Admin', null, [], $this->adminId);
    }

    public function testCrearRolRechazaPermisoInexistente(): void
    {
        try {
            $this->rbac->crearRol('Test', null, ['no.existe'], $this->adminId);
            $this->fail('Debía lanzar');
        } catch (RbacException $e) {
            $this->assertSame('PERMISO_INEXISTENTE', $e->codigo);
        }
    }

    public function testEliminarRolDeSistemaFalla(): void
    {
        $admin = Database::fetchOne('SELECT id FROM roles WHERE nombre = ?', ['Admin']);
        try {
            $this->rbac->eliminarRol((int) $admin['id'], $this->adminId);
            $this->fail('Debía lanzar');
        } catch (RbacException $e) {
            $this->assertSame('ROL_DE_SISTEMA', $e->codigo);
        }
    }

    public function testActualizarRolReemplazaPermisos(): void
    {
        $id = $this->rbac->crearRol('Custom', null, ['auditoria.aprobar'], $this->adminId);
        $this->rbac->actualizarRol($id, null, null, ['auditoria.rechazar', 'tickets.crear'], $this->adminId);
        $rol = $this->rbac->obtenerRol($id);
        $this->assertNotContains('auditoria.aprobar', $rol['permisos']);
        $this->assertContains('auditoria.rechazar', $rol['permisos']);
        $this->assertContains('tickets.crear', $rol['permisos']);
    }

    public function testAsignarYQuitarRolAUsuario(): void
    {
        [$usuarioId] = TestDatabase::crearUsuario('22222222-2', 'Test', 'Trabajador');
        $recepcion = Database::fetchOne('SELECT id FROM roles WHERE nombre = ?', ['Recepción']);

        $this->rbac->asignarRolAUsuario($usuarioId, (int) $recepcion['id'], $this->adminId);
        $usuario = (new UsuarioService())->buscarPorId($usuarioId);
        $this->assertContains('Recepción', $usuario->roles);
        $this->assertContains('Trabajador', $usuario->roles);

        $this->rbac->quitarRolAUsuario($usuarioId, (int) $recepcion['id'], $this->adminId);
        $usuario = (new UsuarioService())->buscarPorId($usuarioId);
        $this->assertNotContains('Recepción', $usuario->roles);
    }

    public function testPermisosEfectivosSonUnionDeRoles(): void
    {
        [$usuarioId] = TestDatabase::crearUsuario('22222222-2', 'Test', 'Trabajador');
        $supervisora = Database::fetchOne('SELECT id FROM roles WHERE nombre = ?', ['Supervisora']);
        $this->rbac->asignarRolAUsuario($usuarioId, (int) $supervisora['id'], $this->adminId);

        $usuario = (new UsuarioService())->buscarPorId($usuarioId);
        $this->assertTrue($usuario->tienePermiso('habitaciones.marcar_completada'));
        $this->assertTrue($usuario->tienePermiso('auditoria.aprobar'));
    }

    // ─── Regla anti-bloqueo: siempre debe quedar ≥1 administrador activo ───────────

    public function testNoSePuedeQuitarElRolAlUltimoAdmin(): void
    {
        $adminRol = Database::fetchOne('SELECT id FROM roles WHERE nombre = ?', ['Admin']);
        try {
            $this->rbac->quitarRolAUsuario($this->adminId, (int) $adminRol['id'], $this->adminId);
            $this->fail('Debía lanzar');
        } catch (RbacException $e) {
            $this->assertSame('ULTIMO_ADMIN', $e->codigo);
            $this->assertSame(409, $e->httpStatus);
        }
        // Rollback: conserva el rol.
        $this->assertContains('Admin', (new UsuarioService())->buscarPorId($this->adminId)->roles);
    }

    public function testConDosAdminsSePuedeQuitarElRolAUno(): void
    {
        [$admin2] = TestDatabase::crearUsuario('22222222-2', 'Admin Dos', 'Admin');
        $adminRol = Database::fetchOne('SELECT id FROM roles WHERE nombre = ?', ['Admin']);
        $this->rbac->quitarRolAUsuario($admin2, (int) $adminRol['id'], $this->adminId);
        $this->assertNotContains('Admin', (new UsuarioService())->buscarPorId($admin2)->roles);
        $this->assertSame(1, $this->rbac->contarAdminsActivos());
    }

    public function testNoSePuedeVaciarElPermisoLlaveDelRolAdmin(): void
    {
        $adminRol = Database::fetchOne('SELECT id FROM roles WHERE nombre = ?', ['Admin']);
        try {
            // Reemplazar la matriz del rol Admin por un set SIN el permiso llave: degradaría
            // a todos sus admins de una vez (el vector más peligroso).
            $this->rbac->actualizarRol((int) $adminRol['id'], null, null, ['ajustes.acceder'], $this->adminId);
            $this->fail('Debía lanzar');
        } catch (RbacException $e) {
            $this->assertSame('ULTIMO_ADMIN', $e->codigo);
            $this->assertSame(409, $e->httpStatus);
        }
        // Rollback: el rol Admin conserva el permiso llave.
        $rol = $this->rbac->obtenerRol((int) $adminRol['id']);
        $this->assertContains(RbacService::PERMISO_ADMIN, $rol['permisos']);
    }

    public function testNoSePuedeBorrarElUltimoRolQueDaAdmin(): void
    {
        // Mover la capacidad admin a un rol custom y dejarlo como único portador de la llave.
        $custom = $this->rbac->crearRol('SuperCustom', null, [RbacService::PERMISO_ADMIN], $this->adminId);
        $adminRol = Database::fetchOne('SELECT id FROM roles WHERE nombre = ?', ['Admin']);
        $this->rbac->asignarRolAUsuario($this->adminId, $custom, $this->adminId);
        // Quitar el rol 'Admin' es OK: queda el custom (quitar un rol admin redundante se permite).
        $this->rbac->quitarRolAUsuario($this->adminId, (int) $adminRol['id'], $this->adminId);
        $this->assertSame(1, $this->rbac->contarAdminsActivos());
        // Borrar el custom (único que otorga la llave ahora) → 409.
        try {
            $this->rbac->eliminarRol($custom, $this->adminId);
            $this->fail('Debía lanzar');
        } catch (RbacException $e) {
            $this->assertSame('ULTIMO_ADMIN', $e->codigo);
        }
        $this->assertSame(1, $this->rbac->contarAdminsActivos());
    }

    public function testAdminInactivoNoCuentaComoAdminActivo(): void
    {
        // Segundo admin pero INACTIVO: no debe contar como admin activo.
        TestDatabase::crearUsuario('22222222-2', 'Admin Dos', 'Admin', 'Abc12345', false, false);
        $this->assertSame(1, $this->rbac->contarAdminsActivos());
    }
}
