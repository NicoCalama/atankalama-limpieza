<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\EsquemaService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * scripts/migrate-add-sin-inspeccion.php (v7.11) sobre una base como la de prod antes de la v7.11: amplía los
 * CHECK de auditorias.veredicto y listas_opciones.lista sin perder filas, siembra la lista «sin_inspeccion» y
 * da el permiso a quien hoy aprueba. Correrla de nuevo no cambia nada. Al final /api/health queda ok.
 */
final class MigracionSinInspeccionV711Test extends TestCase
{
    private int $auditoriaId;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();
        [$ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        Database::execute("INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (1, '101', 1, 'aprobada')");
        Database::execute("INSERT INTO asignaciones (habitacion_id, usuario_id, fecha, orden_cola) VALUES (1, ?, '2026-10-24', 1)", [$ana]);
        Database::execute(
            "INSERT INTO ejecuciones_checklist (habitacion_id, usuario_id, asignacion_id, template_id, estado, timestamp_inicio, timestamp_fin)
             VALUES (1, ?, 1, (SELECT MIN(id) FROM checklists_template), 'auditada', '2026-10-24T13:00:00.000Z', '2026-10-24T13:30:00.000Z')",
            [$ana]
        );
        Database::execute(
            "INSERT INTO auditorias (ejecucion_id, habitacion_id, auditor_id, veredicto, comentario, motivo_id)
             VALUES (1, 1, ?, 'rechazado', 'Baño sucio', ?)",
            [$ana, TestDatabase::opcionId('rechazo', 'Baño sucio')]
        );
        $this->auditoriaId = Database::lastInsertId();

        // Simula la base antes de la v7.11: sin la lista, sin el permiso y con los CHECK viejos.
        Database::execute("DELETE FROM listas_opciones WHERE lista = 'sin_inspeccion'");
        Database::execute("DELETE FROM rol_permisos WHERE permiso_codigo = 'auditoria.sin_inspeccion'");
        Database::execute("DELETE FROM permisos WHERE codigo = 'auditoria.sin_inspeccion'");
        $pdo = Database::pdo();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        foreach ([
            'auditorias' => ["'aprobado', 'aprobado_con_observacion', 'aprobado_automatico', 'rechazado', 'sin_inspeccion'", "'aprobado', 'aprobado_con_observacion', 'aprobado_automatico', 'rechazado'"],
            'listas_opciones' => ["'ticket_respuesta', 'sin_inspeccion'", "'ticket_respuesta'"],
        ] as $tabla => [$nuevo, $viejo]) {
            $sql = (string) Database::fetchColumn("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$tabla]);
            $this->assertStringContainsString($nuevo, $sql, "el schema de {$tabla} trae la lista de la v7.11");
            $pdo->exec(str_replace(["CREATE TABLE {$tabla}", $nuevo], ["CREATE TABLE {$tabla}_v710", $viejo], $sql));
            $pdo->exec("INSERT INTO {$tabla}_v710 SELECT * FROM {$tabla}");
            $pdo->exec("DROP TABLE {$tabla}");
            $pdo->exec("ALTER TABLE {$tabla}_v710 RENAME TO {$tabla}");
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
        EsquemaService::limpiarCache();
    }

    private function correrMigracion(): string
    {
        ob_start();
        try {
            include dirname(__DIR__, 2) . '/scripts/migrate-add-sin-inspeccion.php';
        } finally {
            $salida = (string) ob_get_clean();
        }
        EsquemaService::limpiarCache();
        return $salida;
    }

    public function testMigraSinPerderFilasYQuedaAlDia(): void
    {
        $antes = (new EsquemaService())->faltantes(true);
        $this->assertContains("auditorias.veredicto: 'sin_inspeccion'", $antes['checks']);
        $this->assertContains("listas_opciones.lista: 'sin_inspeccion'", $antes['checks']);

        $salida = $this->correrMigracion();
        $this->assertStringContainsString('Migración completa', $salida);

        $this->assertSame(
            ['veredicto' => 'rechazado', 'comentario' => 'Baño sucio'],
            Database::fetchOne('SELECT veredicto, comentario FROM auditorias WHERE id = ?', [$this->auditoriaId]),
            'la auditoría que ya estaba sigue igual'
        );
        $this->assertSame(
            ['Huésped en la pieza', 'Cartel No molestar'],
            array_column(Database::fetchAll("SELECT nombre FROM listas_opciones WHERE lista = 'sin_inspeccion' ORDER BY orden"), 'nombre')
        );
        $this->assertSame(
            ['Admin', 'Recepción', 'Supervisora'],
            array_column(Database::fetchAll(
                "SELECT r.nombre FROM rol_permisos rp JOIN roles r ON r.id = rp.rol_id
                  WHERE rp.permiso_codigo = 'auditoria.sin_inspeccion' ORDER BY r.nombre"
            ), 'nombre'),
            'los roles que hoy aprueban'
        );
        $this->assertSame(0, count(Database::fetchAll('PRAGMA foreign_key_check')), 'las FK siguen sanas');

        $esquema = (new EsquemaService())->faltantes(true);
        $this->assertTrue($esquema['ok'], 'faltan: ' . json_encode($esquema));
    }

    public function testCorrerlaDosVecesNoCambiaNada(): void
    {
        $this->correrMigracion();
        // Alguien desactiva una opción entre medio: la segunda corrida no la vuelve a sembrar.
        Database::execute("UPDATE listas_opciones SET activo = 0 WHERE lista = 'sin_inspeccion' AND nombre = 'Cartel No molestar'");

        $salida = $this->correrMigracion();
        $this->assertStringContainsString('ya acepta todos los valores', $salida);
        $this->assertStringContainsString('ya tenía opciones', $salida);
        $this->assertSame(2, (int) Database::fetchColumn("SELECT COUNT(*) FROM listas_opciones WHERE lista = 'sin_inspeccion'"));
        $this->assertSame(0, (int) Database::fetchColumn("SELECT activo FROM listas_opciones WHERE nombre = 'Cartel No molestar'"));
        $this->assertSame(3, (int) Database::fetchColumn("SELECT COUNT(*) FROM rol_permisos WHERE permiso_codigo = 'auditoria.sin_inspeccion'"));
    }
}
