<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\CloudbedsSyncService;
use Atankalama\Limpieza\Services\HabitacionService;
use Atankalama\Limpieza\Tests\Support\FakeHttpTransport;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class CloudbedsSyncServiceTest extends TestCase
{
    private FakeHttpTransport $transport;
    private CloudbedsSyncService $sync;
    private int $hotel1SurId;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre, cloudbeds_property_id) VALUES ('1_sur', '1 Sur', 'CB_1SUR')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        $this->hotel1SurId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $tipo = (int) Database::fetchOne("SELECT id FROM tipos_habitacion WHERE nombre='Doble'")['id'];

        Database::execute('INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, cloudbeds_room_id, estado) VALUES (?, ?, ?, ?, ?)', [$this->hotel1SurId, '101', $tipo, 'CB_R101', 'aprobada']);
        Database::execute('INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, cloudbeds_room_id, estado) VALUES (?, ?, ?, ?, ?)', [$this->hotel1SurId, '102', $tipo, 'CB_R102', 'sucia']);

        $this->transport = new FakeHttpTransport();
        $client = new CloudbedsClient(
            transport: $this->transport,
            baseUrl: 'https://cb.test',
            apiKey: 'k',
            backoffs: [0, 0, 0],
            dormir: static fn(int $s) => null,
        );
        $this->sync = new CloudbedsSyncService($client);
    }

    public function testSincronizarMarcaChecOutComoSucia(): void
    {
        // Shape real de getHousekeepingStatus: success + data plano con roomCondition.
        $this->transport->encolarOk(200, [
            'success' => true,
            'data' => [
                ['roomID' => 'CB_R101', 'roomCondition' => 'dirty'],
                ['roomID' => 'CB_R102', 'roomCondition' => 'dirty'],
            ],
        ]);

        $syncId = $this->sync->sincronizar(null, 'manual');

        $r101 = Database::fetchOne("SELECT estado FROM habitaciones WHERE numero='101'");
        $this->assertSame('sucia', $r101['estado']); // aprobada → sucia por check-out

        $r102 = Database::fetchOne("SELECT estado FROM habitaciones WHERE numero='102'");
        $this->assertSame('sucia', $r102['estado']); // ya estaba sucia, no cambia

        $hist = Database::fetchOne('SELECT * FROM cloudbeds_sync_historial WHERE id = ?', [$syncId]);
        $this->assertSame('exito', $hist['resultado']);
        $this->assertSame(1, (int) $hist['habitaciones_sincronizadas']);
    }

    public function testSincronizarGuardaLaOcupacion(): void
    {
        // getHousekeepingStatus trae frontdeskStatus + arrival/departure + roomOccupied (verificado
        // en v1.1). El sync debe guardarlos por pieza. Ver docs/ocupacion-y-sabanas.md
        $this->transport->encolarOk(200, [
            'success' => true,
            'data' => [
                ['roomID' => 'CB_R101', 'roomCondition' => 'dirty', 'frontdeskStatus' => 'stayover', 'roomOccupied' => true, 'arrivalDate' => '2026-07-01', 'departureDate' => '2026-07-09'],
                ['roomID' => 'CB_R102', 'roomCondition' => 'clean', 'frontdeskStatus' => 'unused', 'roomOccupied' => false, 'arrivalDate' => '-', 'departureDate' => '-'],
            ],
        ]);

        $this->sync->sincronizar(null, 'manual');

        $r101 = Database::fetchOne("SELECT cb_frontdesk_status, cb_ocupada, cb_arrival_date, cb_departure_date, cb_ocupacion_sync_at FROM habitaciones WHERE numero='101'");
        $this->assertSame('stayover', $r101['cb_frontdesk_status']);
        $this->assertSame(1, (int) $r101['cb_ocupada']);
        $this->assertSame('2026-07-01', $r101['cb_arrival_date']);
        $this->assertSame('2026-07-09', $r101['cb_departure_date']);
        $this->assertNotNull($r101['cb_ocupacion_sync_at']);

        $r102 = Database::fetchOne("SELECT cb_frontdesk_status, cb_ocupada, cb_arrival_date FROM habitaciones WHERE numero='102'");
        $this->assertSame('unused', $r102['cb_frontdesk_status']);
        $this->assertSame(0, (int) $r102['cb_ocupada']);
        $this->assertNull($r102['cb_arrival_date']); // '-' se normaliza a null
    }

    public function testSincronizarConRespuestaSinSuccessGeneraError(): void
    {
        // Regresión del bug del endpoint equivocado: un 404 (o cualquier respuesta
        // sin success=true) debe contar como error y levantar la alerta P0, no
        // reportar "éxito / 0 registros" en silencio. json() sobre el HTML de 404
        // devuelve [] (sin 'success').
        $this->transport->encolarOk(200, []);

        $syncId = $this->sync->sincronizar(null, 'manual');

        $hist = Database::fetchOne('SELECT * FROM cloudbeds_sync_historial WHERE id = ?', [$syncId]);
        $this->assertSame('error', $hist['resultado']);
        $this->assertSame(0, (int) $hist['habitaciones_sincronizadas']);

        $alerta = Database::fetchOne("SELECT * FROM alertas_activas WHERE tipo = 'cloudbeds_sync_failed'");
        $this->assertNotNull($alerta);
        $this->assertSame(0, (int) $alerta['prioridad']);
    }

    public function testSincronizarSinCloudbedsPropertyIdGeneraError(): void
    {
        Database::execute("UPDATE hoteles SET cloudbeds_property_id = NULL WHERE codigo = '1_sur'");

        $syncId = $this->sync->sincronizar(null, 'manual');

        $hist = Database::fetchOne('SELECT * FROM cloudbeds_sync_historial WHERE id = ?', [$syncId]);
        $this->assertSame('error', $hist['resultado']);

        // Debe haber alerta P0
        $alerta = Database::fetchOne("SELECT * FROM alertas_activas WHERE tipo = 'cloudbeds_sync_failed'");
        $this->assertNotNull($alerta);
        $this->assertSame(0, (int) $alerta['prioridad']);
    }

    public function testEscribirEstadoCleanExitoso(): void
    {
        $this->transport->encolarOk(200, ['success' => true]);

        $hab = (new HabitacionService())->obtener(
            (int) Database::fetchOne("SELECT id FROM habitaciones WHERE numero='101'")['id']
        );
        $ok = $this->sync->escribirEstadoClean($hab);

        $this->assertTrue($ok);
        $hist = Database::fetchOne("SELECT * FROM cloudbeds_sync_historial WHERE tipo = 'escritura_estado'");
        $this->assertSame('exito', $hist['resultado']);
        $this->assertNull(Database::fetchOne("SELECT 1 FROM alertas_activas WHERE tipo = 'cloudbeds_sync_failed'"));
    }

    public function testEscrituraConSuccessFalseSeRegistraComoErrorYAlertaP0(): void
    {
        // Regresión: Cloudbeds responde HTTP 200 pero con {"success": false} cuando rechaza
        // la escritura. No debe registrarse como éxito ni enmascararse.
        $this->transport->encolarOk(200, ['success' => false, 'message' => 'Parameter roomID is required']);

        $hab = (new HabitacionService())->obtener(
            (int) Database::fetchOne("SELECT id FROM habitaciones WHERE numero='101'")['id']
        );
        $ok = $this->sync->escribirEstadoClean($hab);

        $this->assertFalse($ok);
        $hist = Database::fetchOne("SELECT * FROM cloudbeds_sync_historial WHERE tipo = 'escritura_estado'");
        $this->assertSame('error', $hist['resultado']);
        $this->assertStringContainsString('Parameter roomID is required', (string) $hist['error_mensaje']);

        $alerta = Database::fetchOne("SELECT * FROM alertas_activas WHERE tipo = 'cloudbeds_sync_failed'");
        $this->assertNotNull($alerta);
        $this->assertSame(0, (int) $alerta['prioridad']);
    }

    public function testEscrituraUsaFormUrlencoded(): void
    {
        // Cloudbeds API v1.1 exige form-urlencoded en los POST (no JSON).
        $this->transport->encolarOk(200, ['success' => true]);

        $hab = (new HabitacionService())->obtener(
            (int) Database::fetchOne("SELECT id FROM habitaciones WHERE numero='101'")['id']
        );
        $this->sync->escribirEstadoClean($hab);

        $ultima = end($this->transport->peticiones);
        $this->assertSame('POST', $ultima['metodo']);
        $this->assertStringContainsString('/postHousekeepingStatus', $ultima['url']);
        $this->assertSame('application/x-www-form-urlencoded', $ultima['content_type']);
    }

    public function testEscribirEstadoCleanConFalloGeneraAlertaP0(): void
    {
        // 1 intento + 3 reintentos = 4 fallos
        for ($i = 0; $i < 4; $i++) {
            $this->transport->encolarFallo(500);
        }

        $hab = (new HabitacionService())->obtener(
            (int) Database::fetchOne("SELECT id FROM habitaciones WHERE numero='101'")['id']
        );
        $ok = $this->sync->escribirEstadoClean($hab);

        $this->assertFalse($ok);

        $alerta = Database::fetchOne("SELECT * FROM alertas_activas WHERE tipo = 'cloudbeds_sync_failed'");
        $this->assertNotNull($alerta);

        $hist = Database::fetchOne("SELECT * FROM cloudbeds_sync_historial WHERE tipo = 'escritura_estado'");
        $this->assertSame('error', $hist['resultado']);
    }

    public function testPayloadDeEscrituraSanitizaTokenEnLogs(): void
    {
        $this->transport->encolarOk(200, ['success' => true]);

        $hab = (new HabitacionService())->obtener(
            (int) Database::fetchOne("SELECT id FROM habitaciones WHERE numero='101'")['id']
        );
        $this->sync->escribirEstadoClean($hab);

        $hist = Database::fetchOne("SELECT payload_request FROM cloudbeds_sync_historial WHERE tipo = 'escritura_estado'");
        $this->assertStringNotContainsString('CLOUDBEDS_API_KEY', $hist['payload_request']);
        $this->assertStringContainsString('roomID', $hist['payload_request']);
    }

    // ----- Throttle del sync automático (Gap C — ver docs/cloudbeds.md §4.1) -----

    public function testDebeCorrerSinHistorialPrevio(): void
    {
        $this->assertTrue($this->sync->debeCorrerSyncAutomatica(30));
    }

    public function testThrottleOmiteTrasSyncReciente(): void
    {
        // Una sync exitosa recién iniciada: con intervalo 30 se omite; con intervalo 0 corre igual.
        $this->transport->encolarOk(200, ['success' => true, 'data' => []]);
        $this->sync->sincronizar(null, 'auto_cron');

        $this->assertFalse($this->sync->debeCorrerSyncAutomatica(30));
        $this->assertTrue($this->sync->debeCorrerSyncAutomatica(0));
    }

    public function testSyncConErrorNoThrottlea(): void
    {
        // Si la última sync falló, el siguiente tick del cron debe reintentar (no throttlear).
        $this->transport->encolarOk(200, []); // sin success=true → error
        $this->sync->sincronizar(null, 'auto_cron');

        $this->assertTrue($this->sync->debeCorrerSyncAutomatica(30));
    }

    public function testEscrituraDeEstadoNoThrottleaElSyncEntrante(): void
    {
        // Las filas tipo 'escritura_estado' no cuentan para el throttle del sync entrante.
        $this->transport->encolarOk(200, ['success' => true]);
        $hab = (new HabitacionService())->obtener(
            (int) Database::fetchOne("SELECT id FROM habitaciones WHERE numero='101'")['id']
        );
        $this->sync->escribirEstadoClean($hab);

        $this->assertTrue($this->sync->debeCorrerSyncAutomatica(30));
    }

    public function testIntervaloLeeConfigConDefault(): void
    {
        // Sin clave → default 30.
        $this->assertSame(30, $this->sync->intervaloSyncMinutos());

        Database::execute("INSERT INTO cloudbeds_config (clave, valor) VALUES ('sync_intervalo_minutos', '15')");
        $this->assertSame(15, $this->sync->intervaloSyncMinutos());

        // Valor no numérico → default; valor menor a 1 → clamp a 1.
        Database::execute("UPDATE cloudbeds_config SET valor = 'abc' WHERE clave = 'sync_intervalo_minutos'");
        $this->assertSame(30, $this->sync->intervaloSyncMinutos());
        Database::execute("UPDATE cloudbeds_config SET valor = '0' WHERE clave = 'sync_intervalo_minutos'");
        $this->assertSame(1, $this->sync->intervaloSyncMinutos());
    }

    public function testEstadoActualYHistorial(): void
    {
        $this->transport->encolarOk(200, ['success' => true, 'data' => []]);
        $this->sync->sincronizar(null, 'manual');

        $actual = $this->sync->estadoActual();
        $this->assertNotNull($actual);
        $this->assertSame('exito', $actual['resultado']);

        $historial = $this->sync->historial(10);
        $this->assertCount(1, $historial);
    }

    // ── No deshacer la aprobación del día cuando el 'dirty' viene de un check-in ──
    // Incidente real del 22/09/2026 (pieza 706): aprobada a las 11:15, Cloudbeds aceptó el
    // 'clean', entró un huésped, y 25 min después el sync la devolvió a sucia. La limpiaron
    // dos veces. Ese día le pasó a ~8 piezas.

    /** El caso roto: pieza aprobada hoy, huésped adentro. NO se toca. */
    public function testNoDeshaceLaAprobacionDelDiaSiLaPiezaEstaOcupada(): void
    {
        $this->transport->encolarOk(200, [
            'success' => true,
            'data' => [
                ['roomID' => 'CB_R101', 'roomCondition' => 'dirty', 'frontdeskStatus' => 'check-in', 'roomOccupied' => true],
            ],
        ]);

        $this->sync->sincronizar(null, 'manual');

        $r101 = Database::fetchOne("SELECT estado FROM habitaciones WHERE numero='101'");
        $this->assertSame('aprobada', $r101['estado'], 'La aprobación del día no debe deshacerse con el huésped adentro');

        $alertas = Database::fetchAll("SELECT * FROM alertas_activas WHERE tipo = 'aprobacion_deshecha'");
        $this->assertSame([], $alertas, 'Si no se deshizo nada, no hay que alertar');
    }

    /** El caso legítimo: se fue el huésped, la pieza hay que rehacerla de verdad. */
    public function testSiDeshaceLaAprobacionCuandoLaPiezaQuedoDesocupada(): void
    {
        $this->transport->encolarOk(200, [
            'success' => true,
            'data' => [
                ['roomID' => 'CB_R101', 'roomCondition' => 'dirty', 'frontdeskStatus' => 'check-out', 'roomOccupied' => false],
            ],
        ]);

        $this->sync->sincronizar(null, 'manual');

        $r101 = Database::fetchOne("SELECT estado FROM habitaciones WHERE numero='101'");
        $this->assertSame('sucia', $r101['estado'], 'Tras el check-out la re-limpieza es legítima');
    }

    /** Ciclo normal: la aprobación es de otro día, se revierte aunque esté ocupada. */
    public function testDeshaceLaAprobacionDeOtroDiaAunqueEsteOcupada(): void
    {
        // updated_at de anteayer en UTC: sea cual sea el desfase con Chile, no es hoy.
        Database::execute(
            "UPDATE habitaciones SET updated_at = ? WHERE numero = '101'",
            [gmdate('Y-m-d\TH:i:s.000\Z', time() - 2 * 86400)]
        );

        $this->transport->encolarOk(200, [
            'success' => true,
            'data' => [
                ['roomID' => 'CB_R101', 'roomCondition' => 'dirty', 'frontdeskStatus' => 'stayover', 'roomOccupied' => true],
            ],
        ]);

        $this->sync->sincronizar(null, 'manual');

        $r101 = Database::fetchOne("SELECT estado FROM habitaciones WHERE numero='101'");
        $this->assertSame('sucia', $r101['estado'], 'El aseo del día siguiente tiene que seguir entrando a la cola');
    }

    /** Deshacer una aprobación ya no es mudo: queda alerta para la supervisora. */
    public function testAlDeshacerUnaAprobacionLevantaAlertaParaLaSupervisora(): void
    {
        $this->transport->encolarOk(200, [
            'success' => true,
            'data' => [
                ['roomID' => 'CB_R101', 'roomCondition' => 'dirty', 'frontdeskStatus' => 'check-out', 'roomOccupied' => false],
            ],
        ]);

        $this->sync->sincronizar(null, 'manual');

        $alerta = Database::fetchOne("SELECT * FROM alertas_activas WHERE tipo = 'aprobacion_deshecha'");
        $this->assertNotNull($alerta, 'La supervisora tiene que enterarse de que alguien va a limpiar de nuevo');
        $this->assertSame(1, (int) $alerta['prioridad']);
        $this->assertStringContainsString('101', (string) $alerta['titulo']);
        $this->assertSame($this->hotel1SurId, (int) $alerta['hotel_id']);
    }

    // ── Regresiones del incidente del 23/09/2026 ──────────────────────────────
    // Ver docs/incidente-2026-09-23.md

    /**
     * El cierre de día deja la pieza en 'aprobada_automatica' —la marca de que nadie la
     * inspeccionó—. El sync la re-aprobaba a 'aprobada' en el tick siguiente (49 piezas ese
     * día): borraba la marca, así que los KPIs de cobertura la contaban como inspeccionada.
     */
    public function testNoReApruebaLaPiezaQueCerroElCierreDeDia(): void
    {
        Database::execute("UPDATE habitaciones SET estado = 'aprobada_automatica' WHERE numero = '101'");

        $this->transport->encolarOk(200, [
            'success' => true,
            'data' => [
                ['roomID' => 'CB_R101', 'roomCondition' => 'clean', 'frontdeskStatus' => 'stayover', 'roomOccupied' => true],
            ],
        ]);

        $this->sync->sincronizar(null, 'manual');

        $r101 = Database::fetchOne("SELECT estado FROM habitaciones WHERE numero='101'");
        $this->assertSame(
            'aprobada_automatica',
            $r101['estado'],
            'La marca de "la aprobó la máquina" tiene que sobrevivir al sync'
        );
    }

    /**
     * 'turnover' = se va un huésped y entra otro el mismo día. Cloudbeds la reporta ocupada,
     * pero es justo cuando hay que limpiarla. Le pasó a las piezas 710 y 107, conservadas
     * todo el 23/09/2026.
     */
    public function testDeshaceLaAprobacionEnUnTurnoverAunqueCloudbedsLaReporteOcupada(): void
    {
        $this->transport->encolarOk(200, [
            'success' => true,
            'data' => [
                ['roomID' => 'CB_R101', 'roomCondition' => 'dirty', 'frontdeskStatus' => 'turnover', 'roomOccupied' => true],
            ],
        ]);

        $this->sync->sincronizar(null, 'manual');

        $r101 = Database::fetchOne("SELECT estado FROM habitaciones WHERE numero='101'");
        $this->assertSame('sucia', $r101['estado'], 'Entre un huésped y el siguiente hay que limpiar');
    }

    /**
     * La regla se llama "conservar la APROBACIÓN", pero el guard de afuera pregunta por
     * estado terminal, que también abarca 'rechazada'. A una pieza rechazada no la aprobó
     * nadie: tiene que volver a la cola aunque haya alguien adentro.
     */
    public function testNoConservaUnaPiezaRechazadaAunqueEsteOcupada(): void
    {
        Database::execute("UPDATE habitaciones SET estado = 'rechazada' WHERE numero = '101'");

        $this->transport->encolarOk(200, [
            'success' => true,
            'data' => [
                ['roomID' => 'CB_R101', 'roomCondition' => 'dirty', 'frontdeskStatus' => 'check-in', 'roomOccupied' => true],
            ],
        ]);

        $this->sync->sincronizar(null, 'manual');

        $r101 = Database::fetchOne("SELECT estado FROM habitaciones WHERE numero='101'");
        $this->assertSame('sucia', $r101['estado'], 'Una pieza rechazada hay que rehacerla igual');
    }
}
