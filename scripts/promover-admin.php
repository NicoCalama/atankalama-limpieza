<?php

declare(strict_types=1);

/**
 * Recuperación de emergencia: RE-PROMUEVE a un usuario a administrador.
 *
 * Úsalo si el sistema quedó sin ningún administrador ACTIVO (por un bug, una migración
 * o una edición directa de la base de datos) y el guard anti-bloqueo no alcanzó a
 * impedirlo. Es la red de seguridad detrás de RbacService::conGuardiaDeAdmin.
 *
 * Qué hace (idempotente):
 *   1. Reactiva al usuario (activo = 1).
 *   2. Le asigna un rol que otorgue el permiso llave permisos.asignar_a_rol (el rol 'Admin'
 *      del seed si existe; si no, el primero que ya tenga ese permiso).
 *   3. Con --reset-pwd, además genera una contraseña temporal (a cambiar al ingresar).
 *
 * NO pasa por el guard anti-bloqueo (sólo AGREGA capacidad admin, nunca la quita), así que
 * es seguro correrlo aunque haya 0 admins.
 *
 * Uso:
 *   php scripts/promover-admin.php <RUT>
 *   php scripts/promover-admin.php <RUT> --reset-pwd
 *
 * Portable SQLite (dev) / MariaDB (prod) vía el token #__ de Database. Documentado en
 * docs/roles-permisos.md §5.3 (recuperación de emergencia).
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Helpers\Rut;
use Atankalama\Limpieza\Services\PasswordService;
use Atankalama\Limpieza\Services\RbacService;

Config::load(dirname(__DIR__));

$rutArg = $argv[1] ?? '';
if ($rutArg === '' || str_starts_with($rutArg, '--')) {
    fwrite(STDERR, "Uso: php scripts/promover-admin.php <RUT> [--reset-pwd]\n");
    exit(1);
}
$resetPwd = in_array('--reset-pwd', $argv, true);

$rut = Rut::normalizar($rutArg);
$usuario = Database::fetchOne('SELECT id, nombre, activo FROM #__usuarios WHERE rut = ?', [$rut]);
if ($usuario === null) {
    fwrite(STDERR, "No se encontró un usuario con RUT {$rut}.\n");
    exit(1);
}
$usuarioId = (int) $usuario['id'];

// Rol que otorga el permiso llave. Preferimos el 'Admin' del seed; si no, cualquiera que ya
// lo tenga. Definido por PERMISO, nunca por nombre de rol (coherente con el guard).
$rol = Database::fetchOne(
    "SELECT r.id, r.nombre
       FROM #__roles r
       JOIN #__rol_permisos rp ON rp.rol_id = r.id
      WHERE rp.permiso_codigo = ?
      ORDER BY (r.nombre = 'Admin') DESC, r.id ASC
      LIMIT 1",
    [RbacService::PERMISO_ADMIN]
);
if ($rol === null) {
    fwrite(
        STDERR,
        "Ningún rol otorga el permiso '" . RbacService::PERMISO_ADMIN . "'.\n"
        . "Corré primero `php scripts/init-db.php` (re-siembra el catálogo RBAC) y reintentá.\n"
    );
    exit(1);
}
$rolId = (int) $rol['id'];

Database::transaction(function () use ($usuarioId, $rolId): void {
    Database::execute('UPDATE #__usuarios SET activo = 1 WHERE id = ?', [$usuarioId]);
    Database::execute(
        'INSERT OR IGNORE INTO #__usuarios_roles (usuario_id, rol_id) VALUES (?, ?)',
        [$usuarioId, $rolId]
    );
});

echo "Usuario '{$usuario['nombre']}' (RUT {$rut}) reactivado y asignado al rol '{$rol['nombre']}'.\n";

if ($resetPwd) {
    $passwords = new PasswordService();
    $temporal = $passwords->generarTemporal();
    Database::execute(
        'UPDATE #__usuarios SET password_hash = ?, requiere_cambio_pwd = 1 WHERE id = ?',
        [$passwords->hash($temporal), $usuarioId]
    );
    echo "Contraseña temporal: {$temporal}\n";
    echo "(Deberá cambiarla al iniciar sesión.)\n";
}

echo "Listo. Ya hay al menos un administrador activo.\n";
