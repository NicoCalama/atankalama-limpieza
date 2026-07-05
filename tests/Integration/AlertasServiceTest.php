<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\AlertaActiva;
use Atankalama\Limpieza\Models\BitacoraAlerta;
use Atankalama\Limpieza\Services\AlertasException;
use Atankalama\Limpieza\Services\AlertasService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class AlertasServiceTest extends TestCase
{
    private AlertasService $svc;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        $this->svc = new AlertasService();
    }

    public function testLevantarYResolverAlerta(): void
    {
        $alerta = $this->svc->levantar(
            AlertaActiva::TIPO_TICKET_NUEVO,
            'Ticket nuevo',
            'Hay un ticket recién creado.',
            ['ticket_id' => 1]
        );
        $this->assertGreaterThan(0, $alerta->id);
        $this->assertSame(2, $alerta->prioridad);
        $this->assertSame(1, $alerta->contexto['ticket_id']);

        $bitacoraAntes = Database::fetchOne('SELECT COUNT(*) AS n FROM bitacora_alertas WHERE resuelta_at IS NULL');
        $this->assertSame(1, (int) $bitacoraAntes['n']);

        $this->svc->resolver($alerta->id, BitacoraAlerta::RESOLUCION_AUTO);

        $this->assertNull($this->svc->obtener($alerta->id));
        $bitacora = Database::fetchOne('SELECT * FROM bitacora_alertas WHERE id = 1');
        $this->assertSame('auto', $bitacora['resolucion']);
        $this->assertNotNull($bitacora['resuelta_at']);
    }

    public function testTipoInvalidoLanza(): void
    {
        try {
            $this->svc->levantar('inexistente', 'x', 'y');
            $this->fail('Debía lanzar');
        } catch (AlertasException $e) {
            $this->assertSame('TIPO_INVALIDO', $e->codigo);
        }
    }

    public function testDedupeNoCreaDuplicada(): void
    {
        $a1 = $this->svc->levantar(
            AlertaActiva::TIPO_HABITACION_RECHAZADA,
            'Hab 101',
            'Re-limpieza requerida',
            ['habitacion_id' => 5],
            null,
            'habitacion:5'
        );
        $a2 = $this->svc->levantar(
            AlertaActiva::TIPO_HABITACION_RECHAZADA,
            'Hab 101',
            'Re-limpieza requerida',
            ['habitacion_id' => 5],
            null,
            'habitacion:5'
        );
        $this->assertSame($a1->id, $a2->id);

        $count = Database::fetchOne('SELECT COUNT(*) AS n FROM alertas_activas')['n'];
        $this->assertSame(1, (int) $count);
    }

    public function testListarActivasOrdenaPorPrioridad(): void
    {
        $this->svc->levantar(AlertaActiva::TIPO_TICKET_NUEVO, 'P2', 'Ticket', []);
        $this->svc->levantar(AlertaActiva::TIPO_HABITACION_RECHAZADA, 'P1', 'Rechazo', []);
        $this->svc->levantar(AlertaActiva::TIPO_CLOUDBEDS_SYNC_FAILED, 'P0', 'Sync', []);

        $activas = $this->svc->listarActivas();
        $this->assertCount(3, $activas);
        $this->assertSame(0, $activas[0]->prioridad);
        $this->assertSame(1, $activas[1]->prioridad);
        $this->assertSame(2, $activas[2]->prioridad);
    }

    public function testBandejaTopLimitaResultados(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->svc->levantar(AlertaActiva::TIPO_TICKET_NUEVO, "T{$i}", 'd', ['n' => $i]);
        }
        $bandeja = $this->svc->bandejaTop(null, 5);
        $this->assertCount(5, $bandeja['top']);
        $this->assertSame(7, $bandeja['total']);
    }

    public function testConfigDefaultsYActualizar(): void
    {
        $this->assertSame(15, $this->svc->obtenerConfigInt('margen_seguridad_minutos'));

        [$adminId] = TestDatabase::crearUsuario('99999999-9', 'Admin', 'Admin');
        $this->svc->actualizarConfig('margen_seguridad_minutos', '20', $adminId);

        $this->assertSame(20, $this->svc->obtenerConfigInt('margen_seguridad_minutos'));
        $this->assertSame('15', $this->svc->listarConfig()['recalculo_intervalo_minutos']);
    }

    public function testResolverPorDedupeBorraActiva(): void
    {
        $this->svc->levantar(
            AlertaActiva::TIPO_TRABAJADOR_EN_RIESGO,
            't',
            'd',
            [],
            null,
            'trabajador:1:fecha:2026-04-15'
        );
        $this->svc->resolverPorDedupe(
            AlertaActiva::TIPO_TRABAJADOR_EN_RIESGO,
            'trabajador:1:fecha:2026-04-15'
        );
        $count = Database::fetchOne('SELECT COUNT(*) AS n FROM alertas_activas')['n'];
        $this->assertSame(0, (int) $count);
    }

    /**
     * Regresión: con dos alertas del MISMO tipo activas a la vez (dedupe distinto),
     * resolver una debe cerrar SU fila de bitácora, no la más reciente del tipo. Antes,
     * el UPDATE matcheaba por MAX(levantada_at) del tipo y cerraba la bitácora equivocada.
     */
    public function testResolverPorDedupeCierraLaBitacoraCorrectaConVariasActivas(): void
    {
        // Se levanta primero la 101 (menos reciente) y luego la 205.
        $a101 = $this->svc->levantar(
            AlertaActiva::TIPO_HABITACION_SALTADA,
            'Hab 101 saltada',
            'Motivo A',
            ['habitacion_id' => 101],
            null,
            'saltada:101'
        );
        $a205 = $this->svc->levantar(
            AlertaActiva::TIPO_HABITACION_SALTADA,
            'Hab 205 saltada',
            'Motivo B',
            ['habitacion_id' => 205],
            null,
            'saltada:205'
        );

        // Se resuelve la 101 (la MENOS reciente de las dos activas).
        $this->svc->resolverPorDedupe(AlertaActiva::TIPO_HABITACION_SALTADA, 'saltada:101');

        // La bitácora de la 101 (resuelta) queda cerrada...
        $bit101 = Database::fetchOne(
            'SELECT resuelta_at FROM bitacora_alertas WHERE contexto_json LIKE ?',
            ['%"_dedupe":"saltada:101"%']
        );
        $this->assertNotNull($bit101['resuelta_at'], 'La bitácora de la habitación resuelta debe cerrarse');

        // ...y la de la 205 (que sigue saltada) permanece ABIERTA.
        $bit205 = Database::fetchOne(
            'SELECT resuelta_at FROM bitacora_alertas WHERE contexto_json LIKE ?',
            ['%"_dedupe":"saltada:205"%']
        );
        $this->assertNull($bit205['resuelta_at'], 'La bitácora de la habitación aún saltada NO debe cerrarse');

        // Y en las activas: la 101 se borró, la 205 sigue viva.
        $this->assertNull($this->svc->obtener($a101->id));
        $this->assertNotNull($this->svc->obtener($a205->id));
    }
}
