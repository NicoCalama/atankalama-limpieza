<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\EsquemaService;
use Atankalama\Limpieza\Services\HorasExtrasService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * scripts/migrate-add-horas-extras.php (v7.17) sobre una base como la de prod antes de la v7.17: crea
 * horas_extras y da el permiso a quien edita el corte del bono. Correrla de nuevo no cambia nada. Al final
 * /api/health queda ok.
 */
final class MigracionHorasExtrasV717Test extends TestCase
{
    private int $ana;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        [$this->ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');

        // Simula la base antes de la v7.17: sin la tabla y sin el permiso.
        Database::pdo()->exec('DROP TABLE horas_extras');
        Database::execute("DELETE FROM rol_permisos WHERE permiso_codigo = 'horas_extras.registrar'");
        Database::execute("DELETE FROM permisos WHERE codigo = 'horas_extras.registrar'");
        EsquemaService::limpiarCache();
    }

    private function correrMigracion(): string
    {
        ob_start();
        try {
            include dirname(__DIR__, 2) . '/scripts/migrate-add-horas-extras.php';
        } finally {
            $salida = (string) ob_get_clean();
        }
        EsquemaService::limpiarCache();
        return $salida;
    }

    public function testMigraYQuedaAlDia(): void
    {
        $antes = (new EsquemaService())->faltantes(true);
        $this->assertContains('horas_extras', $antes['tablas']);
        $this->assertContains('horas_extras.registrar', $antes['permisos']);

        $salida = $this->correrMigracion();
        $this->assertStringContainsString('Migración completa', $salida);
        $this->assertSame(
            ['Admin'],
            array_column(Database::fetchAll(
                "SELECT r.nombre FROM rol_permisos rp JOIN roles r ON r.id = rp.rol_id
                  WHERE rp.permiso_codigo = 'horas_extras.registrar' ORDER BY r.nombre"
            ), 'nombre'),
            'los roles que hoy editan el corte'
        );

        $esquema = (new EsquemaService())->faltantes(true);
        $this->assertTrue($esquema['ok'], 'faltan: ' . json_encode($esquema));

        // Y la tabla nueva funciona.
        [$admin] = TestDatabase::crearUsuario('99999999-9', 'Admin', 'Admin');
        $this->assertSame(2.5, (new HorasExtrasService(hoyFijo: '2026-11-20'))->guardar($this->ana, '2026-11', 2.5, $admin));
    }

    public function testCorrerlaDosVecesNoCambiaNada(): void
    {
        $this->correrMigracion();
        $salida = $this->correrMigracion();
        $this->assertStringContainsString('ya tenía horas_extras.registrar', $salida);
        $this->assertSame(1, (int) Database::fetchColumn("SELECT COUNT(*) FROM rol_permisos WHERE permiso_codigo = 'horas_extras.registrar'"));
        $this->assertSame(1, (int) Database::fetchColumn("SELECT COUNT(*) FROM permisos WHERE codigo = 'horas_extras.registrar'"));
    }
}
