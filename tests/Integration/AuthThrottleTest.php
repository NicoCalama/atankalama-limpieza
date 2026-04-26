<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\AuthException;
use Atankalama\Limpieza\Services\AuthService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests del rate limiting (throttle) de POST /api/auth/login.
 *
 * Política bajo prueba:
 *   - Default: 5 intentos fallidos por (rut|ip) en ventana de 15 min → 6º bloqueado HTTP 429
 *   - Login exitoso limpia el contador
 *   - Intentos fuera de la ventana no cuentan
 */
final class AuthThrottleTest extends TestCase
{
    private AuthService $auth;
    private string $rut = '11111111-1';
    private string $ip = '203.0.113.42';

    protected function setUp(): void
    {
        TestDatabase::recrear();
        $this->auth = new AuthService();

        // Los tests asumen los defaults del AuthService:
        //   MAX = 5, VENTANA = 15 min, LOCKOUT = 15 min
        // Config::getInt() ya retorna esos defaults si la clave no está en $_ENV.

        TestDatabase::crearUsuario($this->rut, 'Test User', 'Admin', 'Abc12345');
    }

    public function testCincoIntentosFallidosBloqueanElSexto(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            try {
                $this->auth->login($this->rut, 'pwd_mala', $this->ip);
                $this->fail("Intento #{$i}: debía fallar con CREDENCIALES_INVALIDAS");
            } catch (AuthException $e) {
                $this->assertSame('CREDENCIALES_INVALIDAS', $e->codigo, "Intento #{$i}");
                $this->assertSame(401, $e->httpStatus);
            }
        }

        // Sexto intento: debe quedar bloqueado por throttle, incluso con la pwd correcta.
        try {
            $this->auth->login($this->rut, 'Abc12345', $this->ip);
            $this->fail('El sexto intento debía estar bloqueado por THROTTLED');
        } catch (AuthException $e) {
            $this->assertSame('THROTTLED', $e->codigo);
            $this->assertSame(429, $e->httpStatus);
            $this->assertStringContainsString('Demasiados intentos', $e->getMessage());
            $this->assertStringContainsString('minutos', $e->getMessage());
        }
    }

    public function testLoginExitosoLimpiaElContador(): void
    {
        // 3 fallos
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->auth->login($this->rut, 'pwd_mala', $this->ip);
            } catch (AuthException) {
                // ignorado
            }
        }

        $clave = $this->rut . '|' . $this->ip;
        $cuenta = (int) Database::fetchColumn('SELECT COUNT(*) FROM intentos_login WHERE clave = ?', [$clave]);
        $this->assertSame(3, $cuenta, 'Antes del login exitoso debía haber 3 intentos');

        // Login exitoso
        $resultado = $this->auth->login($this->rut, 'Abc12345', $this->ip);
        $this->assertNotEmpty($resultado['token']);

        $cuentaPost = (int) Database::fetchColumn('SELECT COUNT(*) FROM intentos_login WHERE clave = ?', [$clave]);
        $this->assertSame(0, $cuentaPost, 'Login exitoso debía limpiar los intentos fallidos');
    }

    public function testIntentosFueraDeLaVentanaNoCuentan(): void
    {
        $clave = $this->rut . '|' . $this->ip;

        // Insertar 4 intentos "viejos" (hace 60 minutos, fuera de la ventana de 15 min)
        $hace60min = gmdate('Y-m-d\TH:i:s.000\Z', time() - 60 * 60);
        for ($i = 0; $i < 4; $i++) {
            Database::execute(
                'INSERT INTO intentos_login (clave, creado_at) VALUES (?, ?)',
                [$clave, $hace60min]
            );
        }

        // Login con pwd correcta debería funcionar (los 4 intentos viejos no cuentan)
        $resultado = $this->auth->login($this->rut, 'Abc12345', $this->ip);
        $this->assertNotEmpty($resultado['token']);
    }

    public function testThrottleAplicaAUnaIpEspecifica(): void
    {
        // 5 fallos desde IP A bloquean a IP A
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->auth->login($this->rut, 'pwd_mala', '198.51.100.1');
            } catch (AuthException) {
                // ignorado
            }
        }

        try {
            $this->auth->login($this->rut, 'Abc12345', '198.51.100.1');
            $this->fail('Misma IP con max alcanzado debería estar bloqueada');
        } catch (AuthException $e) {
            $this->assertSame('THROTTLED', $e->codigo);
        }

        // Pero desde otra IP debería poder entrar
        $r = $this->auth->login($this->rut, 'Abc12345', '198.51.100.2');
        $this->assertNotEmpty($r['token']);
    }

    public function testRutInvalidoTambienSumaAlThrottle(): void
    {
        $rutMalo = '99999999-0'; // dígito verificador incorrecto
        $clave = \Atankalama\Limpieza\Helpers\Rut::normalizar($rutMalo) . '|' . $this->ip;

        for ($i = 0; $i < 5; $i++) {
            try {
                $this->auth->login($rutMalo, 'cualquiera', $this->ip);
            } catch (AuthException $e) {
                $this->assertSame('RUT_INVALIDO', $e->codigo);
            }
        }

        $cuenta = (int) Database::fetchColumn('SELECT COUNT(*) FROM intentos_login WHERE clave = ?', [$clave]);
        $this->assertSame(5, $cuenta);

        // Sexto intento queda bloqueado
        try {
            $this->auth->login($rutMalo, 'cualquiera', $this->ip);
            $this->fail('Debía estar throttled');
        } catch (AuthException $e) {
            $this->assertSame('THROTTLED', $e->codigo);
            $this->assertSame(429, $e->httpStatus);
        }
    }

    public function testMensajeDeErrorIncluyeMinutosRestantes(): void
    {
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->auth->login($this->rut, 'pwd_mala', $this->ip);
            } catch (AuthException) {
                // ignorado
            }
        }

        try {
            $this->auth->login($this->rut, 'pwd_mala', $this->ip);
            $this->fail('Debía estar throttled');
        } catch (AuthException $e) {
            $this->assertSame('THROTTLED', $e->codigo);
            // El mensaje debe contener un número y la palabra "minutos"
            $this->assertMatchesRegularExpression(
                '/Demasiados intentos\. Reintenta en \d+ minutos\./',
                $e->getMessage()
            );
        }
    }
}
