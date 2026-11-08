<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\CambioTareaService;
use Atankalama\Limpieza\Services\EsquemaService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * scripts/migrate-add-cambio-tarea.php (v7.16) sobre una base como la de prod antes de la v7.16: amplía el
 * CHECK de listas_opciones.lista sin perder filas, crea horas_otras_areas, siembra la lista «area_apoyo» y
 * da el permiso a quien reparte piezas. Correrla de nuevo no cambia nada. Al final /api/health queda ok.
 */
final class MigracionCambioTareaV716Test extends TestCase
{
    private const DESC_LISTAS_V715 = 'Editar las listas de selección: motivos de rechazo, de «No se pudo inspeccionar», de saltar y de reasignar una habitación, del NO de Recepción, mensajes a la mucama y tipos y respuestas de tickets (Ajustes → Checklists y listas)';

    private int $ana;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        [$this->ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');

        // Simula la base antes de la v7.16: sin la tabla, sin la lista, sin el permiso y con el CHECK viejo.
        $pdo = Database::pdo();
        $pdo->exec('DROP TABLE horas_otras_areas');
        Database::execute("DELETE FROM listas_opciones WHERE lista = 'area_apoyo'");
        Database::execute("DELETE FROM rol_permisos WHERE permiso_codigo = 'cambio_tarea.registrar'");
        Database::execute("DELETE FROM permisos WHERE codigo = 'cambio_tarea.registrar'");
        Database::execute("UPDATE permisos SET descripcion = ? WHERE codigo = 'listas.editar'", [self::DESC_LISTAS_V715]);
        $sql = (string) Database::fetchColumn("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'listas_opciones'");
        $this->assertStringContainsString("'sin_inspeccion', 'area_apoyo'", $sql, 'el schema trae la lista de la v7.16');
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec(str_replace(["CREATE TABLE listas_opciones", "'sin_inspeccion', 'area_apoyo'"], ["CREATE TABLE listas_opciones_v715", "'sin_inspeccion'"], $sql));
        $pdo->exec('INSERT INTO listas_opciones_v715 SELECT * FROM listas_opciones');
        $pdo->exec('DROP TABLE listas_opciones');
        $pdo->exec('ALTER TABLE listas_opciones_v715 RENAME TO listas_opciones');
        $pdo->exec('CREATE INDEX idx_listas_opciones_lista ON listas_opciones(lista, orden)');
        $pdo->exec('PRAGMA foreign_keys = ON');
        EsquemaService::limpiarCache();
    }

    private function correrMigracion(): string
    {
        ob_start();
        try {
            include dirname(__DIR__, 2) . '/scripts/migrate-add-cambio-tarea.php';
        } finally {
            $salida = (string) ob_get_clean();
        }
        EsquemaService::limpiarCache();
        return $salida;
    }

    public function testMigraSinPerderFilasYQuedaAlDia(): void
    {
        $antes = (new EsquemaService())->faltantes(true);
        $this->assertContains('horas_otras_areas', $antes['tablas']);
        $this->assertContains("listas_opciones.lista: 'area_apoyo'", $antes['checks']);
        $this->assertContains('cambio_tarea.registrar', $antes['permisos']);
        $rechazosAntes = (int) Database::fetchColumn("SELECT COUNT(*) FROM listas_opciones WHERE lista = 'rechazo'");

        $salida = $this->correrMigracion();
        $this->assertStringContainsString('Migración completa', $salida);

        $this->assertSame($rechazosAntes, (int) Database::fetchColumn("SELECT COUNT(*) FROM listas_opciones WHERE lista = 'rechazo'"), 'las otras listas siguen igual');
        $this->assertSame(
            ['Recepción', 'Lavandería', 'Mantención', 'Cocina', 'Bodega'],
            array_column(Database::fetchAll("SELECT nombre FROM listas_opciones WHERE lista = 'area_apoyo' ORDER BY orden"), 'nombre')
        );
        $this->assertSame(
            ['Admin', 'Supervisora'],
            array_column(Database::fetchAll(
                "SELECT r.nombre FROM rol_permisos rp JOIN roles r ON r.id = rp.rol_id
                  WHERE rp.permiso_codigo = 'cambio_tarea.registrar' ORDER BY r.nombre"
            ), 'nombre'),
            'los roles que hoy reparten piezas'
        );
        $this->assertStringContainsString('áreas de «Cambio Tarea»', (string) Database::fetchColumn("SELECT descripcion FROM permisos WHERE codigo = 'listas.editar'"));
        $this->assertSame(0, count(Database::fetchAll('PRAGMA foreign_key_check')), 'las FK siguen sanas');

        $esquema = (new EsquemaService())->faltantes(true);
        $this->assertTrue($esquema['ok'], 'faltan: ' . json_encode($esquema));

        // Y la tabla nueva funciona.
        [$sup] = TestDatabase::crearUsuario('99999999-9', 'Sofía', 'Supervisora');
        $r = (new CambioTareaService())->guardar($this->ana, date('Y-m-d'), [
            ['area_id' => TestDatabase::opcionId('area_apoyo', 'Recepción'), 'minutos' => 60],
        ], $sup);
        $this->assertSame('Recepción', $r[0]['nombre']);
    }

    public function testCorrerlaDosVecesNoCambiaNada(): void
    {
        $this->correrMigracion();
        // Alguien desactiva una opción entre medio: la segunda corrida no la vuelve a sembrar.
        Database::execute("UPDATE listas_opciones SET activo = 0 WHERE lista = 'area_apoyo' AND nombre = 'Bodega'");

        $salida = $this->correrMigracion();
        $this->assertStringContainsString('ya acepta todas las listas', $salida);
        $this->assertStringContainsString('ya tenía opciones', $salida);
        $this->assertSame(5, (int) Database::fetchColumn("SELECT COUNT(*) FROM listas_opciones WHERE lista = 'area_apoyo'"));
        $this->assertSame(0, (int) Database::fetchColumn("SELECT activo FROM listas_opciones WHERE nombre = 'Bodega'"));
        $this->assertSame(2, (int) Database::fetchColumn("SELECT COUNT(*) FROM rol_permisos WHERE permiso_codigo = 'cambio_tarea.registrar'"));
    }
}
