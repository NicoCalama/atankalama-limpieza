<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\AlertasController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Models\Usuario;
use Atankalama\Limpieza\Services\RevisionEntregaService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * PUT /api/alertas/config (Ajustes → Alertas) solo escribe sus propias claves. alertas_config también
 * guarda datos de otros módulos: el interruptor de la inspección pre-entrega exige
 * revision_entrega.configurar y no se puede cambiar por esta puerta (revisión de la v7).
 */
final class AlertasConfigListaBlancaTest extends TestCase
{
    private function req(array $config): Request
    {
        $r = new Request(metodo: 'PUT', path: '/api/alertas/config', cuerpo: ['config' => $config], ruta: [], query: [], cookies: [], headers: []);
        [$id] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');
        $r->usuario = new Usuario(
            id: $id, rut: '11111111-1', nombre: 'Admin', email: null, activo: true, requiereCambioPwd: false,
            hotelDefault: null, temaPreferido: 'claro', permisos: ['alertas.configurar_umbrales'], roles: ['Admin'],
        );
        return $r;
    }

    protected function setUp(): void
    {
        TestDatabase::recrear();
    }

    public function testRechazaUnaClaveQueNoEsDeAlertas(): void
    {
        $resp = (new AlertasController())->actualizarConfig($this->req([
            'margen_seguridad_minutos' => '20',
            RevisionEntregaService::CLAVE_CONFIG_NO_ENSUCIA => '1',
        ]));

        $this->assertSame(400, $resp->status);
        $this->assertSame('CLAVE_INVALIDA', json_decode($resp->cuerpo, true)['error']['codigo']);
        $this->assertFalse((new RevisionEntregaService())->noEnsucia(), 'el interruptor sigue apagado');
        $this->assertFalse(
            Database::fetchColumn("SELECT valor FROM alertas_config WHERE clave = 'margen_seguridad_minutos'"),
            'no guarda nada a medias'
        );
    }

    public function testGuardaLasClavesDeAjustesAlertas(): void
    {
        $resp = (new AlertasController())->actualizarConfig($this->req(['margen_seguridad_minutos' => '20']));

        $this->assertSame(200, $resp->status, $resp->cuerpo);
        $this->assertSame('20', Database::fetchColumn("SELECT valor FROM alertas_config WHERE clave = 'margen_seguridad_minutos'"));
    }
}
