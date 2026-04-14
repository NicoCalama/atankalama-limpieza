<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Core;

use Atankalama\Limpieza\Helpers\LogSanitizer;

final class Logger
{
    public const NIVEL_INFO = 'INFO';
    public const NIVEL_WARNING = 'WARNING';
    public const NIVEL_ERROR = 'ERROR';

    public static function info(string $modulo, string $mensaje, array $contexto = [], ?int $usuarioId = null): void
    {
        self::log(self::NIVEL_INFO, $modulo, $mensaje, $contexto, $usuarioId);
    }

    public static function warning(string $modulo, string $mensaje, array $contexto = [], ?int $usuarioId = null): void
    {
        self::log(self::NIVEL_WARNING, $modulo, $mensaje, $contexto, $usuarioId);
    }

    public static function error(string $modulo, string $mensaje, array $contexto = [], ?int $usuarioId = null): void
    {
        self::log(self::NIVEL_ERROR, $modulo, $mensaje, $contexto, $usuarioId);
    }

    private static function log(
        string $nivel,
        string $modulo,
        string $mensaje,
        array $contexto,
        ?int $usuarioId
    ): void {
        $sanitizado = LogSanitizer::sanitize($contexto);
        $contextoJson = empty($sanitizado) ? null : json_encode($sanitizado, JSON_UNESCAPED_UNICODE);

        try {
            Database::execute(
                'INSERT INTO logs_eventos (nivel, modulo, mensaje, contexto_json, usuario_id) VALUES (?, ?, ?, ?, ?)',
                [$nivel, $modulo, $mensaje, $contextoJson, $usuarioId]
            );
        } catch (\Throwable $e) {
            self::fallbackFile($nivel, $modulo, $mensaje, $contextoJson, $e->getMessage());
        }
    }

    private static function fallbackFile(string $nivel, string $modulo, string $mensaje, ?string $contextoJson, string $dbError): void
    {
        $logDir = Config::basePath() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $linea = sprintf(
            "[%s] [%s] [%s] %s | ctx=%s | db_error=%s\n",
            date('Y-m-d H:i:s'),
            $nivel,
            $modulo,
            $mensaje,
            $contextoJson ?? '',
            $dbError
        );

        @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'fallback.log', $linea, FILE_APPEND);
    }

    public static function audit(
        ?int $usuarioId,
        string $accion,
        ?string $entidad = null,
        ?int $entidadId = null,
        array $detalles = [],
        string $origen = 'ui',
        ?string $ip = null
    ): void {
        $sanitizado = LogSanitizer::sanitize($detalles);
        $detallesJson = empty($sanitizado) ? null : json_encode($sanitizado, JSON_UNESCAPED_UNICODE);

        Database::execute(
            'INSERT INTO audit_log (usuario_id, accion, entidad, entidad_id, detalles_json, origen, ip) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$usuarioId, $accion, $entidad, $entidadId, $detallesJson, $origen, $ip]
        );
    }
}
