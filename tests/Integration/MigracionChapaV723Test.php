<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\EsquemaService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * scripts/migrate-add-chapa-hora-tarde.php (v7.23) sobre una base como la de producción antes de la v7.23: agrega
 * habitaciones.nochero_modo (las marcas que ya había quedan Día/Noche) y da habitaciones.configurar_hora_tarde al
 * Admin. Re-correrla no cambia nada. Al final /api/health queda ok.
 */
final class MigracionChapaV723Test extends TestCase
{
    private int $habMarcada;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', 'Atankalama')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado, es_nochero, nochero_hasta) VALUES (1, '101', 1, 'sucia', 1, '2026-11-20')"
        );
        $this->habMarcada = Database::lastInsertId();
        TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');

        // Simula la base antes de la v7.23: sin la columna y sin el permiso.
        Database::pdo()->exec('ALTER TABLE habitaciones DROP COLUMN nochero_modo');
        Database::execute("DELETE FROM rol_permisos WHERE permiso_codigo = 'habitaciones.configurar_hora_tarde'");
        Database::execute("DELETE FROM permisos WHERE codigo = 'habitaciones.configurar_hora_tarde'");
        EsquemaService::limpiarCache();
    }

    private function correrMigracion(): string
    {
        ob_start();
        try {
            include dirname(__DIR__, 2) . '/scripts/migrate-add-chapa-hora-tarde.php';
        } finally {
            $salida = (string) ob_get_clean();
        }
        EsquemaService::limpiarCache();
        return $salida;
    }

    public function testMigraYLasMarcasQuedanDiaNoche(): void
    {
        $antes = (new EsquemaService())->faltantes(true);
        $this->assertContains('habitaciones.nochero_modo', $antes['columnas']);
        $this->assertContains('habitaciones.configurar_hora_tarde', $antes['permisos']);

        $salida = $this->correrMigracion();
        $this->assertStringContainsString('Migración completa', $salida);
        $this->assertSame('dia_noche', Database::fetchColumn('SELECT nochero_modo FROM habitaciones WHERE id = ?', [$this->habMarcada]));
        $this->assertSame(
            ['Admin'],
            array_column(Database::fetchAll(
                "SELECT r.nombre FROM rol_permisos rp JOIN roles r ON r.id = rp.rol_id
                  WHERE rp.permiso_codigo = 'habitaciones.configurar_hora_tarde'"
            ), 'nombre')
        );

        $esquema = (new EsquemaService())->faltantes(true);
        $this->assertTrue($esquema['ok'], 'faltan: ' . json_encode($esquema));

        // El CHECK de la columna nueva rechaza un modo inventado.
        $this->expectException(\PDOException::class);
        Database::execute("UPDATE habitaciones SET nochero_modo = 'tarde' WHERE id = ?", [$this->habMarcada]);
    }

    public function testCorrerlaDosVecesNoCambiaNada(): void
    {
        $this->correrMigracion();
        $salida = $this->correrMigracion();
        $this->assertStringContainsString('ya existe', $salida);
        $this->assertStringContainsString('ya tenía habitaciones.configurar_hora_tarde', $salida);
        $this->assertSame(1, (int) Database::fetchColumn("SELECT COUNT(*) FROM rol_permisos WHERE permiso_codigo = 'habitaciones.configurar_hora_tarde'"));
    }
}
