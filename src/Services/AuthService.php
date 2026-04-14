<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Helpers\Rut;
use Atankalama\Limpieza\Models\Usuario;

final class AuthService
{
    public function __construct(
        private readonly UsuarioService $usuarios = new UsuarioService(),
        private readonly PasswordService $passwords = new PasswordService(),
    ) {
    }

    /**
     * Autentica por RUT + password. Devuelve el Usuario hidratado (con permisos).
     * Lanza AuthException con código si falla.
     */
    public function login(string $rutInput, string $password, ?string $ip = null, ?string $userAgent = null): array
    {
        $rut = Rut::normalizar($rutInput);
        if (!Rut::validar($rut)) {
            throw new AuthException('RUT_INVALIDO', 'El RUT no es válido.', 400);
        }

        $fila = Database::fetchOne('SELECT * FROM usuarios WHERE rut = ?', [$rut]);
        if ($fila === null) {
            Logger::warning('auth', 'login fallido: rut no encontrado', ['rut' => $rut]);
            throw new AuthException('CREDENCIALES_INVALIDAS', 'RUT o contraseña incorrectos.', 401);
        }

        if (((int) $fila['activo']) !== 1) {
            Logger::warning('auth', 'login fallido: usuario inactivo', ['usuario_id' => (int) $fila['id']]);
            throw new AuthException('USUARIO_INACTIVO', 'Tu usuario está inactivo. Contacta al admin.', 403);
        }

        if (!$this->passwords->verificar($password, (string) $fila['password_hash'])) {
            Logger::warning('auth', 'login fallido: password incorrecta', ['usuario_id' => (int) $fila['id']]);
            throw new AuthException('CREDENCIALES_INVALIDAS', 'RUT o contraseña incorrectos.', 401);
        }

        $usuario = $this->usuarios->hidratar($fila);

        $token = $this->crearSesion($usuario->id, $ip, $userAgent);

        Database::execute(
            "UPDATE usuarios SET last_login_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
            [$usuario->id]
        );

        Logger::audit($usuario->id, 'auth.login', 'usuario', $usuario->id, [], 'ui', $ip);

        return [
            'token' => $token,
            'usuario' => $usuario,
            'home_target' => $this->calcularHomeTarget($usuario),
        ];
    }

    public function logout(string $token, ?int $usuarioId = null, ?string $ip = null): void
    {
        Database::execute('DELETE FROM sesiones WHERE token = ?', [$token]);
        if ($usuarioId !== null) {
            Logger::audit($usuarioId, 'auth.logout', 'usuario', $usuarioId, [], 'ui', $ip);
        }
    }

    /**
     * Valida el token de sesión. Aplica sliding window: renueva expires_at.
     * Retorna null si no existe o expiró (y borra la fila expirada).
     */
    public function validarSesion(string $token): ?Usuario
    {
        $sesion = Database::fetchOne('SELECT * FROM sesiones WHERE token = ?', [$token]);
        if ($sesion === null) {
            return null;
        }

        if (strtotime((string) $sesion['expires_at']) < time()) {
            Database::execute('DELETE FROM sesiones WHERE token = ?', [$token]);
            return null;
        }

        $nuevoExpires = $this->calcularExpiracion();
        Database::execute('UPDATE sesiones SET expires_at = ? WHERE token = ?', [$nuevoExpires, $token]);

        return $this->usuarios->buscarPorId((int) $sesion['usuario_id']);
    }

    public function cambiarContrasena(int $usuarioId, string $actual, string $nueva, string $confirmacion): void
    {
        if ($nueva !== $confirmacion) {
            throw new AuthException('PWD_NO_COINCIDE', 'La nueva contraseña y su confirmación no coinciden.', 400);
        }

        if (!$this->passwords->validarFortaleza($nueva)) {
            throw new AuthException('PWD_DEBIL', 'La contraseña debe tener al menos 8 caracteres con letras y números.', 400);
        }

        $fila = Database::fetchOne('SELECT password_hash FROM usuarios WHERE id = ?', [$usuarioId]);
        if ($fila === null) {
            throw new AuthException('USUARIO_NO_ENCONTRADO', 'Usuario no encontrado.', 404);
        }

        if (!$this->passwords->verificar($actual, (string) $fila['password_hash'])) {
            throw new AuthException('PWD_ACTUAL_INCORRECTA', 'La contraseña actual no es correcta.', 401);
        }

        Database::transaction(function () use ($usuarioId, $nueva): void {
            Database::execute(
                "UPDATE usuarios SET password_hash = ?, requiere_cambio_pwd = 0, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                [$this->passwords->hash($nueva), $usuarioId]
            );
            Database::execute(
                "UPDATE contrasenas_temporales SET usada = 1, usada_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE usuario_id = ? AND usada = 0",
                [$usuarioId]
            );
        });

        Logger::audit($usuarioId, 'auth.cambiar_password', 'usuario', $usuarioId);
    }

    /**
     * Resetea la pwd de un usuario: genera temporal, flagea requiere_cambio_pwd.
     * Devuelve la pwd temporal (se entrega una sola vez).
     */
    public function resetearContrasenaTemporal(int $usuarioIdObjetivo, int $adminId, string $motivo = 'reset_admin'): string
    {
        $existe = Database::fetchOne('SELECT id FROM usuarios WHERE id = ?', [$usuarioIdObjetivo]);
        if ($existe === null) {
            throw new AuthException('USUARIO_NO_ENCONTRADO', 'Usuario no encontrado.', 404);
        }

        $temporal = $this->passwords->generarTemporal();
        $hash = $this->passwords->hash($temporal);

        Database::transaction(function () use ($usuarioIdObjetivo, $adminId, $motivo, $hash): void {
            Database::execute(
                "UPDATE usuarios SET password_hash = ?, requiere_cambio_pwd = 1, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                [$hash, $usuarioIdObjetivo]
            );
            Database::execute(
                'INSERT INTO contrasenas_temporales (usuario_id, generada_por, motivo) VALUES (?, ?, ?)',
                [$usuarioIdObjetivo, $adminId, $motivo]
            );
            Database::execute('DELETE FROM sesiones WHERE usuario_id = ?', [$usuarioIdObjetivo]);
        });

        Logger::audit($adminId, 'usuario.reset_password', 'usuario', $usuarioIdObjetivo, ['motivo' => $motivo]);

        return $temporal;
    }

    public function calcularHomeTarget(Usuario $usuario): string
    {
        if ($usuario->tienePermiso('ajustes.acceder')) {
            return '/home-admin';
        }
        if ($usuario->tienePermiso('alertas.recibir_predictivas') && $usuario->tienePermiso('asignaciones.asignar_manual')) {
            return '/home-supervisora';
        }
        if ($usuario->tienePermiso('auditoria.ver_bandeja')) {
            return '/home-recepcion';
        }
        if ($usuario->tienePermiso('habitaciones.ver_asignadas_propias')) {
            return '/home-trabajador';
        }
        return '/login';
    }

    private function crearSesion(int $usuarioId, ?string $ip, ?string $userAgent): string
    {
        $token = bin2hex(random_bytes(32));
        $expires = $this->calcularExpiracion();

        Database::execute(
            'INSERT INTO sesiones (token, usuario_id, ip, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)',
            [$token, $usuarioId, $ip, $userAgent, $expires]
        );

        return $token;
    }

    private function calcularExpiracion(): string
    {
        $minutos = Config::getInt('SESSION_LIFETIME_MINUTES', 480);
        return gmdate('Y-m-d\TH:i:s.000\Z', time() + $minutos * 60);
    }
}
