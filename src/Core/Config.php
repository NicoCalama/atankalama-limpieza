<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Core;

use Dotenv\Dotenv;

final class Config
{
    private static ?array $values = null;

    public static function load(string $basePath): void
    {
        if (self::$values !== null) {
            return;
        }

        $dotenv = Dotenv::createImmutable($basePath);
        $dotenv->safeLoad();

        self::$values = $_ENV;

        $timezone = self::get('APP_TIMEZONE', 'America/Santiago');
        date_default_timezone_set($timezone);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$values === null) {
            throw new \RuntimeException('Config no inicializado. Llama a Config::load() primero.');
        }

        return self::$values[$key] ?? $default;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['true', '1', 'yes', 'on'], true);
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Variable de entorno requerida: {$key}");
        }
        return (string) $value;
    }

    public static function basePath(): string
    {
        return dirname(__DIR__, 2);
    }
}
