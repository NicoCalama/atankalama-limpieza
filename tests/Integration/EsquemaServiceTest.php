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
        $this->assertSame([], $r['checks'], 'CHECK reportados como desactualizados en una base recién creada');
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

    // ── Listas de los CHECK ─────────────────────────────────────────────────────
    // La base de producción se creó el 07/07/2026 con un CHECK en alertas_activas.tipo de 7
    // tipos. Los dos que se sumaron después fallaban al guardarse con SQLSTATE[23000] y el
    // verificador no lo veía: solo miraba tablas, columnas y permisos.

    /** El parser tiene que ver las listas de verdad (si no, los tests de abajo pasarían por nada). */
    public function testElParserLeeLasListasDeLosCheck(): void
    {
        $checks = EsquemaService::checksDeSchema(EsquemaService::archivoDeSchema('sqlite'));

        $this->assertContains('aprobacion_deshecha', $checks['alertas_activas.tipo'] ?? []);
        $this->assertContains('aprobada_automatica', $checks['habitaciones.estado'] ?? []);
        $this->assertSame(['0', '1', '2', '3'], $checks['alertas_activas.prioridad'] ?? null);
    }

    /** El caso exacto de producción: el CHECK de tipos se quedó con la lista del 07/07. */
    public function testDetectaUnCheckQueRechazaTiposNuevos(): void
    {
        $this->recrearAlertasActivas(
            "CHECK (tipo IN ('cloudbeds_sync_failed', 'trabajador_en_riesgo', 'habitacion_rechazada',
                'fin_turno_pendientes', 'trabajador_disponible', 'ticket_nuevo', 'habitacion_saltada'))"
        );

        $r = (new EsquemaService())->faltantes();

        $this->assertFalse($r['ok']);
        $this->assertSame(
            ["alertas_activas.tipo: 'inventario_cambios_pendientes', 'aprobacion_deshecha'"],
            $r['checks']
        );
        $this->assertSame([], $r['columnas'], 'La tabla recreada tiene todas sus columnas');
        $this->assertSame(1, $r['total'], 'Una columna desactualizada es UN elemento, no uno por valor');
    }

    /** Sin CHECK en la base, la columna acepta cualquier valor: no hay nada que arreglar. */
    public function testUnaColumnaSinCheckEnLaBaseNoSeReporta(): void
    {
        $this->recrearAlertasActivas('');

        $this->assertSame([], (new EsquemaService())->faltantes()['checks']);
    }

    /** Una lista MÁS ancha que la del schema tampoco es problema (lo que sobra no es error). */
    public function testUnCheckMasAnchoQueElSchemaNoSeReporta(): void
    {
        $tipos = EsquemaService::checksDeSchema(EsquemaService::archivoDeSchema('sqlite'))['alertas_activas.tipo'];
        $tipos[] = 'tipo_de_una_version_futura';
        $this->recrearAlertasActivas("CHECK (tipo IN ('" . implode("', '", $tipos) . "'))");

        $this->assertSame([], (new EsquemaService())->faltantes()['checks']);
    }

    /**
     * Producción es MariaDB, que devuelve el CHECK en information_schema con su propio formato.
     * Los tests corren sobre SQLite, así que el formato se prueba acá directo.
     */
    public function testLeeElFormatoDeMariaDb(): void
    {
        $this->assertSame(
            ['tipo', ['cloudbeds_sync_failed', 'ticket_nuevo']],
            EsquemaService::valoresDeClausulaIn("`tipo` in ('cloudbeds_sync_failed','ticket_nuevo')")
        );
        $this->assertSame(['prioridad', ['0', '1', '2', '3']], EsquemaService::valoresDeClausulaIn('`prioridad` in (0,1,2,3)'));
        // Paréntesis envolventes y la comilla doblada del SQL del schema. (MariaDB imprime una
        // comilla interna como \' — ningún valor de la app lleva comillas, así que no se soporta.)
        $this->assertSame(['x', ["it's"]], EsquemaService::valoresDeClausulaIn("(`x` in ('it''s'))"));

        // Otras formas de CHECK no enumeran valores: se ignoran en vez de adivinar.
        $this->assertNull(EsquemaService::valoresDeClausulaIn("`x` in ('a') or `x` is null"));
        $this->assertNull(EsquemaService::valoresDeClausulaIn('`hora_fin` > `hora_inicio`'));
    }

    /**
     * Si una lista se amplía en un schema y no en el otro, los tests (SQLite) y producción
     * (MariaDB) dejan de verificar lo mismo. Solo se comparan las columnas que tienen CHECK
     * en los dos: que a SQLite le falte uno no esconde nada en producción.
     */
    public function testLosDosSchemasDeclaranLasMismasListas(): void
    {
        $lite  = EsquemaService::checksDeSchema(EsquemaService::archivoDeSchema('sqlite'));
        $maria = EsquemaService::checksDeSchema(EsquemaService::archivoDeSchema('mysql'));

        $this->assertNotSame([], $lite);
        $this->assertNotSame([], $maria);

        foreach (array_intersect_key($lite, $maria) as $clave => $valores) {
            $a = $valores;
            $b = $maria[$clave];
            sort($a);
            sort($b);
            $this->assertSame($a, $b, "El CHECK de {$clave} no acepta lo mismo en los dos schemas");
        }
    }

    /** Recrea alertas_activas con todas sus columnas y el CHECK de `tipo` que se le pase. */
    private function recrearAlertasActivas(string $checkTipo): void
    {
        Database::execute('DROP TABLE alertas_activas');
        Database::execute("CREATE TABLE alertas_activas (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            tipo          TEXT NOT NULL {$checkTipo},
            prioridad     INTEGER NOT NULL CHECK (prioridad IN (0, 1, 2, 3)),
            titulo        TEXT NOT NULL,
            descripcion   TEXT NOT NULL,
            contexto_json TEXT,
            hotel_id      INTEGER,
            created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
        )");
        EsquemaService::limpiarCache();
    }

    /** Lo que SOBRA en la base no es un error: migraciones viejas pueden dejar cosas. */
    public function testUnaTablaDeMasNoSeReportaComoProblema(): void
    {
        Database::execute('CREATE TABLE sobrante_de_una_migracion_vieja (id INTEGER PRIMARY KEY)');
        EsquemaService::limpiarCache();

        $this->assertTrue((new EsquemaService())->faltantes()['ok']);
    }
}
