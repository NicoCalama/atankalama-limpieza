<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Helpers\Rut;
use Atankalama\Limpieza\Models\Usuario;

final class UsuarioService
{
    private const HOTELES_VALIDOS = ['1_sur', 'inn', 'ambos'];

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

    /**
     * @param array{rut:string,nombre:string,email?:?string,hotel_default?:?string,roles?:list<int>} $datos
     * @return array{usuario:Usuario, password_temporal:string}
     */
    public function crear(array $datos, int $creadoPor, PasswordService $passwords): array
    {
        $rutNorm = Rut::normalizar($datos['rut'] ?? '');
        if (!Rut::validar($rutNorm)) {
            throw new UsuarioException('RUT_INVALIDO', 'RUT inválido.', 400);
        }
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        if ($nombre === '' || strlen($nombre) > 200) {
            throw new UsuarioException('NOMBRE_INVALIDO', 'Nombre debe tener entre 1 y 200 caracteres.', 400);
        }
        $email = isset($datos['email']) && $datos['email'] !== '' ? trim((string) $datos['email']) : null;
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new UsuarioException('EMAIL_INVALIDO', 'Email inválido.', 400);
        }
        $hotelDefault = $datos['hotel_default'] ?? null;
        if ($hotelDefault !== null && !in_array($hotelDefault, self::HOTELES_VALIDOS, true)) {
            throw new UsuarioException('HOTEL_INVALIDO', 'hotel_default inválido.', 400);
        }

        $existente = Database::fetchOne('SELECT id FROM usuarios WHERE rut = ?', [$rutNorm]);
        if ($existente !== null) {
            throw new UsuarioException('RUT_DUPLICADO', 'Ya existe un usuario con ese RUT.', 409);
        }

        $temporal = $passwords->generarTemporal();
        $hash = $passwords->hash($temporal);

        $usuarioId = 0;
        Database::transaction(function () use (&$usuarioId, $rutNorm, $nombre, $email, $hash, $hotelDefault, $datos, $creadoPor): void {
            Database::execute(
                'INSERT INTO usuarios (rut, nombre, email, password_hash, requiere_cambio_pwd, activo, hotel_default) VALUES (?, ?, ?, ?, 1, 1, ?)',
                [$rutNorm, $nombre, $email, $hash, $hotelDefault]
            );
            $usuarioId = Database::lastInsertId();
            Database::execute(
                'INSERT INTO contrasenas_temporales (usuario_id, generada_por, motivo) VALUES (?, ?, ?)',
                [$usuarioId, $creadoPor, 'creacion']
            );
            $roles = $datos['roles'] ?? [];
            if (is_array($roles)) {
                foreach ($roles as $rolId) {
                    Database::execute(
                        'INSERT OR IGNORE INTO usuarios_roles (usuario_id, rol_id) VALUES (?, ?)',
                        [$usuarioId, (int) $rolId]
                    );
                }
            }
        });

        Logger::audit($creadoPor, 'usuario.crear', 'usuario', $usuarioId, ['rut' => $rutNorm]);
        $usuario = $this->buscarPorId($usuarioId);
        if ($usuario === null) {
            throw new UsuarioException('USUARIO_NO_ENCONTRADO', 'Error al cargar usuario recién creado.', 500);
        }
        return ['usuario' => $usuario, 'password_temporal' => $temporal];
    }

    /**
     * @param array{nombre?:string,email?:?string,hotel_default?:?string,tema_preferido?:string} $datos
     */
    public function actualizar(int $usuarioId, array $datos, int $editadoPor): Usuario
    {
        $existente = Database::fetchOne('SELECT * FROM usuarios WHERE id = ?', [$usuarioId]);
        if ($existente === null) {
            throw new UsuarioException('USUARIO_NO_ENCONTRADO', 'Usuario no encontrado.', 404);
        }
        $sets = [];
        $params = [];
        if (isset($datos['nombre'])) {
            $nombre = trim((string) $datos['nombre']);
            if ($nombre === '') {
                throw new UsuarioException('NOMBRE_INVALIDO', 'Nombre no puede ser vacío.', 400);
            }
            $sets[] = 'nombre = ?';
            $params[] = $nombre;
        }
        if (array_key_exists('email', $datos)) {
            $email = $datos['email'];
            if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new UsuarioException('EMAIL_INVALIDO', 'Email inválido.', 400);
            }
            $sets[] = 'email = ?';
            $params[] = $email === '' ? null : $email;
        }
        if (array_key_exists('hotel_default', $datos)) {
            $h = $datos['hotel_default'];
            if ($h !== null && !in_array($h, self::HOTELES_VALIDOS, true)) {
                throw new UsuarioException('HOTEL_INVALIDO', 'hotel_default inválido.', 400);
            }
            $sets[] = 'hotel_default = ?';
            $params[] = $h;
        }
        if (isset($datos['tema_preferido'])) {
            if (!in_array($datos['tema_preferido'], ['auto', 'claro', 'oscuro'], true)) {
                throw new UsuarioException('TEMA_INVALIDO', 'Tema inválido.', 400);
            }
            $sets[] = 'tema_preferido = ?';
            $params[] = $datos['tema_preferido'];
        }
        if ($sets === []) {
            return $this->buscarPorId($usuarioId);
        }
        $sets[] = "updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')";
        $params[] = $usuarioId;
        Database::execute('UPDATE usuarios SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        Logger::audit($editadoPor, 'usuario.actualizar', 'usuario', $usuarioId, $datos);
        return $this->buscarPorId($usuarioId);
    }

    public function activar(int $usuarioId, bool $activo, int $editadoPor): Usuario
    {
        $existente = Database::fetchOne('SELECT id FROM usuarios WHERE id = ?', [$usuarioId]);
        if ($existente === null) {
            throw new UsuarioException('USUARIO_NO_ENCONTRADO', 'Usuario no encontrado.', 404);
        }
        Database::execute(
            "UPDATE usuarios SET activo = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
            [$activo ? 1 : 0, $usuarioId]
        );
        if (!$activo) {
            Database::execute('DELETE FROM sesiones WHERE usuario_id = ?', [$usuarioId]);
        }
        Logger::audit($editadoPor, $activo ? 'usuario.activar' : 'usuario.desactivar', 'usuario', $usuarioId, []);
        return $this->buscarPorId($usuarioId);
    }

    /**
     * @param array{activo?:?bool, rol?:?string, busqueda?:?string} $filtros
     * @return list<array<string, mixed>>
     */
    public function listar(array $filtros = []): array
    {
        $sql = "SELECT u.id, u.rut, u.nombre, u.email, u.activo, u.hotel_default, u.last_login_at,
                       GROUP_CONCAT(r.nombre, ',') AS roles
                  FROM usuarios u
             LEFT JOIN usuarios_roles ur ON ur.usuario_id = u.id
             LEFT JOIN roles r ON r.id = ur.rol_id
                 WHERE 1=1";
        $params = [];
        if (isset($filtros['activo'])) {
            $sql .= ' AND u.activo = ?';
            $params[] = $filtros['activo'] ? 1 : 0;
        }
        if (isset($filtros['busqueda']) && is_string($filtros['busqueda']) && $filtros['busqueda'] !== '') {
            $sql .= ' AND (u.nombre LIKE ? OR u.rut LIKE ?)';
            $like = '%' . $filtros['busqueda'] . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' GROUP BY u.id ORDER BY u.nombre';
        $filas = Database::fetchAll($sql, $params);
        $rolFiltro = $filtros['rol'] ?? null;
        if (is_string($rolFiltro) && $rolFiltro !== '') {
            $filas = array_values(array_filter($filas, static function (array $f) use ($rolFiltro): bool {
                $roles = explode(',', (string) ($f['roles'] ?? ''));
                return in_array($rolFiltro, $roles, true);
            }));
        }
        return $filas;
    }
}
