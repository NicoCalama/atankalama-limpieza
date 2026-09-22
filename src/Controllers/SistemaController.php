<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\EsquemaService;
use Throwable;

final class SistemaController
{
    public function salud(Request $request): Response
    {
        $checks = [
            'db' => $this->verificarBd(),
            'env' => $this->verificarEnv(),
            'esquema' => $this->verificarEsquema(),
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
        } catch (Throwable $e) {
            return ['ok' => false, 'mensaje' => 'DB no responde'];
        }
    }

    /**
     * ¿La base tiene lo que el código espera? Un release cuyo SQL no se corrió deja la
     * app fallando en silencio (incidente del 22/09/2026, ver EsquemaService).
     *
     * Este endpoint es PÚBLICO, así que acá solo se dice CUÁNTO falta, nunca qué:
     * los nombres de tablas y columnas le sirven más a un atacante que a nosotros. El
     * detalle está en Inicio → Salud del sistema (detrás de permiso) y en
     * `scripts/verificar-esquema.php`.
     *
     * El runbook ya prueba `/api/health` → 200 después de cada deploy: con esto, un SQL
     * olvidado se cae ahí mismo el día del deploy en vez de a los días.
     *
     * @return array{ok: bool, mensaje?: string}
     */
    private function verificarEsquema(): array
    {
        try {
            $r = (new EsquemaService())->faltantes();
        } catch (Throwable $e) {
            // Un fallo del verificador no puede tumbar el health: si no se puede
            // comprobar, no se afirma que esté mal.
            return ['ok' => true, 'mensaje' => 'No se pudo verificar el esquema'];
        }
        if ($r['ok']) {
            return ['ok' => true];
        }
        return [
            'ok' => false,
            'mensaje' => "Faltan migraciones: {$r['total']} elemento(s). Ver Inicio → Salud del sistema.",
        ];
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
