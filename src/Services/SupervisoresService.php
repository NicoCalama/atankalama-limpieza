<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;

/**
 * Supervisores de cada persona (v7.22, reunión del 08/11/2026; idea de Nicolás, 10/11/2026).
 *
 * Se eligen en la ficha de la persona (Usuarios), uno o varios. Por ahora se usan para un aviso:
 * cuando a alguien le asignan un ticket, les llega también a sus supervisores (TicketService::asignar).
 * Ver docs/tickets.md §6.2.
 */
final class SupervisoresService
{
    /**
     * Solo puede supervisar quien tiene este permiso (hoy Admin y Supervisora; Nicolás, 10/11/2026: si
     * aparece otra jefatura, se le da en Ajustes). Se exige al agregar y también al avisar, por si después
     * lo pierde. Para abrir el ticket del aviso, el rol necesita además tickets.ver_todos.
     */
    public const PERMISO_SUPERVISOR = 'usuarios.ser_supervisor';

    /** @return list<array{id: int, nombre: string, activo: bool}> */
    public function supervisoresDe(int $usuarioId): array
    {
        $filas = Database::fetchAll(
            'SELECT u.id, u.nombre, u.activo
               FROM #__usuarios_supervisores us
               JOIN #__usuarios u ON u.id = us.supervisor_id
              WHERE us.usuario_id = ?
              ORDER BY u.nombre',
            [$usuarioId]
        );
        return array_map(static fn(array $f): array => [
            'id' => (int) $f['id'],
            'nombre' => (string) $f['nombre'],
            'activo' => (int) $f['activo'] === 1,
        ], $filas);
    }

    /**
     * Quienes se pueden elegir como supervisores de esta persona: activos, con el permiso, y no ella misma.
     *
     * @return list<array{id: int, nombre: string}>
     */
    public function candidatos(int $usuarioId): array
    {
        $filas = Database::fetchAll(
            'SELECT DISTINCT u.id, u.nombre
               FROM #__usuarios u
               JOIN #__usuarios_roles ur ON ur.usuario_id = u.id
               JOIN #__rol_permisos rp ON rp.rol_id = ur.rol_id
              WHERE rp.permiso_codigo = ? AND u.activo = 1 AND u.id <> ?
              ORDER BY u.nombre',
            [self::PERMISO_SUPERVISOR, $usuarioId]
        );
        return array_map(static fn(array $f): array => [
            'id' => (int) $f['id'],
            'nombre' => (string) $f['nombre'],
        ], $filas);
    }

    /** Agrega un supervisor. Si ya lo era, no hace nada. */
    public function agregar(int $usuarioId, int $supervisorId, int $por): void
    {
        if (Database::fetchOne('SELECT id FROM #__usuarios WHERE id = ?', [$usuarioId]) === null) {
            throw new UsuarioException('USUARIO_NO_ENCONTRADO', 'Usuario no encontrado.', 404);
        }
        if ($supervisorId === $usuarioId) {
            throw new UsuarioException('SUPERVISOR_INVALIDO', 'Una persona no puede ser su propia supervisora.', 400);
        }
        if (!$this->puedeSupervisar($supervisorId)) {
            throw new UsuarioException(
                'SUPERVISOR_INVALIDO',
                'Solo se puede elegir a alguien activo que pueda ser supervisor (Ajustes → Roles y Permisos).',
                400
            );
        }
        if ($this->esSupervisor($usuarioId, $supervisorId)) {
            return;
        }
        Database::execute(
            'INSERT INTO #__usuarios_supervisores (usuario_id, supervisor_id) VALUES (?, ?)',
            [$usuarioId, $supervisorId]
        );
        Logger::audit($por, 'usuario.agregar_supervisor', 'usuario', $usuarioId, ['supervisor_id' => $supervisorId]);
    }

    /** Quita un supervisor. Si no lo era, no hace nada. */
    public function quitar(int $usuarioId, int $supervisorId, int $por): void
    {
        if (!$this->esSupervisor($usuarioId, $supervisorId)) {
            return;
        }
        Database::execute(
            'DELETE FROM #__usuarios_supervisores WHERE usuario_id = ? AND supervisor_id = ?',
            [$usuarioId, $supervisorId]
        );
        Logger::audit($por, 'usuario.quitar_supervisor', 'usuario', $usuarioId, ['supervisor_id' => $supervisorId]);
    }

    /**
     * A quién avisar: por cada supervisor activo que todavía tiene el permiso, las personas de la
     * lista que supervisa.
     *
     * @param list<int> $usuarioIds
     * @return array<int, list<int>> supervisor_id => personas de la lista que supervisa
     */
    public function supervisoresParaAvisar(array $usuarioIds): array
    {
        $usuarioIds = array_values(array_unique(array_map('intval', $usuarioIds)));
        if ($usuarioIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($usuarioIds), '?'));
        $filas = Database::fetchAll(
            "SELECT DISTINCT us.supervisor_id, us.usuario_id
               FROM #__usuarios_supervisores us
               JOIN #__usuarios s ON s.id = us.supervisor_id AND s.activo = 1
               JOIN #__usuarios_roles ur ON ur.usuario_id = s.id
               JOIN #__rol_permisos rp ON rp.rol_id = ur.rol_id AND rp.permiso_codigo = ?
              WHERE us.usuario_id IN ({$placeholders})
              ORDER BY us.supervisor_id, us.usuario_id",
            array_merge([self::PERMISO_SUPERVISOR], $usuarioIds)
        );
        $porSupervisor = [];
        foreach ($filas as $f) {
            $porSupervisor[(int) $f['supervisor_id']][] = (int) $f['usuario_id'];
        }
        return $porSupervisor;
    }

    private function esSupervisor(int $usuarioId, int $supervisorId): bool
    {
        return Database::fetchOne(
            'SELECT 1 AS ok FROM #__usuarios_supervisores WHERE usuario_id = ? AND supervisor_id = ?',
            [$usuarioId, $supervisorId]
        ) !== null;
    }

    private function puedeSupervisar(int $supervisorId): bool
    {
        return Database::fetchOne(
            'SELECT 1 AS ok
               FROM #__usuarios u
               JOIN #__usuarios_roles ur ON ur.usuario_id = u.id
               JOIN #__rol_permisos rp ON rp.rol_id = ur.rol_id
              WHERE u.id = ? AND u.activo = 1 AND rp.permiso_codigo = ?
              LIMIT 1',
            [$supervisorId, self::PERMISO_SUPERVISOR]
        ) !== null;
    }
}
