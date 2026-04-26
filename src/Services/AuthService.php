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
    private static bool $migracionThrottleAplicada = false;

    public function __construct(
        private readonly UsuarioService $usuarios = new UsuarioService(),
        private readonly PasswordService $passwords = new PasswordService(),
    ) {
    }

    /**
     * Autentica por RUT + password. Devuelve el Usuario hidratado (con permisos).
     * Lanza AuthException con código si falla.
     *
     * Aplica rate limiting (throttle) por combinación rut|ip:
     *   - Variables: LOGIN_THROTTLE_MAX_INTENTOS, LOGIN_THROTTLE_VENTANA_MINUTOS, LOGIN_THROTTLE_LOCKOUT_MINUTOS
     *   - Si en los últimos VENTANA_MINUTOS hay >= MAX_INTENTOS fallidos, lanza THROTTLED (HTTP 429)
     *   - Cualquier fallo (RUT inválido, credenciales, usuario inactivo) suma un intento
     *   - Login exitoso limpia el contador para esa clave
     */
    public function login(string $rutInput, string $password, ?string $ip = null, ?string $userAgent = null): array
    {
        $this->asegurarTablaIntentosLogin();

        $rutNorm = Rut::normalizar($rutInput);
        $clave = $this->claveThrottle($rutNorm, $ip);

        // Pre-check: ¿está bloqueado por throttle?
        $this->verificarThrottle($clave, $ip);

        try {
            if (!Rut::validar($rutNorm)) {
                throw new AuthException('RUT_INVALIDO', 'El RUT no es válido.', 400);
            }

            $fila = Database::fetchOne('SELECT * FROM usuarios WHERE rut = ?', [$rutNorm]);
            if ($fila === null) {
                Logger::warning('auth', 'login fallido: rut no encontrado', ['rut' => $rutNorm]);
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
        } catch (AuthException $e) {
            // Cualquier fallo de credenciales/inactivo/rut inválido suma al throttle
            $this->registrarIntentoFallido($clave);
            throw $e;
        }

        $usuario = $this->usuarios->hidratar($fila);

        $token = $this->crearSesion($usuario->id, $ip, $userAgent);

        // Limpia el contador de intentos fallidos para esta clave
        Database::execute('DELETE FROM intentos_login WHERE clave = ?', [$clave]);

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

    /**
     * Construye la clave única de throttle: "<rut>|<ip>" o "<rut>|sin_ip".
     */
    private function claveThrottle(string $rutNorm, ?string $ip): string
    {
        return $rutNorm . '|' . ($ip ?? 'sin_ip');
    }

    /**
     * Trunca la clave para logs (no leakea el RUT completo): primeros 5 chars del RUT + ***|<ip>.
     */
    private function claveTruncada(string $clave): string
    {
        $partes = explode('|', $clave, 2);
        $rutPrefix = substr($partes[0], 0, 5);
        $ipParte = $partes[1] ?? 'sin_ip';
        return $rutPrefix . '***|' . $ipParte;
    }

    /**
     * Cuenta intentos fallidos dentro de la ventana. Si >= MAX, lanza THROTTLED.
     */
    private function verificarThrottle(string $clave, ?string $ip): void
    {
        $max = max(1, Config::getInt('LOGIN_THROTTLE_MAX_INTENTOS', 5));
        $ventanaMin = max(1, Config::getInt('LOGIN_THROTTLE_VENTANA_MINUTOS', 15));

        $desde = gmdate('Y-m-d\TH:i:s.000\Z', time() - $ventanaMin * 60);

        $intentos = Database::fetchAll(
            'SELECT creado_at FROM intentos_login WHERE clave = ? AND creado_at >= ? ORDER BY creado_at ASC',
            [$clave, $desde]
        );

        if (count($intentos) < $max) {
            return;
        }

        // Calcular minutos restantes hasta que el intento más antiguo de los MAX salga de la ventana.
        // Tomamos el (count - max + 1)-ésimo intento más antiguo: cuando ese expire, count caerá bajo MAX.
        $indiceClave = count($intentos) - $max;
        $tsObjetivo = strtotime((string) $intentos[$indiceClave]['creado_at']);
        $expiraEn = $tsObjetivo + $ventanaMin * 60;
        $segundosRestantes = max(1, $expiraEn - time());
        $minutosRestantes = (int) ceil($segundosRestantes / 60);

        Logger::warning('auth', 'login throttled', [
            'clave' => $this->claveTruncada($clave),
            'intentos' => count($intentos),
            'max' => $max,
            'ventana_min' => $ventanaMin,
            'minutos_restantes' => $minutosRestantes,
            'ip' => $ip,
        ]);

        throw new AuthException(
            'THROTTLED',
            "Demasiados intentos. Reintenta en {$minutosRestantes} minutos.",
            429
        );
    }

    /**
     * Inserta una fila por cada intento fallido (RUT_INVALIDO / CREDENCIALES_INVALIDAS / USUARIO_INACTIVO).
     */
    private function registrarIntentoFallido(string $clave): void
    {
        Database::execute('INSERT INTO intentos_login (clave) VALUES (?)', [$clave]);
    }

    /**
     * Migración idempotente: crea la tabla intentos_login si no existe.
     * Permite que dev DBs preexistentes la obtengan sin requerir re-init manual.
     * Solo corre una vez por proceso (flag estático).
     */
    private function asegurarTablaIntentosLogin(): void
    {
        if (self::$migracionThrottleAplicada) {
            return;
        }
        Database::pdo()->exec(
            "CREATE TABLE IF NOT EXISTS intentos_login (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                clave TEXT NOT NULL,
                creado_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
            )"
        );
        Database::pdo()->exec(
            'CREATE INDEX IF NOT EXISTS idx_intentos_login_clave_creado ON intentos_login(clave, creado_at)'
        );
        self::$migracionThrottleAplicada = true;
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
        $usuario = Database::fetchOne('SELECT id, nombre, rut, email FROM usuarios WHERE id = ?', [$usuarioIdObjetivo]);
        if ($usuario === null) {
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

        if (!empty($usuario['email'])) {
            (new EmailService())->enviarPasswordTemporal(
                $usuario['email'],
                $usuario['nombre'],
                $usuario['rut'],
                $temporal,
                'reset_admin'
            );
        }

        return $temporal;
    }

    public function calcularHomeTarget(Usuario $usuario): string
    {
        return '/home';
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
