<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Helpers;

final class LogSanitizer
{
    private const CAMPOS_SENSIBLES = [
        'password',
        'password_hash',
        'password_actual',
        'password_nueva',
        'password_temporal',
        'api_key',
        'api-key',
        'apikey',
        'authorization',
        'x-api-key',
        'cookie',
        'session',
        'token',
        'bearer',
        'secret',
        'credential',
        'cloudbeds_api_key',
        'cloudbeds_api_key_inn',
        'cloudbeds_api_key_principal',
        'claude_api_key',
        'anthropic_api_key',
    ];

    private const REEMPLAZO = '[REDACTED]';

    public static function sanitize(array $payload): array
    {
        $resultado = [];
        foreach ($payload as $key => $value) {
            $keyLower = strtolower((string) $key);
            if (self::esCampoSensible($keyLower)) {
                $resultado[$key] = self::REEMPLAZO;
                continue;
            }

            if (is_array($value)) {
                $resultado[$key] = self::sanitize($value);
                continue;
            }

            if (is_string($value)) {
                $resultado[$key] = self::sanitizeStringValue($value);
                continue;
            }

            $resultado[$key] = $value;
        }

        return $resultado;
    }

    private static function esCampoSensible(string $keyLower): bool
    {
        foreach (self::CAMPOS_SENSIBLES as $sensible) {
            if (str_contains($keyLower, $sensible)) {
                return true;
            }
        }
        return false;
    }

    private static function sanitizeStringValue(string $value): string
    {
        if (preg_match('/^Bearer\s+\S+$/i', $value)) {
            return self::REEMPLAZO;
        }
        if (preg_match('/^sk-[a-zA-Z0-9_-]{20,}$/', $value)) {
            return self::REEMPLAZO;
        }
        return $value;
    }
}
