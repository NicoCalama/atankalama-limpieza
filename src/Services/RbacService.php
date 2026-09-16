<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;

final class RbacService
{
    /**
     * Permiso "llave maestra": quien puede reasignar permisos a los roles puede reconstruir
     * toda la matriz RBAC y recuperar cualquier otro permiso. Por eso define quién es
     * "administrador" para el invariante anti-bloqueo — RBAC dinámico: NUNCA por nombre de
     * rol. Ver docs/roles-permisos.md §5.3.
     */
    public const PERMISO_ADMIN = 'permisos.asignar_a_rol';

    /** Mensaje único (amable, español chileno) para el 409 de último administrador. */
    public const MSG_ULTIMO_ADMIN = 'Debe existir al menos un administrador. Asigná otro administrador antes de continuar.';

    /**
     * Cuenta los usuarios ACTIVOS con capacidad administrativa: activo=1 y con el permiso
     * llave (PERMISO_ADMIN) efectivo vía cualquiera de sus roles. Definición dinámica del
     * "admin" (nunca por nombre de rol; funciona con multi-rol y roles personalizados).
     */
    public function contarAdminsActivos(): int
    {
        return (int) Database::fetchColumn(
            'SELECT COUNT(DISTINCT u.id)
               FROM #__usuarios u
               JOIN #__usuarios_roles ur ON ur.usuario_id = u.id
               JOIN #__rol_permisos rp ON rp.rol_id = ur.rol_id
              WHERE rp.permiso_codigo = ? AND u.activo = 1',
            [self::PERMISO_ADMIN]
        );
    }

    /**
     * Ejecuta $mutacion dentro de una transacción SERIALIZADA y garantiza el invariante
     * anti-bloqueo: si antes había ≥1 admin activo, después debe seguir habiendo ≥1. Si la
     * mutación dejaría al sistema sin ningún administrador activo, revierte y lanza
     * $exUltimoAdmin (409 ULTIMO_ADMIN). Es el candado real de los 5 vectores (desactivar /
     * eliminar / quitar-rol / editar-matriz / borrar-rol) con la misma lógica "mutar → recontar".
     *
     * Atomicidad: en MariaDB bloquea la fila-centinela del permiso llave (FOR UPDATE) para
     * serializar; en SQLite lo hace el BEGIN IMMEDIATE de transactionImmediate(). Así dos
     * requests concurrentes que dejarían 0 admins no pueden pasar ambos la validación (TOCTOU).
     */
    public function conGuardiaDeAdmin(callable $mutacion, \Throwable $exUltimoAdmin): mixed
    {
        return Database::transactionImmediate(function () use ($mutacion, $exUltimoAdmin) {
            // Serializa la sección crítica. En MariaDB, FOR UPDATE sobre la fila del permiso
            // llave (que siempre existe: la referencia el FK de rol_permisos) bloquea a otros
            // guards concurrentes hasta el commit; en SQLite es un SELECT normal (ya serializa
            // el BEGIN IMMEDIATE).
            Database::query(
                'SELECT codigo FROM #__permisos WHERE codigo = ?' . Database::forUpdate(),
                [self::PERMISO_ADMIN]
            );
            $antes = $this->contarAdminsActivos();
            $resultado = $mutacion();
            if ($antes >= 1 && $this->contarAdminsActivos() === 0) {
                throw $exUltimoAdmin;
            }
            return $resultado;
        });
    }

    /**
     * @return array<int, array{codigo:string, descripcion:string, categoria:string, scope:string}>
     */
    public function listarPermisos(): array
    {
        return Database::fetchAll('SELECT codigo, descripcion, categoria, scope FROM #__permisos ORDER BY categoria, codigo');
    }

    /**
     * @return array<int, array{id:int, nombre:string, descripcion:?string, es_sistema:int, permisos:string[]}>
     */
    public function listarRoles(): array
    {
        $roles = Database::fetchAll('SELECT id, nombre, descripcion, es_sistema FROM #__roles ORDER BY id');
        foreach ($roles as &$rol) {
            $rol['id'] = (int) $rol['id'];
            $rol['es_sistema'] = (int) $rol['es_sistema'];
            $permisos = Database::fetchAll(
                'SELECT permiso_codigo FROM #__rol_permisos WHERE rol_id = ? ORDER BY permiso_codigo',
                [$rol['id']]
            );
            $rol['permisos'] = array_column($permisos, 'permiso_codigo');
        }
        return $roles;
    }

    public function obtenerRol(int $rolId): ?array
    {
        $rol = Database::fetchOne('SELECT id, nombre, descripcion, es_sistema FROM #__roles WHERE id = ?', [$rolId]);
        if ($rol === null) {
            return null;
        }
        $rol['id'] = (int) $rol['id'];
        $rol['es_sistema'] = (int) $rol['es_sistema'];
        $permisos = Database::fetchAll(
            'SELECT permiso_codigo FROM #__rol_permisos WHERE rol_id = ? ORDER BY permiso_codigo',
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
        if (Database::fetchOne('SELECT id FROM #__roles WHERE nombre = ?', [$nombre]) !== null) {
            throw new RbacException('NOMBRE_DUPLICADO', 'Ya existe un rol con ese nombre.', 409);
        }

        $this->validarPermisosExisten($permisos);

        $rolId = Database::transaction(function () use ($nombre, $descripcion, $permisos): int {
            Database::execute(
                'INSERT INTO #__roles (nombre, descripcion, es_sistema) VALUES (?, ?, 0)',
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
        $rol = Database::fetchOne('SELECT id, nombre, es_sistema FROM #__roles WHERE id = ?', [$rolId]);
        if ($rol === null) {
            throw new RbacException('ROL_NO_ENCONTRADO', 'Rol no encontrado.', 404);
        }

        $esSistema = ((int) $rol['es_sistema']) === 1;

        // Guard anti-bloqueo: vaciar permisos.asignar_a_rol del (último) rol que lo otorga
        // degradaría a TODOS sus admins de una vez → 409 si dejaría al sistema sin admin.
        $this->conGuardiaDeAdmin(function () use ($rol, $rolId, $nombre, $descripcion, $permisos, $esSistema): void {
            if ($nombre !== null && !$esSistema && trim($nombre) !== '' && $nombre !== $rol['nombre']) {
                if (Database::fetchOne('SELECT id FROM #__roles WHERE nombre = ? AND id <> ?', [$nombre, $rolId]) !== null) {
                    throw new RbacException('NOMBRE_DUPLICADO', 'Ya existe un rol con ese nombre.', 409);
                }
                Database::execute(
                    "UPDATE #__roles SET nombre = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                    [$nombre, $rolId]
                );
            }

            if ($descripcion !== null) {
                Database::execute(
                    "UPDATE #__roles SET descripcion = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                    [$descripcion, $rolId]
                );
            }

            if ($permisos !== null) {
                $this->validarPermisosExisten($permisos);
                $this->reemplazarPermisosDeRol($rolId, $permisos);
            }
        }, new RbacException('ULTIMO_ADMIN', self::MSG_ULTIMO_ADMIN, 409));

        Logger::audit($adminId, 'rol.actualizar', 'rol', $rolId, [
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'permisos' => $permisos,
        ]);
    }

    public function eliminarRol(int $rolId, int $adminId): void
    {
        $rol = Database::fetchOne('SELECT id, nombre, es_sistema FROM #__roles WHERE id = ?', [$rolId]);
        if ($rol === null) {
            throw new RbacException('ROL_NO_ENCONTRADO', 'Rol no encontrado.', 404);
        }
        if (((int) $rol['es_sistema']) === 1) {
            throw new RbacException('ROL_DE_SISTEMA', 'No se puede eliminar un rol del sistema.', 409);
        }

        // Guard anti-bloqueo: un rol admin-equivalente custom (es_sistema=0) que otorgue el
        // permiso llave puede borrarse; el CASCADE quitaría la capacidad admin de sus usuarios.
        $this->conGuardiaDeAdmin(
            function () use ($rolId): void {
                Database::execute('DELETE FROM #__roles WHERE id = ?', [$rolId]);
            },
            new RbacException('ULTIMO_ADMIN', self::MSG_ULTIMO_ADMIN, 409)
        );
        Logger::audit($adminId, 'rol.eliminar', 'rol', $rolId, ['nombre' => $rol['nombre']]);
    }

    public function asignarRolAUsuario(int $usuarioId, int $rolId, int $adminId): void
    {
        if (Database::fetchOne('SELECT id FROM #__usuarios WHERE id = ?', [$usuarioId]) === null) {
            throw new RbacException('USUARIO_NO_ENCONTRADO', 'Usuario no encontrado.', 404);
        }
        if (Database::fetchOne('SELECT id FROM #__roles WHERE id = ?', [$rolId]) === null) {
            throw new RbacException('ROL_NO_ENCONTRADO', 'Rol no encontrado.', 404);
        }
        Database::execute(
            'INSERT OR IGNORE INTO #__usuarios_roles (usuario_id, rol_id) VALUES (?, ?)',
            [$usuarioId, $rolId]
        );
        Logger::audit($adminId, 'usuario.asignar_rol', 'usuario', $usuarioId, ['rol_id' => $rolId]);
    }

    public function quitarRolAUsuario(int $usuarioId, int $rolId, int $adminId): void
    {
        // Guard anti-bloqueo: si este rol le daba la capacidad admin al último admin activo,
        // quitarlo dejaría al sistema sin administrador → 409.
        $this->conGuardiaDeAdmin(
            function () use ($usuarioId, $rolId): void {
                Database::execute(
                    'DELETE FROM #__usuarios_roles WHERE usuario_id = ? AND rol_id = ?',
                    [$usuarioId, $rolId]
                );
            },
            new RbacException('ULTIMO_ADMIN', self::MSG_ULTIMO_ADMIN, 409)
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
            "SELECT codigo FROM #__permisos WHERE codigo IN ({$placeholders})",
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
        Database::execute('DELETE FROM #__rol_permisos WHERE rol_id = ?', [$rolId]);
        foreach (array_unique($permisos) as $codigo) {
            Database::execute(
                'INSERT INTO #__rol_permisos (rol_id, permiso_codigo) VALUES (?, ?)',
                [$rolId, $codigo]
            );
        }
    }
}
