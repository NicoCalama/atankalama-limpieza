<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\EsquemaService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * scripts/migrate-add-supervisores-aviso-mantencion.php (v7.22) sobre una base como la de producción antes
 * de la v7.22: crea usuarios_supervisores, da tickets.aviso_mantencion al rol Mantenimiento y precarga a las
 * supervisoras como supervisoras de cada persona de Aseo activa. Re-correrla no cambia nada. Al final
 * /api/health queda ok.
 */
final class MigracionSupervisoresV722Test extends TestCase
{
    private int $ana;
    private int $berta;
    private int $sofia;
    private int $carla;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO roles (nombre, descripcion, es_sistema) VALUES ('Mantenimiento', 'Mantención', 0)");
        [$this->ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');
        [$this->berta] = TestDatabase::crearUsuario('22222222-2', 'Berta', 'Aseo');
        TestDatabase::crearUsuario('33333333-3', 'Cecilia de baja', 'Aseo', activo: false);
        TestDatabase::crearUsuario('44444444-4', 'Lucy Apoyo', 'Apoyo');
        [$this->sofia] = TestDatabase::crearUsuario('55555555-5', 'Sofía', 'Supervisora');
        [$this->carla] = TestDatabase::crearUsuario('66666666-6', 'Carla', 'Supervisora');
        TestDatabase::crearUsuario('77777777-7', 'Inés de baja', 'Supervisora', activo: false);

        // Simula la base antes de la v7.22: sin la tabla y sin los dos permisos.
        Database::pdo()->exec('DROP TABLE usuarios_supervisores');
        Database::execute("DELETE FROM rol_permisos WHERE permiso_codigo IN ('tickets.aviso_mantencion', 'usuarios.ser_supervisor')");
        Database::execute("DELETE FROM permisos WHERE codigo IN ('tickets.aviso_mantencion', 'usuarios.ser_supervisor')");
        EsquemaService::limpiarCache();
    }

    private function correrMigracion(): string
    {
        ob_start();
        try {
            include dirname(__DIR__, 2) . '/scripts/migrate-add-supervisores-aviso-mantencion.php';
        } finally {
            $salida = (string) ob_get_clean();
        }
        EsquemaService::limpiarCache();
        return $salida;
    }

    /** @return list<string> «persona → supervisora» */
    private function pares(): array
    {
        return array_map(
            static fn(array $f): string => $f['persona'] . ' → ' . $f['supervisora'],
            Database::fetchAll(
                'SELECT u.nombre AS persona, s.nombre AS supervisora
                   FROM usuarios_supervisores us
                   JOIN usuarios u ON u.id = us.usuario_id
                   JOIN usuarios s ON s.id = us.supervisor_id
                  ORDER BY u.nombre, s.nombre'
            )
        );
    }

    public function testMigraPrecargaYQuedaAlDia(): void
    {
        $antes = (new EsquemaService())->faltantes(true);
        $this->assertContains('usuarios_supervisores', $antes['tablas']);
        $this->assertContains('tickets.aviso_mantencion', $antes['permisos']);
        $this->assertContains('usuarios.ser_supervisor', $antes['permisos']);

        $salida = $this->correrMigracion();
        $this->assertStringContainsString('Migración completa', $salida);
        $this->assertStringContainsString('2 persona(s) de Aseo, 4 par(es)', $salida);
        $this->assertSame(
            ['Ana → Carla', 'Ana → Sofía', 'Berta → Carla', 'Berta → Sofía'],
            $this->pares(),
            'solo Aseo activo, solo supervisoras activas'
        );
        $rolesCon = static fn(string $permiso): array => array_column(Database::fetchAll(
            'SELECT r.nombre FROM rol_permisos rp JOIN roles r ON r.id = rp.rol_id
              WHERE rp.permiso_codigo = ? ORDER BY r.nombre',
            [$permiso]
        ), 'nombre');
        $this->assertSame(['Mantenimiento'], $rolesCon('tickets.aviso_mantencion'));
        $this->assertSame(['Admin', 'Supervisora'], $rolesCon('usuarios.ser_supervisor'), 'las jefaturas de hoy');

        $esquema = (new EsquemaService())->faltantes(true);
        $this->assertTrue($esquema['ok'], 'faltan: ' . json_encode($esquema));
    }

    public function testCorrerlaDeNuevoNoVuelveAAgregarLoQuitadoAMano(): void
    {
        $this->correrMigracion();
        Database::execute('DELETE FROM usuarios_supervisores WHERE usuario_id = ? AND supervisor_id = ?', [$this->ana, $this->carla]);

        $salida = $this->correrMigracion();
        $this->assertStringContainsString('ya tenía tickets.aviso_mantencion', $salida);
        $this->assertStringContainsString('no se precarga nada', $salida);
        $this->assertSame(['Ana → Sofía', 'Berta → Carla', 'Berta → Sofía'], $this->pares());
        $this->assertSame(1, (int) Database::fetchColumn("SELECT COUNT(*) FROM rol_permisos WHERE permiso_codigo = 'tickets.aviso_mantencion'"));
    }

    public function testSinRolMantenimientoAvisaQueSeDaDesdeAjustes(): void
    {
        Database::execute("DELETE FROM roles WHERE nombre = 'Mantenimiento'");
        $salida = $this->correrMigracion();
        $this->assertStringContainsString('No existe el rol Mantenimiento', $salida);
        $this->assertSame(0, (int) Database::fetchColumn("SELECT COUNT(*) FROM rol_permisos WHERE permiso_codigo = 'tickets.aviso_mantencion'"));
    }
}
