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
            'INSERT INTO notificaciones (usuario_id, tipo, titulo, cuerpo, url) VALUES (?, ?, ?, ?, ?)',
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
        return Database::fetchAll(
            'SELECT id, tipo, titulo, cuerpo, url, leida, created_at
               FROM notificaciones
              WHERE usuario_id = ?
              ORDER BY created_at DESC
              LIMIT ?',
            [$usuarioId, $limite]
        );
    }

    public function sinLeer(int $usuarioId): int
    {
        $fila = Database::fetchOne(
            'SELECT COUNT(*) AS total FROM notificaciones WHERE usuario_id = ? AND leida = 0',
            [$usuarioId]
        );
        return (int) ($fila['total'] ?? 0);
    }

    public function marcarTodasLeidas(int $usuarioId): void
    {
        Database::execute(
            'UPDATE notificaciones SET leida = 1 WHERE usuario_id = ? AND leida = 0',
            [$usuarioId]
        );
    }

    /** Conserva solo las últimas MAX_POR_USUARIO para no inflar la BD. */
    private function limpiarAntiguas(int $usuarioId): void
    {
        Database::execute(
            'DELETE FROM notificaciones
              WHERE usuario_id = ?
                AND id NOT IN (
                    SELECT id FROM notificaciones
                     WHERE usuario_id = ?
                     ORDER BY created_at DESC
                     LIMIT ?
                )',
            [$usuarioId, $usuarioId, self::MAX_POR_USUARIO]
        );
    }
}
