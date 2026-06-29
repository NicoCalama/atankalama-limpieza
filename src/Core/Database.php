<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Core;

use PDO;
use PDOStatement;

final class Database
{
    /**
     * Token de prefijo de tabla. Las queries (y el esquema) escriben los nombres
     * de tabla como `#__nombre`; aquí se reemplaza por DB_PREFIX antes de preparar.
     * Con DB_PREFIX='' (SQLite/local) el token desaparece; con DB_PREFIX='limpieza_'
     * (MariaDB/prod en la BD compartida) produce `limpieza_nombre`, así las tablas
     * de atankalama no chocan con las de otras apps (p.ej. `maisterchef_`).
     */
    public const PREFIX_TOKEN = '#__';

    private static ?PDO $pdo = null;

    /** Driver de BD activo, normalizado: 'sqlite' | 'mysql' | 'mariadb'. */
    public static function driver(): string
    {
        return strtolower((string) Config::get('DB_CONNECTION', 'sqlite'));
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            $driver = self::driver();

            self::$pdo = match ($driver) {
                'mysql', 'mariadb' => self::connectMysql($options),
                default            => self::connectSqlite($options),
            };
        }

        return self::$pdo;
    }

    private static function connectSqlite(array $options): PDO
    {
        $dbPath = (string) Config::get('DB_PATH', 'database/atankalama.db');
        $fullPath = Config::basePath() . DIRECTORY_SEPARATOR . $dbPath;

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $pdo = new PDO('sqlite:' . $fullPath, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');

        return $pdo;
    }

    private static function connectMysql(array $options): PDO
    {
        $database = Config::require('DB_DATABASE');
        $charset  = (string) Config::get('DB_CHARSET', 'utf8mb4');
        $socket   = (string) Config::get('DB_SOCKET', '');
        $username = (string) Config::get('DB_USERNAME', '');
        $password = (string) Config::get('DB_PASSWORD', '');

        if ($socket !== '') {
            $dsn = "mysql:unix_socket={$socket};dbname={$database};charset={$charset}";
        } else {
            $host = (string) Config::get('DB_HOST', '127.0.0.1');
            $port = (string) Config::get('DB_PORT', '3306');
            $dsn  = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
        }

        return new PDO($dsn, $username, $password, $options);
    }

    /** Prefijo de tabla configurado (vacío si no aplica). */
    public static function prefix(): string
    {
        return (string) Config::get('DB_PREFIX', '');
    }

    /** Reemplaza el token de prefijo (#__) por el prefijo configurado. */
    public static function applyPrefix(string $sql): string
    {
        return str_replace(self::PREFIX_TOKEN, self::prefix(), $sql);
    }

    /**
     * Aplica el prefijo de tabla Y traduce el SQL (escrito en dialecto SQLite) al
     * motor activo. En SQLite es passthrough (salvo el prefijo); en MariaDB/MySQL
     * traduce las construcciones SQLite no portables. Las queries de los Services se
     * escriben UNA vez (dialecto SQLite) y corren en ambos motores.
     *
     * Traduce (solo mysql/mariadb):
     *   strftime('%Y-%m-%dT%H:%M:%fZ','now') -> CONCAT(REPLACE(UTC_TIMESTAMP(3),' ','T'),'Z')
     *   date('now')                           -> UTC_DATE()
     *   INSERT OR IGNORE                      -> INSERT IGNORE
     *   INSERT OR REPLACE                     -> REPLACE
     *   GROUP_CONCAT(x, 'sep')                -> GROUP_CONCAT(x SEPARATOR 'sep')
     *
     * NO cubre (se resuelven calculando en PHP y pasando el valor como parametro,
     * por ser ambiguos para traducir): julianday(), aritmetica datetime('now',
     * '-N days') y el concat '||'. Tampoco ON CONFLICT (se reescribe en el origen).
     */
    public static function applyDialect(string $sql): string
    {
        $sql = self::applyPrefix($sql);

        $driver = self::driver();
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return $sql;
        }

        $isoUtc = "CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')";
        $sql = preg_replace("/strftime\\(\\s*'%Y-%m-%dT%H:%M:%[fS]Z'\\s*,\\s*'now'\\s*\\)/i", $isoUtc, $sql);
        $sql = preg_replace("/\\bdate\\(\\s*'now'\\s*\\)/i", 'UTC_DATE()', $sql);
        $sql = preg_replace("/\\bINSERT\\s+OR\\s+IGNORE\\b/i", 'INSERT IGNORE', $sql);
        $sql = preg_replace("/\\bINSERT\\s+OR\\s+REPLACE\\b/i", 'REPLACE', $sql);
        $sql = preg_replace("/GROUP_CONCAT\\(\\s*([^,()]+?)\\s*,\\s*('[^']*')\\s*\\)/i", 'GROUP_CONCAT($1 SEPARATOR $2)', $sql);

        return $sql;
    }

    /** Nombre de tabla ya prefijado (para construir SQL dinámico). */
    public static function tabla(string $nombre): string
    {
        return self::prefix() . $nombre;
    }

    /**
     * Timestamp "ahora" en UTC ISO-8601 con milisegundos (YYYY-MM-DDTHH:MM:SS.sssZ),
     * generado en PHP para no depender de strftime() de SQLite. Pásalo como parámetro
     * en lugar de usar funciones de fecha del motor → portable entre SQLite y MariaDB.
     */
    public static function now(): string
    {
        $t = microtime(true);
        $ms = (int) (($t - floor($t)) * 1000);
        return gmdate('Y-m-d\TH:i:s', (int) $t) . sprintf('.%03dZ', $ms);
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare(self::applyDialect($sql));
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetchColumn(string $sql, array $params = []): mixed
    {
        return self::query($sql, $params)->fetchColumn();
    }

    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    public static function lastInsertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }
}
