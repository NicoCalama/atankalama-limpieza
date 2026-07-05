<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Core\Database;
use PHPUnit\Framework\TestCase;

/**
 * Fija el SQL que el motor de dialecto genera para MariaDB. La suite corre sobre SQLite,
 * donde el dialecto es passthrough y NO detecta una traducción rota; estos tests son la red
 * de seguridad de la traducción a MariaDB sin necesitar un servidor MariaDB real (las
 * funciones bajo prueba son puras: reciben el driver como argumento).
 */
final class DatabaseDialectTest extends TestCase
{
    // ── translateDialect: passthrough en SQLite ──────────────────────────────

    public function testSqliteEsPassthrough(): void
    {
        $sql = "SELECT DATE(e.timestamp_fin), GROUP_CONCAT(r.nombre, ',') "
             . "FROM #__x WHERE updated_at > strftime('%Y-%m-%dT%H:%M:%fZ', 'now') "
             . "AND fecha = date('now')";
        $this->assertSame($sql, Database::translateDialect($sql, 'sqlite'));
    }

    // ── translateDialect: traducciones MariaDB ───────────────────────────────

    public function testMariaTraduceStrftimeIsoNow(): void
    {
        $out = Database::translateDialect("x > strftime('%Y-%m-%dT%H:%M:%fZ', 'now')", 'mariadb');
        $this->assertSame("x > CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')", $out);
    }

    public function testMariaTraduceDateNowAUtcDate(): void
    {
        $out = Database::translateDialect("WHERE fecha = date('now')", 'mariadb');
        $this->assertSame('WHERE fecha = UTC_DATE()', $out);
    }

    public function testMariaTraduceDateColumnaASubstr(): void
    {
        $out = Database::translateDialect('SELECT DATE(ec.timestamp_inicio) FROM t', 'mariadb');
        $this->assertSame('SELECT SUBSTR(ec.timestamp_inicio, 1, 10) FROM t', $out);
    }

    public function testMariaDateDentroDeCountDistinct(): void
    {
        $out = Database::translateDialect('COUNT(DISTINCT DATE(ec.timestamp_inicio))', 'mariadb');
        $this->assertSame('COUNT(DISTINCT SUBSTR(ec.timestamp_inicio, 1, 10))', $out);
    }

    public function testMariaNoRompeUtcDateNiUpdate(): void
    {
        // date('now') -> UTC_DATE() y luego la regla DATE(col) NO debe tocar UTC_DATE();
        // tampoco debe confundir el keyword UPDATE con DATE(.
        $out = Database::translateDialect("UPDATE #__t SET fecha = date('now') WHERE id = 1", 'mariadb');
        $this->assertSame('UPDATE #__t SET fecha = UTC_DATE() WHERE id = 1', $out);
    }

    public function testMariaTraduceInsertOrIgnoreYReplace(): void
    {
        $this->assertSame(
            'INSERT IGNORE INTO #__t (a) VALUES (?)',
            Database::translateDialect('INSERT OR IGNORE INTO #__t (a) VALUES (?)', 'mariadb')
        );
        $this->assertSame(
            'REPLACE INTO #__t (a) VALUES (?)',
            Database::translateDialect('INSERT OR REPLACE INTO #__t (a) VALUES (?)', 'mariadb')
        );
    }

    public function testMariaTraduceGroupConcatSeparador(): void
    {
        $out = Database::translateDialect("GROUP_CONCAT(r.nombre, ',') AS roles", 'mariadb');
        $this->assertSame("GROUP_CONCAT(r.nombre SEPARATOR ',') AS roles", $out);
    }

    // ── diffMinutosSql ───────────────────────────────────────────────────────

    public function testDiffMinutosSqlSqlite(): void
    {
        $this->assertSame(
            '((julianday(ec.timestamp_fin) - julianday(ec.timestamp_inicio)) * 1440)',
            Database::diffMinutosSql('ec.timestamp_inicio', 'ec.timestamp_fin', 'sqlite')
        );
    }

    public function testDiffMinutosSqlMariaDb(): void
    {
        $this->assertSame(
            '(TIMESTAMPDIFF(SECOND, '
            . "REPLACE(REPLACE(ec.timestamp_inicio, 'T', ' '), 'Z', ''), "
            . "REPLACE(REPLACE(ec.timestamp_fin, 'T', ' '), 'Z', '')) / 60.0)",
            Database::diffMinutosSql('ec.timestamp_inicio', 'ec.timestamp_fin', 'mariadb')
        );
    }

    // ── onConflictUpdate ─────────────────────────────────────────────────────

    public function testOnConflictUpdateSqlite(): void
    {
        $this->assertSame(
            'ON CONFLICT(usuario_id, endpoint) DO UPDATE SET p256dh = excluded.p256dh, auth = excluded.auth',
            Database::onConflictUpdate(['usuario_id', 'endpoint'], ['p256dh', 'auth'], 'sqlite')
        );
    }

    public function testOnConflictUpdateMariaDb(): void
    {
        $this->assertSame(
            'ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth)',
            Database::onConflictUpdate(['usuario_id', 'endpoint'], ['p256dh', 'auth'], 'mariadb')
        );
    }

    // ── Query representativa end-to-end (pin del SQL MariaDB real) ────────────

    public function testQueryRepresentativaMaria(): void
    {
        // Imita ReportesService: julianday vía diffMinutosSql + DATE(col) en el WHERE.
        $sql = 'SELECT ROUND(AVG(' . Database::diffMinutosSql('ec.timestamp_inicio', 'ec.timestamp_fin', 'mariadb') . '), 1) '
             . 'FROM #__ejecuciones_checklist ec WHERE DATE(ec.timestamp_inicio) BETWEEN ? AND ?';
        $out = Database::translateDialect($sql, 'mariadb');

        $this->assertStringContainsString('TIMESTAMPDIFF(SECOND,', $out);
        $this->assertStringContainsString('WHERE SUBSTR(ec.timestamp_inicio, 1, 10) BETWEEN ? AND ?', $out);
        $this->assertStringNotContainsString('julianday', $out);
        $this->assertStringNotContainsString('DATE(', $out);
    }
}
