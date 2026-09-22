<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\SistemaController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Services\EsquemaService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class SistemaControllerTest extends TestCase
{
    protected function setUp(): void
    {
        TestDatabase::recrear();
    }

    private function request(): Request
    {
        return new Request(
            metodo: 'GET',
            path: '/api/health',
            cuerpo: [],
            ruta: [],
            query: [],
            cookies: [],
            headers: [],
        );
    }

    /**
     * La red contra el incidente del 22/09/2026: si el SQL de un release no se corrió,
     * el health se cae el día del deploy (el runbook ya lo prueba) en vez de dejar la app
     * fallando en silencio durante días.
     */
    public function testHealthSeCaeCuandoFaltaUnaMigracion(): void
    {
        Database::execute('ALTER TABLE ejecuciones_checklist DROP COLUMN auditoria_iniciada_at');
        EsquemaService::limpiarCache();

        $resp = (new SistemaController())->salud($this->request());
        $payload = json_decode($resp->cuerpo, true);

        $this->assertSame(503, $resp->status);
        $this->assertFalse($payload['data']['checks']['esquema']['ok']);

        // Endpoint público: dice cuánto falta, nunca qué. Los nombres quedan para la
        // pantalla con permiso y para scripts/verificar-esquema.php.
        $mensaje = $payload['data']['checks']['esquema']['mensaje'];
        $this->assertStringContainsString('Faltan migraciones', $mensaje);
        $this->assertStringNotContainsString('auditoria_iniciada_at', $mensaje);
        $this->assertStringNotContainsString('ejecuciones_checklist', $mensaje);
    }

    public function testHealthRetorna200CuandoBdYEnvEstanOk(): void
    {
        $resp = (new SistemaController())->salud($this->request());

        $this->assertSame(200, $resp->status);
        $payload = json_decode($resp->cuerpo, true);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['data']['checks']['db']['ok']);
        $this->assertTrue($payload['data']['checks']['env']['ok']);
        $this->assertTrue($payload['data']['checks']['esquema']['ok']);
        $this->assertArrayHasKey('timestamp', $payload['data']);
        $this->assertArrayHasKey('app', $payload['data']);
        $this->assertArrayHasKey('env', $payload['data']);
    }

    public function testHealthReportaEnvFaltante(): void
    {
        $refl = new \ReflectionClass(\Atankalama\Limpieza\Core\Config::class);
        $prop = $refl->getProperty('values');
        $prop->setAccessible(true);
        $original = $prop->getValue();
        $modificado = $original;
        $modificado['SESSION_SECRET'] = '';
        $prop->setValue(null, $modificado);

        try {
            $resp = (new SistemaController())->salud($this->request());

            $this->assertSame(503, $resp->status);
            $payload = json_decode($resp->cuerpo, true);
            $this->assertFalse($payload['ok']);
            $this->assertFalse($payload['data']['checks']['env']['ok']);
            $this->assertStringContainsString('SESSION_SECRET', $payload['data']['checks']['env']['mensaje']);
        } finally {
            $prop->setValue(null, $original);
        }
    }
}
