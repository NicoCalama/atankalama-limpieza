<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Support;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\PasswordService;

final class TestDatabase
{
    /**
     * Recrea la BD de tests desde cero: borra archivo, aplica schema, siembra mínimos.
     */
    public static function recrear(): void
    {
        Database::reset();

        $dbPath = Config::basePath() . DIRECTORY_SEPARATOR . Config::get('DB_PATH', 'database/test.db');
        foreach ([$dbPath, $dbPath . '-journal', $dbPath . '-wal', $dbPath . '-shm'] as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }

        $schema = file_get_contents(Config::basePath() . '/docs/database-schema.sql');
        if ($schema === false) {
            throw new \RuntimeException('No se pudo leer docs/database-schema.sql');
        }
        Database::pdo()->exec($schema);

        self::sembrarMinimos();
    }

    /**
     * Siembra: permisos, 4 roles con sus permisos, y un admin de prueba.
     */
    private static function sembrarMinimos(): void
    {
        $seedDir = Config::basePath() . '/database/seeds';
        $permisos = require $seedDir . '/permisos.php';
        $roles = require $seedDir . '/roles.php';

        foreach ($permisos as [$codigo, $descripcion, $categoria, $scope]) {
            Database::execute(
                'INSERT OR IGNORE INTO permisos (codigo, descripcion, categoria, scope) VALUES (?, ?, ?, ?)',
                [$codigo, $descripcion, $categoria, $scope]
            );
        }

        $todos = array_column(Database::fetchAll('SELECT codigo FROM permisos'), 'codigo');

        foreach ($roles as $rol) {
            Database::execute(
                'INSERT INTO roles (nombre, descripcion, es_sistema) VALUES (?, ?, ?)',
                [$rol['nombre'], $rol['descripcion'], $rol['es_sistema']]
            );
            $rolId = Database::lastInsertId();
            $permisosRol = $rol['permisos'] === '__ALL__' ? $todos : $rol['permisos'];
            foreach ($permisosRol as $codigo) {
                Database::execute(
                    'INSERT INTO rol_permisos (rol_id, permiso_codigo) VALUES (?, ?)',
                    [$rolId, $codigo]
                );
            }
        }
    }

    /**
     * Crea un usuario de prueba con un rol específico y pwd conocida.
     * Retorna [usuario_id, password].
     *
     * @return array{0:int, 1:string}
     */
    public static function crearUsuario(
        string $rut,
        string $nombre,
        string $rolNombre,
        string $password = 'Abc12345',
        bool $requiereCambio = false,
        bool $activo = true,
    ): array {
        $pwdService = new PasswordService();
        $hash = $pwdService->hash($password);
        Database::execute(
            'INSERT INTO usuarios (rut, nombre, password_hash, requiere_cambio_pwd, activo, hotel_default) VALUES (?, ?, ?, ?, ?, ?)',
            [$rut, $nombre, $hash, $requiereCambio ? 1 : 0, $activo ? 1 : 0, 'ambos']
        );
        $usuarioId = Database::lastInsertId();

        $rol = Database::fetchOne('SELECT id FROM roles WHERE nombre = ?', [$rolNombre]);
        if ($rol === null) {
            throw new \RuntimeException("Rol de prueba no existe: {$rolNombre}");
        }
        Database::execute(
            'INSERT INTO usuarios_roles (usuario_id, rol_id) VALUES (?, ?)',
            [$usuarioId, (int) $rol['id']]
        );

        return [$usuarioId, $password];
    }
}
