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
    /**
     * Nombre de la cookie de sesión. Propio de la app (no "session" a secas)
     * porque en prod convive con otras apps bajo el mismo dominio (Maisterchef).
     */
    public const SESSION_COOKIE = 'limpieza_session';

    private static bool $migracionThrottleAplicada = false;

    public function __construct(
        private readonly UsuarioService $usuarios = new UsuarioService(),
        private readonly PasswordService $passwords = new PasswordService(),
        private readonly EmailService $emails = new EmailService(),
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

            $fila = Database::fetchOne('SELECT * FROM #__usuarios WHERE rut = ?', [$rutNorm]);
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
        Database::execute('DELETE FROM #__intentos_login WHERE clave = ?', [$clave]);

        Database::execute(
            "UPDATE #__usuarios SET last_login_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
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
     * $maxOverride permite un límite distinto al de login (p. ej. recuperación).
     */
    private function verificarThrottle(string $clave, ?string $ip, ?int $maxOverride = null): void
    {
        $max = $maxOverride ?? max(1, Config::getInt('LOGIN_THROTTLE_MAX_INTENTOS', 5));
        $ventanaMin = max(1, Config::getInt('LOGIN_THROTTLE_VENTANA_MINUTOS', 15));

        $desde = gmdate('Y-m-d\TH:i:s.000\Z', time() - $ventanaMin * 60);

        $intentos = Database::fetchAll(
            'SELECT creado_at FROM #__intentos_login WHERE clave = ? AND creado_at >= ? ORDER BY creado_at ASC',
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
        Database::execute('INSERT INTO #__intentos_login (clave) VALUES (?)', [$clave]);
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
        // Red de seguridad SOLO para BDs SQLite de desarrollo creadas antes de que
        // intentos_login entrara al esquema. En MariaDB la tabla la crea el esquema/
        // init-db (con prefijo) y además este DDL es dialecto SQLite, así que se omite.
        if (Database::driver() !== 'sqlite') {
            self::$migracionThrottleAplicada = true;
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
        Database::execute('DELETE FROM #__sesiones WHERE token = ?', [$token]);
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
        $sesion = Database::fetchOne('SELECT * FROM #__sesiones WHERE token = ?', [$token]);
        if ($sesion === null) {
            return null;
        }

        if (strtotime((string) $sesion['expires_at']) < time()) {
            Database::execute('DELETE FROM #__sesiones WHERE token = ?', [$token]);
            return null;
        }

        $nuevoExpires = $this->calcularExpiracion();
        Database::execute('UPDATE #__sesiones SET expires_at = ? WHERE token = ?', [$nuevoExpires, $token]);

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

        $fila = Database::fetchOne('SELECT password_hash FROM #__usuarios WHERE id = ?', [$usuarioId]);
        if ($fila === null) {
            throw new AuthException('USUARIO_NO_ENCONTRADO', 'Usuario no encontrado.', 404);
        }

        if (!$this->passwords->verificar($actual, (string) $fila['password_hash'])) {
            throw new AuthException('PWD_ACTUAL_INCORRECTA', 'La contraseña actual no es correcta.', 401);
        }

        Database::transaction(function () use ($usuarioId, $nueva): void {
            Database::execute(
                "UPDATE #__usuarios SET password_hash = ?, requiere_cambio_pwd = 0, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                [$this->passwords->hash($nueva), $usuarioId]
            );
            Database::execute(
                "UPDATE #__contrasenas_temporales SET usada = 1, usada_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE usuario_id = ? AND usada = 0",
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
        $usuario = Database::fetchOne('SELECT id, nombre, rut, email FROM #__usuarios WHERE id = ?', [$usuarioIdObjetivo]);
        if ($usuario === null) {
            throw new AuthException('USUARIO_NO_ENCONTRADO', 'Usuario no encontrado.', 404);
        }

        $temporal = $this->passwords->generarTemporal();
        $hash = $this->passwords->hash($temporal);

        Database::transaction(function () use ($usuarioIdObjetivo, $adminId, $motivo, $hash): void {
            Database::execute(
                "UPDATE #__usuarios SET password_hash = ?, requiere_cambio_pwd = 1, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                [$hash, $usuarioIdObjetivo]
            );
            Database::execute(
                'INSERT INTO #__contrasenas_temporales (usuario_id, generada_por, motivo) VALUES (?, ?, ?)',
                [$usuarioIdObjetivo, $adminId, $motivo]
            );
            Database::execute('DELETE FROM #__sesiones WHERE usuario_id = ?', [$usuarioIdObjetivo]);
        });

        Logger::audit($adminId, 'usuario.reset_password', 'usuario', $usuarioIdObjetivo, ['motivo' => $motivo]);

        if (!empty($usuario['email'])) {
            $this->emails->enviarPasswordTemporal(
                $usuario['email'],
                $usuario['nombre'],
                $usuario['rut'],
                $temporal,
                'reset_admin'
            );
        }

        return $temporal;
    }

    /**
     * Flujo público "olvidé mi contraseña": genera una temporal y la envía al
     * email registrado del usuario.
     *
     * Anti-enumeración: NUNCA revela si el RUT existe — todos los caminos (RUT
     * inexistente, usuario inactivo, sin email, fallo de envío) terminan en
     * silencio y el controlador responde siempre el mismo mensaje genérico.
     *
     * El hash se pisa SOLO si el email salió: si el envío falla o el correo está
     * deshabilitado, el usuario conserva su contraseña actual (no queda
     * bloqueado por un mail perdido).
     *
     * Throttle propio (clave "rec:<rut>|<ip>"): cada solicitud consume un
     * intento, exista o no el RUT, para impedir bombardeo de correos y sondeo
     * de RUTs. Límite: RECUPERAR_THROTTLE_MAX_INTENTOS (default 3) por ventana.
     */
    public function recuperarContrasena(string $rutInput, ?string $ip = null): void
    {
        $this->asegurarTablaIntentosLogin();

        $rutNorm = Rut::normalizar($rutInput);
        if (!Rut::validar($rutNorm)) {
            throw new AuthException('RUT_INVALIDO', 'El RUT no es válido.', 400);
        }

        $clave = 'rec:' . $this->claveThrottle($rutNorm, $ip);
        $max = max(1, Config::getInt('RECUPERAR_THROTTLE_MAX_INTENTOS', 3));
        $this->verificarThrottle($clave, $ip, $max);
        // A diferencia del login, acá TODA solicitud suma (también las "exitosas"):
        // sin esto, un tercero que conozca el RUT podría bombardear el correo ajeno.
        $this->registrarIntentoFallido($clave);

        $usuario = Database::fetchOne(
            'SELECT id, nombre, rut, email, activo FROM #__usuarios WHERE rut = ?',
            [$rutNorm]
        );
        if ($usuario === null || ((int) $usuario['activo']) !== 1 || empty($usuario['email'])) {
            Logger::warning('auth', 'recuperación omitida: RUT sin usuario elegible', [
                'rut' => $rutNorm,
                'ip'  => $ip,
            ]);
            return;
        }

        $usuarioId = (int) $usuario['id'];
        $temporal = $this->passwords->generarTemporal();
        $hash = $this->passwords->hash($temporal);

        $enviado = $this->emails->enviarPasswordTemporal(
            (string) $usuario['email'],
            (string) $usuario['nombre'],
            (string) $usuario['rut'],
            $temporal,
            'olvido'
        );
        if (!$enviado) {
            Logger::warning('auth', 'recuperación abortada: no se pudo enviar el email', [
                'usuario_id' => $usuarioId,
            ]);
            return;
        }

        Database::transaction(function () use ($usuarioId, $hash): void {
            Database::execute(
                "UPDATE #__usuarios SET password_hash = ?, requiere_cambio_pwd = 1, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                [$hash, $usuarioId]
            );
            Database::execute(
                'INSERT INTO #__contrasenas_temporales (usuario_id, generada_por, motivo) VALUES (?, NULL, ?)',
                [$usuarioId, 'olvido']
            );
            // Igual que el reset por admin: la clave cambió, las sesiones viejas caen.
            Database::execute('DELETE FROM #__sesiones WHERE usuario_id = ?', [$usuarioId]);
        });

        Logger::audit($usuarioId, 'auth.recuperar_password', 'usuario', $usuarioId, ['motivo' => 'olvido'], 'ui', $ip);
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
            'INSERT INTO #__sesiones (token, usuario_id, ip, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)',
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
