<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\EsquemaService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * scripts/migrate-add-revision-entrega.php sobre una base que todavía no tiene la v6.18 (la de prod):
 * crea las dos tablas, los tres permisos, los grants por rol y los motivos iniciales, y correrla de
 * nuevo no duplica nada. Al final el verificador de esquema (lo mismo que mira /api/health) queda ok.
 */
final class MigracionRevisionEntregaTest extends TestCase
{
    private const PERMISOS = "('revision_entrega.registrar', 'revision_entrega.configurar', 'habitaciones.gestionar_edificios')";

    protected function setUp(): void
    {
        TestDatabase::recrear();
        // Simula la base de prod antes de la v6.18.
        Database::pdo()->exec('DROP TABLE revisiones_entrega');
        Database::pdo()->exec('DROP TABLE motivos_revision_entrega');
        Database::execute('DELETE FROM rol_permisos WHERE permiso_codigo IN ' . self::PERMISOS);
        Database::execute('DELETE FROM permisos WHERE codigo IN ' . self::PERMISOS);
        EsquemaService::limpiarCache();
    }

    private function correrMigracion(): string
    {
        ob_start();
        try {
            include dirname(__DIR__, 2) . '/scripts/migrate-add-revision-entrega.php';
        } finally {
            $salida = (string) ob_get_clean();
        }
        return $salida;
    }

    /** @return list<string> */
    private function rolesCon(string $permiso): array
    {
        return array_column(Database::fetchAll(
            'SELECT r.nombre FROM rol_permisos rp JOIN roles r ON r.id = rp.rol_id WHERE rp.permiso_codigo = ? ORDER BY r.nombre',
            [$permiso]
        ), 'nombre');
    }

    public function testCreaTablasPermisosGrantsYMotivos(): void
    {
        $this->assertFalse((new EsquemaService())->faltantes(true)['ok'], 'antes de migrar faltan cosas');

        $salida = $this->correrMigracion();
        $this->assertStringContainsString('Migración completa', $salida);

        $tablas = array_column(Database::fetchAll(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('motivos_revision_entrega', 'revisiones_entrega') ORDER BY name"
        ), 'name');
        $this->assertSame(['motivos_revision_entrega', 'revisiones_entrega'], $tablas);

        $indices = array_column(Database::fetchAll(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND name LIKE 'idx_revisiones_entrega_%' ORDER BY name"
        ), 'name');
        $this->assertSame(
            ['idx_revisiones_entrega_auditoria', 'idx_revisiones_entrega_created', 'idx_revisiones_entrega_hab_fecha', 'idx_revisiones_entrega_idem'],
            $indices
        );
        $columnas = array_column(Database::fetchAll('PRAGMA table_info(revisiones_entrega)'), 'name');
        foreach (['estado_pieza', 'ejecucion_id', 'auditoria_id', 'paso_a_sucia', 'idempotency_key'] as $col) {
            $this->assertContains($col, $columnas);
        }

        $this->assertSame(['Admin', 'Recepción'], $this->rolesCon('revision_entrega.registrar'));
        $this->assertSame(['Admin', 'Supervisora'], $this->rolesCon('revision_entrega.configurar'));
        $this->assertSame(
            ['Admin', 'Supervisora'],
            $this->rolesCon('habitaciones.gestionar_edificios'),
            'lo conserva todo rol que tenía ver_todas, menos Recepción'
        );

        $this->assertSame(8, (int) Database::fetchColumn('SELECT COUNT(*) FROM motivos_revision_entrega'));

        EsquemaService::limpiarCache();
        $this->assertTrue((new EsquemaService())->faltantes(true)['ok'], 'el verificador de esquema queda sin faltantes');
    }

    public function testCorrerlaDosVecesNoDuplicaNada(): void
    {
        $this->correrMigracion();
        $segunda = $this->correrMigracion();

        $this->assertStringContainsString('ya existía', $segunda);
        $this->assertStringContainsString('ya tenía datos', $segunda);
        $this->assertSame(3, (int) Database::fetchColumn('SELECT COUNT(*) FROM permisos WHERE codigo IN ' . self::PERMISOS));
        $this->assertSame(['Admin', 'Recepción'], $this->rolesCon('revision_entrega.registrar'));
        $this->assertSame(['Admin', 'Supervisora'], $this->rolesCon('revision_entrega.configurar'));
        $this->assertSame(['Admin', 'Supervisora'], $this->rolesCon('habitaciones.gestionar_edificios'));
        $this->assertSame(8, (int) Database::fetchColumn('SELECT COUNT(*) FROM motivos_revision_entrega'));
    }

    public function testNoResiembraMotivosSiYaHayAlguno(): void
    {
        $this->correrMigracion();
        Database::execute('DELETE FROM motivos_revision_entrega');
        Database::execute("INSERT INTO motivos_revision_entrega (nombre) VALUES ('Solo este')");

        $this->correrMigracion();

        $this->assertSame(['Solo este'], array_column(Database::fetchAll('SELECT nombre FROM motivos_revision_entrega'), 'nombre'));
    }
}
