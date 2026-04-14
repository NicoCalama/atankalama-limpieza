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
}
