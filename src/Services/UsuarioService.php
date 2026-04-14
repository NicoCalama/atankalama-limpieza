<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Usuario;

final class UsuarioService
{
    public function buscarPorId(int $id): ?Usuario
    {
        $fila = Database::fetchOne('SELECT * FROM usuarios WHERE id = ?', [$id]);
        return $fila === null ? null : $this->hidratar($fila);
    }

    public function buscarPorRut(string $rutNormalizado): ?Usuario
    {
        $fila = Database::fetchOne('SELECT * FROM usuarios WHERE rut = ?', [$rutNormalizado]);
        return $fila === null ? null : $this->hidratar($fila);
    }

    /**
     * Hidrata un Usuario cargando sus roles y permisos efectivos (unión).
     *
     * @param array<string, mixed> $fila  Fila cruda de la tabla usuarios
     */
    public function hidratar(array $fila): Usuario
    {
        $usuarioId = (int) $fila['id'];

        $roles = Database::fetchAll(
            'SELECT r.nombre
               FROM usuarios_roles ur
               JOIN roles r ON r.id = ur.rol_id
              WHERE ur.usuario_id = ?
              ORDER BY r.nombre',
            [$usuarioId]
        );

        $permisos = Database::fetchAll(
            'SELECT DISTINCT rp.permiso_codigo AS codigo
               FROM usuarios_roles ur
               JOIN rol_permisos rp ON rp.rol_id = ur.rol_id
              WHERE ur.usuario_id = ?',
            [$usuarioId]
        );

        return new Usuario(
            id: $usuarioId,
            rut: (string) $fila['rut'],
            nombre: (string) $fila['nombre'],
            email: $fila['email'] !== null ? (string) $fila['email'] : null,
            activo: ((int) $fila['activo']) === 1,
            requiereCambioPwd: ((int) $fila['requiere_cambio_pwd']) === 1,
            hotelDefault: $fila['hotel_default'] !== null ? (string) $fila['hotel_default'] : null,
            temaPreferido: (string) $fila['tema_preferido'],
            permisos: array_column($permisos, 'codigo'),
            roles: array_column($roles, 'nombre'),
        );
    }
}
