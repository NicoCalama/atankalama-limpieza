<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\AuthException;
use Atankalama\Limpieza\Services\AuthService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests del flujo público "olvidé mi contraseña" (POST /api/auth/recuperar).
 *
 * Política bajo prueba:
 *   - Solo cambia la contraseña si el email SALIÓ (MAIL_TRANSPORT=log simula éxito).
 *   - Anti-enumeración: RUT inexistente / inactivo / sin email → silencio, sin cambios.
 *   - Toda solicitud consume un intento de throttle (default 3 por ventana).
 *   - El cambio invalida las sesiones del usuario y registra motivo='olvido'.
 */
final class AuthRecuperarTest extends TestCase
{
    private AuthService $auth;
    private string $ip = '203.0.113.42';

    protected function setUp(): void
    {
        TestDatabase::recrear();
        // Transport 'log': simula envío exitoso sin mandar nada (EmailService lee
        // la config en su constructor → sobrescribir ANTES de crear AuthService).
        Config::sobrescribir('MAIL_TRANSPORT', 'log');
        $this->auth = new AuthService();
    }

    protected function tearDown(): void
    {
        // Volver al default para no contaminar otros tests de la suite.
        Config::sobrescribir('MAIL_TRANSPORT', 'smtp');
    }

    private function crearUsuarioConEmail(string $rut = '11111111-1'): array
    {
        [$id, $pwd] = TestDatabase::crearUsuario($rut, 'Recuperable', 'Trabajador');
        Database::execute('UPDATE usuarios SET email = ? WHERE id = ?', ['recuperable@test.cl', $id]);
        return [$id, $pwd];
    }

    public function testRecuperarConEmailCambiaPasswordEInvalidaSesiones(): void
    {
        [$id, $pwd] = $this->crearUsuarioConEmail();

        // Sesión activa previa que debe caer al recuperar
        $r = $this->auth->login('11111111-1', $pwd, $this->ip);
        $this->assertNotNull(Database::fetchOne('SELECT 1 FROM sesiones WHERE token = ?', [$r['token']]));

        $this->auth->recuperarContrasena('11.111.111-1', $this->ip);

        // La contraseña anterior ya no sirve
        try {
            $this->auth->login('11111111-1', $pwd, '203.0.113.99');
            $this->fail('La contraseña vieja debía quedar inválida');
        } catch (AuthException $e) {
            $this->assertSame('CREDENCIALES_INVALIDAS', $e->codigo);
        }

        // Queda flageado para cambio forzado y con traza motivo='olvido' sin admin
        $fila = Database::fetchOne('SELECT requiere_cambio_pwd FROM usuarios WHERE id = ?', [$id]);
        $this->assertSame(1, (int) $fila['requiere_cambio_pwd']);

        $temp = Database::fetchOne(
            'SELECT motivo, generada_por FROM contrasenas_temporales WHERE usuario_id = ?',
            [$id]
        );
        $this->assertNotNull($temp);
        $this->assertSame('olvido', $temp['motivo']);
        $this->assertNull($temp['generada_por']);

        // Las sesiones previas cayeron
        $this->assertNull(Database::fetchOne('SELECT 1 FROM sesiones WHERE usuario_id = ?', [$id]));
    }

    public function testRecuperarRutInexistenteNoLanzaNiDejaTraza(): void
    {
        // RUT válido pero no registrado: silencio total (anti-enumeración)
        $this->auth->recuperarContrasena('99999999-9', $this->ip);

        $this->assertNull(Database::fetchOne('SELECT 1 FROM contrasenas_temporales LIMIT 1'));
    }

    public function testRecuperarSinEmailNoCambiaPassword(): void
    {
        [$id, $pwd] = TestDatabase::crearUsuario('22222222-2', 'Sin Email', 'Trabajador');

        $this->auth->recuperarContrasena('22222222-2', $this->ip);

        // La contraseña original sigue funcionando
        $r = $this->auth->login('22222222-2', $pwd, $this->ip);
        $this->assertSame($id, $r['usuario']->id);
        $this->assertNull(Database::fetchOne('SELECT 1 FROM contrasenas_temporales WHERE usuario_id = ?', [$id]));
    }

    public function testRecuperarUsuarioInactivoNoCambiaPassword(): void
    {
        [$id] = TestDatabase::crearUsuario('33333333-3', 'Inactivo', 'Trabajador', activo: false);
        Database::execute('UPDATE usuarios SET email = ? WHERE id = ?', ['inactivo@test.cl', $id]);

        $this->auth->recuperarContrasena('33333333-3', $this->ip);

        $this->assertNull(Database::fetchOne('SELECT 1 FROM contrasenas_temporales WHERE usuario_id = ?', [$id]));
    }

    public function testRecuperarRutInvalidoLanza(): void
    {
        try {
            $this->auth->recuperarContrasena('abc', $this->ip);
            $this->fail('Debía lanzar RUT_INVALIDO');
        } catch (AuthException $e) {
            $this->assertSame('RUT_INVALIDO', $e->codigo);
            $this->assertSame(400, $e->httpStatus);
        }
    }

    public function testRecuperarSinEnvioDeMailNoCambiaPassword(): void
    {
        // Con transport smtp y SMTP_HOST vacío el correo está deshabilitado:
        // recuperar NO debe pisar el hash (el usuario quedaría bloqueado sin mail).
        Config::sobrescribir('MAIL_TRANSPORT', 'smtp');
        Config::sobrescribir('SMTP_HOST', '');
        $auth = new AuthService();

        [$id, $pwd] = $this->crearUsuarioConEmail('44444444-4');

        $auth->recuperarContrasena('44444444-4', $this->ip);

        $r = $auth->login('44444444-4', $pwd, $this->ip);
        $this->assertSame($id, $r['usuario']->id);
        $this->assertNull(Database::fetchOne('SELECT 1 FROM contrasenas_temporales WHERE usuario_id = ?', [$id]));
    }

    public function testRecuperarThrottleAlCuartoIntento(): void
    {
        // Default RECUPERAR_THROTTLE_MAX_INTENTOS = 3: toda solicitud suma,
        // exista o no el RUT. La 4ª desde la misma (rut|ip) debe bloquearse.
        for ($i = 1; $i <= 3; $i++) {
            $this->auth->recuperarContrasena('99999999-9', $this->ip);
        }

        try {
            $this->auth->recuperarContrasena('99999999-9', $this->ip);
            $this->fail('La cuarta solicitud debía estar bloqueada por THROTTLED');
        } catch (AuthException $e) {
            $this->assertSame('THROTTLED', $e->codigo);
            $this->assertSame(429, $e->httpStatus);
        }
    }

    public function testThrottleDeRecuperarNoContaminaElDeLogin(): void
    {
        [, $pwd] = $this->crearUsuarioConEmail('55555555-5');

        // Agotar el throttle de recuperación para este rut|ip
        for ($i = 1; $i <= 3; $i++) {
            $this->auth->recuperarContrasena('55555555-5', $this->ip);
        }

        // El login del mismo rut|ip sigue funcionando: claves de throttle separadas.
        // (La 1ª recuperación ya rotó la contraseña; usamos la temporal más reciente
        // solo para verificar que login NO tira THROTTLED — credenciales inválidas
        // es el resultado esperado con la pwd vieja.)
        try {
            $this->auth->login('55555555-5', $pwd, $this->ip);
            $this->fail('Debía fallar por credenciales (la pwd rotó), no por throttle');
        } catch (AuthException $e) {
            $this->assertSame('CREDENCIALES_INVALIDAS', $e->codigo);
        }
    }
}
