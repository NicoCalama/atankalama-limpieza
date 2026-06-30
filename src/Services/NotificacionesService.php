<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;

final class NotificacionesService
{
    private const MAX_POR_USUARIO = 50;

    public function crear(
        int $usuarioId,
        string $tipo,
        string $titulo,
        string $cuerpo,
        string $url = '/home',
    ): void {
        Database::execute(
            'INSERT INTO #__notificaciones (usuario_id, tipo, titulo, cuerpo, url) VALUES (?, ?, ?, ?, ?)',
            [$usuarioId, $tipo, $titulo, $cuerpo, $url]
        );
        $this->limpiarAntiguas($usuarioId);
    }

    /** Crea la misma notificación para múltiples usuarios de una vez. */
    public function crearParaVarios(
        array $usuarioIds,
        string $tipo,
        string $titulo,
        string $cuerpo,
        string $url = '/home',
    ): void {
        foreach ($usuarioIds as $uid) {
            $this->crear((int) $uid, $tipo, $titulo, $cuerpo, $url);
        }
    }

    /** Devuelve las últimas notificaciones del usuario (más recientes primero). */
    public function listar(int $usuarioId, int $limite = 30): array
    {
        // LIMIT con valor inline (entero validado): los prepares nativos de MySQL rechazan
        // 'LIMIT ?' con binding de string. El cast (int) evita cualquier inyección.
        return Database::fetchAll(
            'SELECT id, tipo, titulo, cuerpo, url, leida, created_at
               FROM #__notificaciones
              WHERE usuario_id = ?
              ORDER BY created_at DESC
              LIMIT ' . (int) $limite,
            [$usuarioId]
        );
    }

    public function sinLeer(int $usuarioId): int
    {
        $fila = Database::fetchOne(
            'SELECT COUNT(*) AS total FROM #__notificaciones WHERE usuario_id = ? AND leida = 0',
            [$usuarioId]
        );
        return (int) ($fila['total'] ?? 0);
    }

    public function marcarTodasLeidas(int $usuarioId): void
    {
        Database::execute(
            'UPDATE #__notificaciones SET leida = 1 WHERE usuario_id = ? AND leida = 0',
            [$usuarioId]
        );
    }

    /** Conserva solo las últimas MAX_POR_USUARIO para no inflar la BD. */
    private function limpiarAntiguas(int $usuarioId): void
    {
        // El subquery se envuelve en una tabla derivada (SELECT ... FROM (SELECT ... LIMIT N) AS t)
        // porque MariaDB/MySQL no soportan LIMIT directo dentro de IN/NOT IN (error 1235) ni leer
        // la tabla destino del DELETE en un subquery plano (error 1093). La tabla derivada materializa
        // el resultado y evita ambos; en SQLite es igualmente válido. Portable entre los dos motores.
        Database::execute(
            'DELETE FROM #__notificaciones
              WHERE usuario_id = ?
                AND id NOT IN (
                    SELECT id FROM (
                        SELECT id FROM #__notificaciones
                         WHERE usuario_id = ?
                         ORDER BY created_at DESC
                         LIMIT ' . (int) self::MAX_POR_USUARIO . '
                    ) AS conservadas
                )',
            [$usuarioId, $usuarioId]
        );
    }
}
