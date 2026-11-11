<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\EsquemaService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * scripts/migrate-add-areas-beta.php (v7.27) sobre una base como la de producción antes de la v7.27. Toda área
 * existente queda alfa, el catálogo parte vacío y /api/health queda ok. Re-correrla no cambia nada.
 */
final class MigracionAreasBetaV727Test extends TestCase
{
    private const PERMISOS = ['espacios.agregar_extra', 'espacios.editar_m2', 'tareas.crear', 'tareas.editar', 'tareas.ver'];

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Área común')");
        Database::execute("INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado, es_espacio_comun) VALUES (1, 'Piscina', 1, 'aprobada', 1)");

        // Simula la base antes de la v7.27.
        $pdo = Database::pdo();
        $pdo->exec('DROP TABLE extras_areas_comunes');
        $pdo->exec('DROP TABLE espacio_tareas');
        $pdo->exec('DROP TABLE tareas_catalogo');
        $pdo->exec('ALTER TABLE habitaciones DROP COLUMN area_beta');
        $marcas = implode(',', array_fill(0, count(self::PERMISOS), '?'));
        Database::execute("DELETE FROM rol_permisos WHERE permiso_codigo IN ({$marcas})", self::PERMISOS);
        Database::execute("DELETE FROM permisos WHERE codigo IN ({$marcas})", self::PERMISOS);
        EsquemaService::limpiarCache();
    }

    private function correrMigracion(): string
    {
        ob_start();
        try {
            include dirname(__DIR__, 2) . '/scripts/migrate-add-areas-beta.php';
        } finally {
            $salida = (string) ob_get_clean();
        }
        EsquemaService::limpiarCache();
        return $salida;
    }

    /** @return list<string> */
    private function rolesCon(string $permiso): array
    {
        return array_map('strval', array_column(Database::fetchAll(
            'SELECT r.nombre FROM rol_permisos rp JOIN roles r ON r.id = rp.rol_id WHERE rp.permiso_codigo = ? ORDER BY r.nombre',
            [$permiso]
        ), 'nombre'));
    }

    public function testMigraYQuedaAlDia(): void
    {
        $antes = (new EsquemaService())->faltantes(true);
        $this->assertContains('tareas_catalogo', $antes['tablas']);
        $this->assertContains('extras_areas_comunes', $antes['tablas']);
        $this->assertContains('tareas.editar', $antes['permisos']);

        $this->assertStringContainsString('Migración completa', $this->correrMigracion());

        $this->assertSame(0, (int) Database::fetchColumn('SELECT area_beta FROM habitaciones WHERE numero = ?', ['Piscina']), 'las áreas existentes quedan alfa');
        $this->assertSame(0, (int) Database::fetchColumn('SELECT COUNT(*) FROM tareas_catalogo'), 'catálogo limpio');
        $this->assertSame(['Admin', 'Supervisora'], $this->rolesCon('tareas.crear'));
        $this->assertSame(['Admin', 'Supervisora'], $this->rolesCon('espacios.agregar_extra'));
        $this->assertSame(['Admin', 'Supervisora'], $this->rolesCon('tareas.ver'));
        $this->assertSame(['Admin'], $this->rolesCon('tareas.editar'), 'cambiar valores: solo el admin');
        $this->assertSame(['Admin'], $this->rolesCon('espacios.editar_m2'), 'cambiar m² ya guardados: solo el admin');

        $esquema = (new EsquemaService())->faltantes(true);
        $this->assertTrue($esquema['ok'], 'faltan: ' . json_encode($esquema));
    }

    public function testCorrerlaDeNuevoNoCambiaNada(): void
    {
        $this->correrMigracion();
        $salida = $this->correrMigracion();

        $this->assertStringContainsString('area_beta ya existía', $salida);
        $this->assertSame(1, (int) Database::fetchColumn("SELECT COUNT(*) FROM rol_permisos WHERE permiso_codigo = 'tareas.editar'"));
    }
}
