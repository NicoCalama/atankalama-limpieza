<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\EsquemaService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * scripts/migrate-add-listas-opciones.php sobre una base como la de prod antes de la v7.3: crea la tabla,
 * agrega motivos_revision_entrega.orden (alfabético con «Otro» al final) y auditorias.motivo_id, el permiso
 * listas.editar solo para el rol que administra la matriz (Admin) y las opciones iniciales; correrla de
 * nuevo no duplica nada. Al final el verificador de esquema (lo que mira /api/health) queda ok.
 */
final class MigracionListasOpcionesTest extends TestCase
{
    protected function setUp(): void
    {
        TestDatabase::recrear();
        // Simula la base de prod antes de la v7.3 (SQLite no borra columnas con FK: se rehacen las tablas).
        $pdo = Database::pdo();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('DROP TABLE listas_opciones');
        $pdo->exec('CREATE TABLE motivos_tmp AS SELECT id, nombre, activo, created_at FROM motivos_revision_entrega');
        $pdo->exec('DROP TABLE motivos_revision_entrega');
        $pdo->exec(
            "CREATE TABLE motivos_revision_entrega (
                id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL UNIQUE,
                activo INTEGER NOT NULL DEFAULT 1 CHECK (activo IN (0, 1)),
                created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')))"
        );
        $pdo->exec('DROP TABLE motivos_tmp');
        $pdo->exec('ALTER TABLE auditorias RENAME TO auditorias_v73');
        $pdo->exec(
            "CREATE TABLE auditorias (
                id INTEGER PRIMARY KEY AUTOINCREMENT, ejecucion_id INTEGER NOT NULL UNIQUE, habitacion_id INTEGER NOT NULL,
                auditor_id INTEGER NOT NULL,
                veredicto TEXT NOT NULL CHECK (veredicto IN ('aprobado', 'aprobado_con_observacion', 'aprobado_automatico', 'rechazado')),
                comentario TEXT, items_desmarcados_json TEXT,
                created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')))"
        );
        $pdo->exec('DROP TABLE auditorias_v73');
        $pdo->exec('PRAGMA foreign_keys = ON');
        foreach (['Mal olor', 'Otro', 'Baño sucio', 'Basura sin retirar'] as $nombre) {
            Database::execute('INSERT INTO motivos_revision_entrega (nombre) VALUES (?)', [$nombre]);
        }
        Database::execute("DELETE FROM rol_permisos WHERE permiso_codigo = 'listas.editar'");
        Database::execute("DELETE FROM permisos WHERE codigo = 'listas.editar'");
        EsquemaService::limpiarCache();
    }

    private function correrMigracion(): string
    {
        ob_start();
        try {
            include dirname(__DIR__, 2) . '/scripts/migrate-add-listas-opciones.php';
        } finally {
            $salida = (string) ob_get_clean();
        }
        EsquemaService::limpiarCache();
        return $salida;
    }

    public function testMigraYQuedaAlDia(): void
    {
        $this->assertFalse((new EsquemaService())->faltantes(true)['ok'], 'antes de migrar faltan cosas');

        $salida = $this->correrMigracion();
        $this->assertStringContainsString('Migración completa', $salida);

        $this->assertSame(
            ['Baño sucio', 'Basura sin retirar', 'Mal olor', 'Otro'],
            array_column(Database::fetchAll('SELECT nombre FROM motivos_revision_entrega ORDER BY orden'), 'nombre'),
            'alfabético en español y «Otro» al final'
        );
        $this->assertContains('motivo_id', array_column(Database::fetchAll('PRAGMA table_info(auditorias)'), 'name'));
        $this->assertSame(
            ['Admin'],
            array_column(Database::fetchAll(
                "SELECT r.nombre FROM rol_permisos rp JOIN roles r ON r.id = rp.rol_id WHERE rp.permiso_codigo = 'listas.editar'"
            ), 'nombre'),
            'solo el rol que administra la matriz'
        );
        $this->assertSame(36, (int) Database::fetchColumn('SELECT COUNT(*) FROM listas_opciones'), '8 rechazo + 4 saltar + 4 reasignar + 6 mensaje + 9 tipo + 5 respuesta');
        $this->assertContains('tipo_id', array_column(Database::fetchAll('PRAGMA table_info(tickets)'), 'name'));

        $esquema = (new EsquemaService())->faltantes(true);
        $this->assertTrue($esquema['ok'], 'faltan: ' . json_encode($esquema));
    }

    public function testCorrerlaDosVecesNoDuplicaNiPisaElOrden(): void
    {
        $this->correrMigracion();
        // Alguien reordena y desactiva una opción entre medio: la segunda corrida no lo pisa.
        Database::execute("UPDATE motivos_revision_entrega SET orden = 9 WHERE nombre = 'Baño sucio'");
        Database::execute("UPDATE listas_opciones SET activo = 0 WHERE lista = 'saltar' AND nombre = 'Otro'");

        $salida = $this->correrMigracion();
        $this->assertStringContainsString('ya tenían orden', $salida);
        $this->assertSame(36, (int) Database::fetchColumn('SELECT COUNT(*) FROM listas_opciones'));
        $this->assertSame(9, (int) Database::fetchColumn("SELECT orden FROM motivos_revision_entrega WHERE nombre = 'Baño sucio'"));
        $this->assertSame(0, (int) Database::fetchColumn("SELECT activo FROM listas_opciones WHERE lista = 'saltar' AND nombre = 'Otro'"));
        $this->assertSame(1, (int) Database::fetchColumn("SELECT COUNT(*) FROM rol_permisos WHERE permiso_codigo = 'listas.editar'"));
    }
}
