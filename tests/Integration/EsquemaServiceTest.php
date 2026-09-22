<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\EsquemaService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Verificador de esquema — la red que faltaba el 22/09/2026, cuando el SQL de la v6.4
 * nunca se corrió en producción y la app falló en silencio durante días.
 *
 * El test importante es el primero: contra una base recién creada desde el MISMO archivo
 * de schema, no puede faltar nada. Si el parser se pierde una tabla o una columna (porque
 * alguien escribió un CREATE TABLE de una forma que no contempla), este test se pone rojo
 * en vez de dejar un verificador que miente diciendo que todo está bien.
 */
final class EsquemaServiceTest extends TestCase
{
    protected function setUp(): void
    {
        TestDatabase::recrear();
        EsquemaService::limpiarCache();
    }

    protected function tearDown(): void
    {
        EsquemaService::limpiarCache();
    }

    public function testBaseReciénCreadaNoTieneNadaFaltante(): void
    {
        $r = (new EsquemaService())->faltantes();

        $this->assertSame([], $r['tablas'], 'Tablas reportadas como faltantes en una base recién creada');
        $this->assertSame([], $r['columnas'], 'Columnas reportadas como faltantes en una base recién creada');
        $this->assertSame([], $r['permisos'], 'Permisos reportados como faltantes en una base recién creada');
        $this->assertTrue($r['ok']);
        $this->assertSame(0, $r['total']);
    }

    /** El parser tiene que ver de verdad el schema, no devolver una lista vacía. */
    public function testElParserEncuentraTablasYColumnasConocidas(): void
    {
        $columnas = Database::fetchAll('PRAGMA table_info(ejecuciones_checklist)');
        $nombres = array_column($columnas, 'name');

        $this->assertContains('auditoria_iniciada_at', $nombres);
        $this->assertContains('timestamp_fin', $nombres);

        // Si el parser no leyera el schema, el test de abajo (columna borrada) pasaría
        // por la razón equivocada. Acá se fuerza que haya parseado algo real.
        Database::execute('DROP TABLE habitaciones');
        EsquemaService::limpiarCache();

        $this->assertContains('habitaciones', (new EsquemaService())->faltantes()['tablas']);
    }

    /** El caso exacto del incidente: la columna de la v6.4 sin aplicar. */
    public function testDetectaUnaColumnaFaltante(): void
    {
        Database::execute('ALTER TABLE ejecuciones_checklist DROP COLUMN auditoria_iniciada_at');
        EsquemaService::limpiarCache();

        $r = (new EsquemaService())->faltantes();

        $this->assertFalse($r['ok']);
        $this->assertContains('ejecuciones_checklist.auditoria_iniciada_at', $r['columnas']);
        $this->assertSame([], $r['tablas']);
        $this->assertSame(1, $r['total']);
    }

    /** La otra mitad del incidente: el permiso del catálogo que nunca se insertó. */
    public function testDetectaUnPermisoFaltante(): void
    {
        Database::execute('DELETE FROM #__permisos WHERE codigo = ?', ['reportes.ver_supervisoras']);
        EsquemaService::limpiarCache();

        $r = (new EsquemaService())->faltantes();

        $this->assertFalse($r['ok']);
        $this->assertContains('reportes.ver_supervisoras', $r['permisos']);
        $this->assertFalse((new EsquemaService())->estaAlDia());
    }

    /** Si falta la tabla entera, no se listan además sus columnas: sería ilegible. */
    public function testUnaTablaFaltanteNoArrastraSusColumnas(): void
    {
        Database::execute('DROP TABLE alertas_activas');
        EsquemaService::limpiarCache();

        $r = (new EsquemaService())->faltantes();

        $this->assertContains('alertas_activas', $r['tablas']);
        foreach ($r['columnas'] as $columna) {
            $this->assertStringStartsNotWith('alertas_activas.', $columna);
        }
    }

    /**
     * Los DOS schemas tienen que declarar lo mismo. El verificador compara la base viva
     * contra el archivo del motor que corre: si el de MariaDB se queda atrás, en producción
     * —que es MariaDB— deja de revisar justo lo que falta, sin avisar.
     *
     * Pasó de verdad: `edificios` y las columnas `habitaciones.edificio/edificio_id/piso`
     * estaban solo en el schema de SQLite. Se repuso el 22/09/2026.
     */
    public function testLosDosSchemasDeclaranLoMismo(): void
    {
        $lite  = EsquemaService::tablasDeSchema(EsquemaService::archivoDeSchema('sqlite'));
        $maria = EsquemaService::tablasDeSchema(EsquemaService::archivoDeSchema('mysql'));

        $this->assertNotSame([], $lite, 'El parser no leyó el schema de SQLite');
        $this->assertNotSame([], $maria, 'El parser no leyó el schema de MariaDB');

        $soloLite  = array_diff(array_keys($lite), array_keys($maria));
        $soloMaria = array_diff(array_keys($maria), array_keys($lite));
        $this->assertSame([], array_values($soloLite), 'Tablas que están solo en el schema de SQLite');
        $this->assertSame([], array_values($soloMaria), 'Tablas que están solo en el schema de MariaDB');

        foreach ($lite as $tabla => $columnas) {
            $this->assertSame(
                [],
                array_values(array_diff($columnas, $maria[$tabla])),
                "Columnas de {$tabla} que faltan en el schema de MariaDB"
            );
            $this->assertSame(
                [],
                array_values(array_diff($maria[$tabla], $columnas)),
                "Columnas de {$tabla} que faltan en el schema de SQLite"
            );
        }
    }

    /** Lo que SOBRA en la base no es un error: migraciones viejas pueden dejar cosas. */
    public function testUnaTablaDeMasNoSeReportaComoProblema(): void
    {
        Database::execute('CREATE TABLE sobrante_de_una_migracion_vieja (id INTEGER PRIMARY KEY)');
        EsquemaService::limpiarCache();

        $this->assertTrue((new EsquemaService())->faltantes()['ok']);
    }
}
