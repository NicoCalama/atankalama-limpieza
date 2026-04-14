<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Models\Usuario;
use PHPUnit\Framework\TestCase;

final class UsuarioTest extends TestCase
{
    private function crear(array $permisos = [], array $roles = []): Usuario
    {
        return new Usuario(
            id: 1,
            rut: '12345678-9',
            nombre: 'Test',
            email: null,
            activo: true,
            requiereCambioPwd: false,
            hotelDefault: 'ambos',
            temaPreferido: 'auto',
            permisos: $permisos,
            roles: $roles,
        );
    }

    public function testTienePermisoDetectaPresente(): void
    {
        $u = $this->crear(['habitaciones.ver_todas']);
        $this->assertTrue($u->tienePermiso('habitaciones.ver_todas'));
    }

    public function testTienePermisoRechazaAusente(): void
    {
        $u = $this->crear(['habitaciones.ver_todas']);
        $this->assertFalse($u->tienePermiso('usuarios.crear'));
    }

    public function testTieneAlgunPermisoEsOr(): void
    {
        $u = $this->crear(['auditoria.aprobar']);
        $this->assertTrue($u->tieneAlgunPermiso(['auditoria.aprobar', 'auditoria.rechazar']));
        $this->assertFalse($u->tieneAlgunPermiso(['x.y', 'z.w']));
    }

    public function testTieneRol(): void
    {
        $u = $this->crear([], ['Supervisora', 'Admin']);
        $this->assertTrue($u->tieneRol('Admin'));
        $this->assertFalse($u->tieneRol('Trabajador'));
    }

    public function testToArrayPublicoNoIncluyePasswordHash(): void
    {
        $u = $this->crear(['x']);
        $array = $u->toArrayPublico();
        $this->assertArrayNotHasKey('password_hash', $array);
        $this->assertArrayNotHasKey('permisos', $array);
        $this->assertSame('12345678-9', $array['rut']);
    }
}
