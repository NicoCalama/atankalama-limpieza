<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use PDOException;
use Throwable;

final class SistemaController
{
    public function salud(Request $request): Response
    {
        $checks = [
            'db' => $this->verificarBd(),
            'env' => $this->verificarEnv(),
        ];

        $todoOk = !in_array(false, array_column($checks, 'ok'), true);
        $status = $todoOk ? 200 : 503;

        return Response::json([
            'ok' => $todoOk,
            'data' => [
                'app' => Config::get('APP_NAME', 'Atankalama'),
                'env' => Config::get('APP_ENV', 'local'),
                'timestamp' => (new \DateTimeImmutable())->format('c'),
                'checks' => $checks,
            ],
        ], $status);
    }

    /**
     * @return array{ok: bool, mensaje?: string}
     */
    private function verificarBd(): array
    {
        try {
            $fila = Database::fetchOne('SELECT 1 AS uno');
            return ['ok' => $fila !== null && (int) $fila['uno'] === 1];
        } catch (PDOException | Throwable $e) {
            return ['ok' => false, 'mensaje' => 'DB no responde'];
        }
    }

    /**
     * @return array{ok: bool, mensaje?: string}
     */
    private function verificarEnv(): array
    {
        $requeridas = ['APP_NAME', 'APP_ENV', 'SESSION_SECRET'];

        // Las variables de BD dependen del driver: SQLite usa un archivo (DB_PATH),
        // MariaDB/MySQL usan host + base + usuario. Tras la migración, exigir DB_PATH
        // en MariaDB daba un falso negativo (503) aunque la BD respondiera bien.
        $conexion = strtolower((string) Config::get('DB_CONNECTION', 'sqlite'));
        if ($conexion === 'sqlite') {
            $requeridas[] = 'DB_PATH';
        } else {
            array_push($requeridas, 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME');
        }

        $faltantes = [];
        foreach ($requeridas as $clave) {
            if (Config::get($clave) === null || Config::get($clave) === '') {
                $faltantes[] = $clave;
            }
        }
        if ($faltantes === []) {
            return ['ok' => true];
        }
        return ['ok' => false, 'mensaje' => 'Variables faltantes: ' . implode(', ', $faltantes)];
    }
}
