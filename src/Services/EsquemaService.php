<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;

/**
 * Verifica que la base VIVA tenga todo lo que el código espera.
 *
 * Nació del incidente del 22/09/2026: el SQL de la v6.4 (columna
 * `ejecuciones_checklist.auditoria_iniciada_at` + permiso `reportes.ver_supervisoras`)
 * nunca se corrió en producción. El código subió igual y `POST /api/auditoria/{id}/iniciar`
 * tiró 500 en cada apertura de inspección durante días, en silencio — el frontend se
 * tragaba el error y nadie miraba los logs. Ver docs/deploy-cpanel.md §11.
 *
 * NO usa un manifiesto de migraciones a mano: se apoya en las dos fuentes de verdad que
 * ya existen y ya se mantienen solas, así el chequeo no depende de que alguien se acuerde
 * de anotar su release (que es justo la disciplina que falló):
 *
 *   - `docs/database-schema*.sql`   — el MISMO archivo con el que init-db crea la base
 *   - `database/seeds/permisos.php` — el catálogo de permisos
 *
 * Solo reporta lo que FALTA. Lo que sobra en la base no es error: una migración vieja o
 * una columna que se dejó de usar pueden quedar ahí sin romper nada. Y solo lee, nunca
 * modifica.
 */
final class EsquemaService
{
    /** Arranques de definición que NO son columnas dentro de un CREATE TABLE. */
    private const NO_COLUMNAS = [
        'PRIMARY', 'FOREIGN', 'UNIQUE', 'CHECK', 'CONSTRAINT',
        'KEY', 'INDEX', 'FULLTEXT', 'SPATIAL',
    ];

    /** @var array{ok: bool, tablas: list<string>, columnas: list<string>, permisos: list<string>, total: int}|null */
    private static ?array $cache = null;

    /**
     * Qué le falta a la base para estar al día con el código.
     *
     * Si una tabla falta entera, NO se listan además todas sus columnas: se reporta la
     * tabla y punto, para que el resultado se pueda leer.
     *
     * @return array{ok: bool, tablas: list<string>, columnas: list<string>, permisos: list<string>, total: int}
     */
    public function faltantes(bool $refrescar = false): array
    {
        if (!$refrescar && self::$cache !== null) {
            return self::$cache;
        }

        $esperado = $this->esperadoDesdeSchema();
        $vivo     = $this->vivoDesdeBd();

        $tablasFaltantes  = [];
        $columnasFaltantes = [];

        foreach ($esperado as $tabla => $columnas) {
            if (!isset($vivo[$tabla])) {
                $tablasFaltantes[] = $tabla;
                continue;
            }
            foreach ($columnas as $columna) {
                if (!in_array(strtolower($columna), $vivo[$tabla], true)) {
                    $columnasFaltantes[] = "{$tabla}.{$columna}";
                }
            }
        }

        $permisosFaltantes = $this->permisosFaltantes($tablasFaltantes);

        $total = count($tablasFaltantes) + count($columnasFaltantes) + count($permisosFaltantes);

        return self::$cache = [
            'ok'        => $total === 0,
            'tablas'    => $tablasFaltantes,
            'columnas'  => $columnasFaltantes,
            'permisos'  => $permisosFaltantes,
            'total'     => $total,
        ];
    }

    public function estaAlDia(): bool
    {
        return $this->faltantes()['ok'];
    }

    /** Se llama desde los tests, que recrean la base entre casos. */
    public static function limpiarCache(): void
    {
        self::$cache = null;
    }

    // ───────────────────────────── Esperado (archivos del repo) ─────────────────────────────

    /**
     * Tablas y columnas que el schema del repo declara, SIN el prefijo (`#__`/`limpieza_`).
     *
     * @return array<string, list<string>>
     */
    private function esperadoDesdeSchema(): array
    {
        return self::tablasDeSchema(self::archivoDeSchema(Database::driver()));
    }

    /** Ruta del schema que corresponde a un driver. */
    public static function archivoDeSchema(string $driver): string
    {
        return dirname(__DIR__, 2) . '/docs/' . ($driver === 'sqlite'
            ? 'database-schema.sql'
            : 'database-schema.mariadb.sql');
    }

    /**
     * Tablas y columnas declaradas en un archivo de schema, sin prefijo.
     * Pública para que el test de paridad pueda comparar los DOS schemas entre sí.
     *
     * @return array<string, list<string>>
     */
    public static function tablasDeSchema(string $archivo): array
    {
        $svc = new self();
        $sql = @file_get_contents($archivo);
        if ($sql === false) {
            // Sin el archivo no se puede afirmar que falte algo: mejor no reportar nada
            // que reportar la base entera como faltante.
            return [];
        }

        $sql = $svc->quitarComentarios($sql);
        $tablas = [];
        $offset = 0;

        // CREATE TABLE [IF NOT EXISTS] [#__]nombre (  — el token #__ solo está en el schema
        // de MariaDB; el de SQLite escribe los nombres pelados.
        $patron = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(?:#__)?([A-Za-z0-9_]+)`?\s*\(/i';

        while (preg_match($patron, $sql, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $nombre  = $m[1][0];
            $inicio  = $m[0][1] + strlen($m[0][0]);   // primer carácter después del '('
            $fin     = $svc->cierreDelParentesis($sql, $inicio);

            $tablas[$nombre] = $svc->columnasDeCuerpo(substr($sql, $inicio, $fin - $inicio));
            $offset = $fin;
        }

        return $tablas;
    }

    /**
     * Columnas declaradas en el cuerpo de un CREATE TABLE.
     *
     * Corta por las comas de NIVEL SUPERIOR: un `CHECK (estado IN ('a','b'))` trae comas
     * adentro que no separan columnas. Después descarta las definiciones que no son
     * columnas (PRIMARY KEY, FOREIGN KEY, CHECK suelto…).
     *
     * @return list<string>
     */
    private function columnasDeCuerpo(string $cuerpo): array
    {
        $columnas = [];
        foreach ($this->partirPorComasDeNivelSuperior($cuerpo) as $parte) {
            $parte = trim($parte);
            if ($parte === '' || preg_match('/^`?([A-Za-z_][A-Za-z0-9_]*)`?/', $parte, $m) !== 1) {
                continue;
            }
            if (in_array(strtoupper($m[1]), self::NO_COLUMNAS, true)) {
                continue;
            }
            $columnas[] = $m[1];
        }
        return $columnas;
    }

    /** @return list<string> */
    private function partirPorComasDeNivelSuperior(string $cuerpo): array
    {
        $partes = [];
        $actual = '';
        $nivel  = 0;
        $largo  = strlen($cuerpo);

        for ($i = 0; $i < $largo; $i++) {
            $c = $cuerpo[$i];

            if ($c === "'") {
                $i = $this->finDelLiteral($cuerpo, $i);
                continue;   // el contenido del literal no aporta columnas
            }
            if ($c === '(') {
                $nivel++;
            } elseif ($c === ')') {
                $nivel--;
            } elseif ($c === ',' && $nivel === 0) {
                $partes[] = $actual;
                $actual = '';
                continue;
            }
            $actual .= $c;
        }
        $partes[] = $actual;

        return $partes;
    }

    /** Posición del ')' que cierra el '(' abierto en $inicio-1, saltando literales. */
    private function cierreDelParentesis(string $sql, int $inicio): int
    {
        $nivel = 1;
        $largo = strlen($sql);

        for ($i = $inicio; $i < $largo; $i++) {
            $c = $sql[$i];
            if ($c === "'") {
                $i = $this->finDelLiteral($sql, $i);
                continue;
            }
            if ($c === '(') {
                $nivel++;
            } elseif ($c === ')') {
                $nivel--;
                if ($nivel === 0) {
                    return $i;
                }
            }
        }
        return $largo;
    }

    /** Índice de la comilla que cierra el literal abierto en $inicio ('' = escape). */
    private function finDelLiteral(string $texto, int $inicio): int
    {
        $largo = strlen($texto);
        for ($i = $inicio + 1; $i < $largo; $i++) {
            if ($texto[$i] !== "'") {
                continue;
            }
            if ($i + 1 < $largo && $texto[$i + 1] === "'") {
                $i++;        // comilla escapada ('') — sigue dentro del literal
                continue;
            }
            return $i;
        }
        return $largo;
    }

    /**
     * Saca los comentarios `-- …` antes de parsear: varios traen paréntesis y comas
     * (ej. "-- KPI tiempo por auditación (ver schema SQLite)") que descuadrarían el conteo.
     */
    private function quitarComentarios(string $sql): string
    {
        $salida = '';
        $largo  = strlen($sql);

        for ($i = 0; $i < $largo; $i++) {
            $c = $sql[$i];

            if ($c === "'") {
                $fin = $this->finDelLiteral($sql, $i);
                $salida .= substr($sql, $i, $fin - $i + 1);
                $i = $fin;
                continue;
            }
            if ($c === '-' && $i + 1 < $largo && $sql[$i + 1] === '-') {
                while ($i < $largo && $sql[$i] !== "\n") {
                    $i++;
                }
                $salida .= "\n";
                continue;
            }
            $salida .= $c;
        }

        return $salida;
    }

    // ───────────────────────────── Vivo (la base real) ─────────────────────────────

    /**
     * Tablas y columnas que EXISTEN, sin prefijo y en minúsculas.
     *
     * @return array<string, list<string>>
     */
    private function vivoDesdeBd(): array
    {
        return Database::driver() === 'sqlite' ? $this->vivoSqlite() : $this->vivoMysql();
    }

    /** @return array<string, list<string>> */
    private function vivoMysql(): array
    {
        $filas = Database::fetchAll(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()'
        );

        $vivo = [];
        foreach ($filas as $fila) {
            $tabla = $this->sinPrefijo((string) $fila['t']);
            if ($tabla === null) {
                continue;
            }
            $vivo[$tabla][] = strtolower((string) $fila['c']);
        }
        return $vivo;
    }

    /** @return array<string, list<string>> */
    private function vivoSqlite(): array
    {
        $vivo = [];
        foreach (Database::fetchAll("SELECT name FROM sqlite_master WHERE type = 'table'") as $fila) {
            $real  = (string) $fila['name'];
            $tabla = $this->sinPrefijo($real);
            // PRAGMA no admite parámetros: el nombre viene de sqlite_master, pero igual
            // se valida antes de interpolarlo.
            if ($tabla === null || preg_match('/^[A-Za-z0-9_]+$/', $real) !== 1) {
                continue;
            }
            foreach (Database::fetchAll("PRAGMA table_info({$real})") as $col) {
                $vivo[$tabla][] = strtolower((string) $col['name']);
            }
        }
        return $vivo;
    }

    /**
     * Quita el prefijo configurado. Devuelve null si la tabla NO es de la app
     * (otra aplicación en la misma base, que es el caso de cPanel).
     */
    private function sinPrefijo(string $tabla): ?string
    {
        $prefijo = Database::prefix();
        if ($prefijo === '') {
            return $tabla;
        }
        if (!str_starts_with($tabla, $prefijo)) {
            return null;
        }
        return substr($tabla, strlen($prefijo));
    }

    // ───────────────────────────── Permisos ─────────────────────────────

    /**
     * Códigos del catálogo (`database/seeds/permisos.php`) que no están en la base.
     *
     * NO verifica QUÉ ROLES tienen cada permiso: esa asignación se edita desde
     * Ajustes → Roles y Permisos y no tiene fuente de verdad en el código.
     *
     * @param list<string> $tablasFaltantes
     * @return list<string>
     */
    private function permisosFaltantes(array $tablasFaltantes): array
    {
        if (in_array('permisos', $tablasFaltantes, true)) {
            return [];   // sin la tabla, listar los 100 códigos sería ruido
        }

        $archivo = dirname(__DIR__, 2) . '/database/seeds/permisos.php';
        if (!is_file($archivo)) {
            return [];
        }
        /** @var list<array{0: string, 1: string, 2: string, 3: string}> $catalogo */
        $catalogo = require $archivo;

        $enBd = [];
        foreach (Database::fetchAll('SELECT codigo FROM #__permisos') as $fila) {
            $enBd[(string) $fila['codigo']] = true;
        }

        $faltantes = [];
        foreach ($catalogo as $permiso) {
            $codigo = (string) $permiso[0];
            if ($codigo !== '' && !isset($enBd[$codigo])) {
                $faltantes[] = $codigo;
            }
        }
        return $faltantes;
    }
}
