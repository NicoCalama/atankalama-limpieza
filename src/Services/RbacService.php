<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;

final class RbacService
{
    /**
     * @return array<int, array{codigo:string, descripcion:string, categoria:string, scope:string}>
     */
    public function listarPermisos(): array
    {
        return Database::fetchAll('SELECT codigo, descripcion, categoria, scope FROM permisos ORDER BY categoria, codigo');
    }

    /**
     * @return array<int, array{id:int, nombre:string, descripcion:?string, es_sistema:int, permisos:string[]}>
     */
    public function listarRoles(): array
    {
        $roles = Database::fetchAll('SELECT id, nombre, descripcion, es_sistema FROM roles ORDER BY id');
        foreach ($roles as &$rol) {
            $rol['id'] = (int) $rol['id'];
            $rol['es_sistema'] = (int) $rol['es_sistema'];
            $permisos = Database::fetchAll(
                'SELECT permiso_codigo FROM rol_permisos WHERE rol_id = ? ORDER BY permiso_codigo',
                [$rol['id']]
            );
            $rol['permisos'] = array_column($permisos, 'permiso_codigo');
        }
        return $roles;
    }

    public function obtenerRol(int $rolId): ?array
    {
        $rol = Database::fetchOne('SELECT id, nombre, descripcion, es_sistema FROM roles WHERE id = ?', [$rolId]);
        if ($rol === null) {
            return null;
        }
        $rol['id'] = (int) $rol['id'];
        $rol['es_sistema'] = (int) $rol['es_sistema'];
        $permisos = Database::fetchAll(
            'SELECT permiso_codigo FROM rol_permisos WHERE rol_id = ? ORDER BY permiso_codigo',
            [$rolId]
        );
        $rol['permisos'] = array_column($permisos, 'permiso_codigo');
        return $rol;
    }

    public function crearRol(string $nombre, ?string $descripcion, array $permisos, int $adminId): int
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            throw new RbacException('NOMBRE_REQUERIDO', 'El nombre del rol es obligatorio.', 400);
        }
        if (Database::fetchOne('SELECT id FROM roles WHERE nombre = ?', [$nombre]) !== null) {
            throw new RbacException('NOMBRE_DUPLICADO', 'Ya existe un rol con ese nombre.', 409);
        }

        $this->validarPermisosExisten($permisos);

        $rolId = Database::transaction(function () use ($nombre, $descripcion, $permisos): int {
            Database::execute(
                'INSERT INTO roles (nombre, descripcion, es_sistema) VALUES (?, ?, 0)',
                [$nombre, $descripcion]
            );
            $id = Database::lastInsertId();
            $this->reemplazarPermisosDeRol($id, $permisos);
            return $id;
        });

        Logger::audit($adminId, 'rol.crear', 'rol', $rolId, ['nombre' => $nombre, 'permisos' => $permisos]);
        return $rolId;
    }

    public function actualizarRol(int $rolId, ?string $nombre, ?string $descripcion, ?array $permisos, int $adminId): void
    {
        $rol = Database::fetchOne('SELECT id, nombre, es_sistema FROM roles WHERE id = ?', [$rolId]);
        if ($rol === null) {
            throw new RbacException('ROL_NO_ENCONTRADO', 'Rol no encontrado.', 404);
        }

        $esSistema = ((int) $rol['es_sistema']) === 1;

        Database::transaction(function () use ($rol, $rolId, $nombre, $descripcion, $permisos, $esSistema): void {
            if ($nombre !== null && !$esSistema && trim($nombre) !== '' && $nombre !== $rol['nombre']) {
                if (Database::fetchOne('SELECT id FROM roles WHERE nombre = ? AND id <> ?', [$nombre, $rolId]) !== null) {
                    throw new RbacException('NOMBRE_DUPLICADO', 'Ya existe un rol con ese nombre.', 409);
                }
                Database::execute(
                    "UPDATE roles SET nombre = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                    [$nombre, $rolId]
                );
            }

            if ($descripcion !== null) {
                Database::execute(
                    "UPDATE roles SET descripcion = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                    [$descripcion, $rolId]
                );
            }

            if ($permisos !== null) {
                $this->validarPermisosExisten($permisos);
                $this->reemplazarPermisosDeRol($rolId, $permisos);
            }
        });

        Logger::audit($adminId, 'rol.actualizar', 'rol', $rolId, [
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'permisos' => $permisos,
        ]);
    }

    public function eliminarRol(int $rolId, int $adminId): void
    {
        $rol = Database::fetchOne('SELECT id, nombre, es_sistema FROM roles WHERE id = ?', [$rolId]);
        if ($rol === null) {
            throw new RbacException('ROL_NO_ENCONTRADO', 'Rol no encontrado.', 404);
        }
        if (((int) $rol['es_sistema']) === 1) {
            throw new RbacException('ROL_DE_SISTEMA', 'No se puede eliminar un rol del sistema.', 409);
        }

        Database::execute('DELETE FROM roles WHERE id = ?', [$rolId]);
        Logger::audit($adminId, 'rol.eliminar', 'rol', $rolId, ['nombre' => $rol['nombre']]);
    }

    public function asignarRolAUsuario(int $usuarioId, int $rolId, int $adminId): void
    {
        if (Database::fetchOne('SELECT id FROM usuarios WHERE id = ?', [$usuarioId]) === null) {
            throw new RbacException('USUARIO_NO_ENCONTRADO', 'Usuario no encontrado.', 404);
        }
        if (Database::fetchOne('SELECT id FROM roles WHERE id = ?', [$rolId]) === null) {
            throw new RbacException('ROL_NO_ENCONTRADO', 'Rol no encontrado.', 404);
        }
        Database::execute(
            'INSERT OR IGNORE INTO usuarios_roles (usuario_id, rol_id) VALUES (?, ?)',
            [$usuarioId, $rolId]
        );
        Logger::audit($adminId, 'usuario.asignar_rol', 'usuario', $usuarioId, ['rol_id' => $rolId]);
    }

    public function quitarRolAUsuario(int $usuarioId, int $rolId, int $adminId): void
    {
        Database::execute(
            'DELETE FROM usuarios_roles WHERE usuario_id = ? AND rol_id = ?',
            [$usuarioId, $rolId]
        );
        Logger::audit($adminId, 'usuario.quitar_rol', 'usuario', $usuarioId, ['rol_id' => $rolId]);
    }

    /**
     * @param string[] $permisos
     */
    private function validarPermisosExisten(array $permisos): void
    {
        if (empty($permisos)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($permisos), '?'));
        $filas = Database::fetchAll(
            "SELECT codigo FROM permisos WHERE codigo IN ({$placeholders})",
            $permisos
        );
        $existentes = array_column($filas, 'codigo');
        $faltantes = array_diff($permisos, $existentes);
        if (!empty($faltantes)) {
            throw new RbacException(
                'PERMISO_INEXISTENTE',
                'Permisos no existen: ' . implode(', ', $faltantes),
                400
            );
        }
    }

    /**
     * @param string[] $permisos
     */
    private function reemplazarPermisosDeRol(int $rolId, array $permisos): void
    {
        Database::execute('DELETE FROM rol_permisos WHERE rol_id = ?', [$rolId]);
        foreach (array_unique($permisos) as $codigo) {
            Database::execute(
                'INSERT INTO rol_permisos (rol_id, permiso_codigo) VALUES (?, ?)',
                [$rolId, $codigo]
            );
        }
    }
}
