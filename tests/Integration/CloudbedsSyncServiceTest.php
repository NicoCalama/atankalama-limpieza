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
        // La 101 se aprobó hoy: el «cuándo» sale del audit_log, como en producción.
        $this->registrarCambioDeEstado('101', 'aprobada', 0);

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

    public function testSincronizarGuardaLaCantidadDeHuespedesComoFlexkeeping(): void
    {
        // Lo que mostraba Flexkeeping en la pieza: los huéspedes de la reserva actual y los
        // que llegan ese mismo día. Sale de getReservations (getHousekeepingStatus no lo trae).
        $hoy = date('Y-m-d');
        $this->transport->encolarOk(200, [
            'success' => true,
            'data' => [
                ['roomID' => 'CB_R101', 'roomCondition' => 'clean', 'frontdeskStatus' => 'stayover', 'roomOccupied' => true],
                ['roomID' => 'CB_R102', 'roomCondition' => 'dirty', 'frontdeskStatus' => 'turnover', 'roomOccupied' => false],
            ],
        ]);
        $this->transport->encolarOk(200, ['success' => true, 'data' => []]); // getReservationAssignments
        $this->transport->encolarOk(200, [
            'success' => true,
            'total' => 3,
            'data' => [
                // 101: sigue alojado (2 adultos).
                ['status' => 'checked_in', 'rooms' => [
                    ['roomID' => 'CB_R101', 'adults' => '2', 'children' => '0', 'roomStatus' => 'in_house', 'roomCheckIn' => '2026-09-20', 'roomCheckOut' => '2099-01-01'],
                    // Cancelada en la misma pieza: no suma.
                    ['roomID' => 'CB_R101', 'adults' => '5', 'children' => '0', 'roomStatus' => 'cancelled', 'roomCheckIn' => $hoy, 'roomCheckOut' => '2099-01-01'],
                ]],
                // 102: salieron hoy 1 adulto + 1 niño...
                ['status' => 'checked_out', 'rooms' => [
                    ['roomID' => 'CB_R102', 'adults' => '1', 'children' => '1', 'roomStatus' => 'checked_out', 'roomCheckIn' => '2026-09-20', 'roomCheckOut' => $hoy],
                ]],
                // ...y llegan 3 hoy. Más una reserva vieja «en casa» sin pieza asignada (roomID
                // vacío), como las que Cloudbeds arrastra desde 2022: se ignora.
                ['status' => 'confirmed', 'rooms' => [
                    ['roomID' => 'CB_R102', 'adults' => '3', 'children' => '0', 'roomStatus' => 'not_checked_in', 'roomCheckIn' => $hoy, 'roomCheckOut' => '2099-01-01'],
                    ['roomID' => '', 'adults' => '1', 'children' => '0', 'roomStatus' => 'in_house', 'roomCheckIn' => '2022-12-05', 'roomCheckOut' => '2022-12-06'],
                ]],
            ],
        ]);

        $this->sync->sincronizar(null, 'manual');

        // La consulta del día: una sola, con las fechas de hoy.
        $this->assertStringContainsString('/getReservations?', $this->transport->peticiones[2]['url']);
        $this->assertStringContainsString('checkInTo=' . $hoy, $this->transport->peticiones[2]['url']);

        $r101 = Database::fetchOne("SELECT cb_huespedes, cb_huespedes_llegan FROM habitaciones WHERE numero='101'");
        $this->assertSame(2, (int) $r101['cb_huespedes']);
        $this->assertNull($r101['cb_huespedes_llegan']);

        $r102 = Database::fetchOne("SELECT cb_huespedes, cb_huespedes_llegan FROM habitaciones WHERE numero='102'");
        $this->assertSame(2, (int) $r102['cb_huespedes'], 'ya no queda nadie adentro: cuentan los que salieron hoy');
        $this->assertSame(3, (int) $r102['cb_huespedes_llegan']);
    }

    public function testSiGetReservationsFallaElSyncSigueYLaCantidadQuedaVacia(): void
    {
        // Dato secundario: si Cloudbeds no responde esta consulta, el sync de limpieza sigue
        // igual y la pieza queda sin número (mejor que mostrar uno de otra hora).
        Database::execute("UPDATE habitaciones SET cb_huespedes = 2, cb_huespedes_llegan = 1 WHERE numero = '101'");
        $this->transport->encolarOk(200, [
            'success' => true,
            'data' => [['roomID' => 'CB_R101', 'roomCondition' => 'clean', 'frontdeskStatus' => 'stayover', 'roomOccupied' => true]],
        ]);
        $this->transport->encolarOk(200, ['success' => true, 'data' => []]); // getReservationAssignments
        // getReservations: sin respuestas programadas → falla de red en todos los intentos.

        $syncId = $this->sync->sincronizar(null, 'manual');

        $hist = Database::fetchOne('SELECT resultado FROM cloudbeds_sync_historial WHERE id = ?', [$syncId]);
        $this->assertSame('exito', $hist['resultado']);
        $r101 = Database::fetchOne("SELECT cb_frontdesk_status, cb_huespedes, cb_huespedes_llegan FROM habitaciones WHERE numero='101'");
        $this->assertSame('stayover', $r101['cb_frontdesk_status'], 'la ocupación se guarda igual');
        $this->assertNull($r101['cb_huespedes']);
        $this->assertNull($r101['cb_huespedes_llegan']);
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
        // Aprobada anteayer en UTC: sea cual sea el desfase con Chile, no es hoy.
        $this->envejecerCambiosDeEstado('101', 2);

        $this->transport->encolarOk(200, [
            'success' => true,
            'data' => [
                ['roomID' => 'CB_R101', 'roomCondition' => 'dirty', 'frontdeskStatus' => 'stayover', 'roomOccupied' => true],
            ],
        ]);

        $this->sync->sincronizar(null, 'manual');

        $r101 = Database::fetchOne("SELECT estado FROM habitaciones WHERE numero='101'");
        $this->assertSame('sucia', $r101['estado'], 'El aseo del día siguiente tiene que seguir entrando a la cola');

        // Y es el ciclo normal, no una noticia: así entra cada mañana el aseo del día.
        $alertas = Database::fetchAll("SELECT * FROM alertas_activas WHERE tipo = 'aprobacion_deshecha'");
        $this->assertSame([], $alertas, 'El ciclo normal no puede levantar una alerta por pieza');
    }

    // ── Alerta «aprobación deshecha»: solo lo que es noticia ─────────────────────
    // En producción nunca se llegó a guardar (el CHECK de alertas_activas.tipo no tenía el
    // tipo), y eso tapó que se levantaba por CUALQUIER pieza que volvía a sucia: ~140 por día
    // entre el 24 y el 27/09/2026 según logs_eventos. Ver docs/incidente-2026-09-23.md.

    /**
     * El caso más común en producción: el cierre de día de anoche la aprobó sola y Cloudbeds
     * la marca sucia hoy. Vuelve a la cola sin alertar.
     */
    public function testLaAprobacionAutomaticaDeAyerVuelveALaColaSinAlerta(): void
    {
        Database::execute("UPDATE habitaciones SET estado = 'aprobada_automatica' WHERE numero = '101'");
        $this->envejecerCambiosDeEstado('101', 2);
        $this->transport->encolarOk(200, [
            'success' => true,
            'data' => [
                ['roomID' => 'CB_R101', 'roomCondition' => 'dirty', 'frontdeskStatus' => 'check-out', 'roomOccupied' => false],
            ],
        ]);

        $this->sync->sincronizar(null, 'manual');

        $r101 = Database::fetchOne("SELECT estado FROM habitaciones WHERE numero='101'");
        $this->assertSame('sucia', $r101['estado']);
        $this->assertSame([], Database::fetchAll("SELECT * FROM alertas_activas WHERE tipo = 'aprobacion_deshecha'"));
    }

    /**
     * «¿Se aprobó hoy?» no puede salir de updated_at: también lo mueven la nota de Recepción,
     * marcar nochero o editar la estructura, sin cambiar el estado. Hasta la revisión de la
     * v6.15, una pieza aprobada ayer con una nota de hoy pasaba por «aprobada hoy»: alertaba
     * con un texto falso, o se conservaba en vez de volver a la cola.
     */
    public function testUnaNotaDeHoyNoConvierteUnaAprobacionDeAyerEnDeHoy(): void
    {
        Database::execute("UPDATE habitaciones SET estado = 'aprobada_automatica' WHERE numero = '101'");
        $this->envejecerCambiosDeEstado('101', 2);
        $id = (int) Database::fetchOne("SELECT id FROM habitaciones WHERE numero='101'")['id'];
        [$recepcionista] = TestDatabase::crearUsuario('44444444-4', 'Carla', 'Recepción');
        (new HabitacionService())->agregarNota($id, 'Cama extra para el que llega', $recepcionista);
        $this->assertFalse((new HabitacionService())->cambioDeEstadoHoy($id), 'Una nota no es un cambio de estado');

        // 'stayover' (el aseo diario del que sigue): una aprobación de HOY se conservaría; la de
        // otro día tiene que volver a la cola. Desde la v6.20 la llegada ('check-in') sobre una
        // aprobación anterior SÍ se conserva, así que ya no sirve para distinguir.
        $this->transport->encolarOk(200, [
            'success' => true,
            'data' => [
                ['roomID' => 'CB_R101', 'roomCondition' => 'dirty', 'frontdeskStatus' => 'stayover', 'roomOccupied' => true],
            ],
        ]);
        $this->sync->sincronizar(null, 'manual');

        $r101 = Database::fetchOne("SELECT estado FROM habitaciones WHERE numero='101'");
        $this->assertSame('sucia', $r101['estado'], 'Aprobación de otro día: el aseo del día vuelve a la cola aunque haya una nota de hoy');
        $this->assertSame([], Database::fetchAll("SELECT * FROM alertas_activas WHERE tipo = 'aprobacion_deshecha'"));
    }

    /** Una rechazada que vuelve a sucia no es una aprobación deshecha: a esa no la aprobó nadie. */
    public function testUnaRechazadaQueVuelveASuciaNoLevantaLaAlerta(): void
    {
        Database::execute("UPDATE habitaciones SET estado = 'rechazada' WHERE numero = '101'");
        $this->transport->encolarOk(200, [
            'success' => true,
            'data' => [
                ['roomID' => 'CB_R101', 'roomCondition' => 'dirty', 'frontdeskStatus' => 'check-out', 'roomOccupied' => false],
            ],
        ]);

        $this->sync->sincronizar(null, 'manual');

        $r101 = Database::fetchOne("SELECT estado FROM habitaciones WHERE numero='101'");
        $this->assertSame('sucia', $r101['estado'], 'La rechazada vuelve a la cola igual (v6.11)');
        $this->assertSame([], Database::fetchAll("SELECT * FROM alertas_activas WHERE tipo = 'aprobacion_deshecha'"));
    }

    /**
     * La alerta dura lo que dura la condición: cuando la pieza vuelve a quedar aprobada, se
     * resuelve sola y queda cerrada en la bitácora. Si no, cada pieza deshecha dejaba una
     * alerta P1 colgada para siempre en el Inicio de la supervisora.
     */
    public function testLaAlertaSeResuelveSolaCuandoLaPiezaVuelveAQuedarAprobada(): void
    {
        $this->transport->encolarOk(200, [
            'success' => true,
            'data' => [
                ['roomID' => 'CB_R101', 'roomCondition' => 'dirty', 'frontdeskStatus' => 'check-out', 'roomOccupied' => false],
            ],
        ]);
        $this->sync->sincronizar(null, 'manual');
        $this->assertNotNull(Database::fetchOne("SELECT id FROM alertas_activas WHERE tipo = 'aprobacion_deshecha'"));

        // Cualquier camino que apruebe pasa por cambiarEstado(): acá, el cierre de día.
        $id = (int) Database::fetchOne("SELECT id FROM habitaciones WHERE numero='101'")['id'];
        (new HabitacionService())->cambiarEstado($id, 'aprobada_automatica', null, 'cron', forzar: true);

        $this->assertSame([], Database::fetchAll("SELECT * FROM alertas_activas WHERE tipo = 'aprobacion_deshecha'"));
        $bitacora = Database::fetchOne("SELECT * FROM bitacora_alertas WHERE tipo = 'aprobacion_deshecha'");
        $this->assertNotNull($bitacora['resuelta_at'], 'La bitácora tiene que quedar cerrada');
        $this->assertSame('auto', $bitacora['resolucion']);
    }

    /** Resolver la de una pieza no toca la alerta de otra (dedupe por pieza). */
    public function testAprobarUnaPiezaNoResuelveLaAlertaDeOtra(): void
    {
        Database::execute("UPDATE habitaciones SET estado = 'aprobada' WHERE numero = '102'");
        $this->registrarCambioDeEstado('102', 'aprobada', 0);
        $this->transport->encolarOk(200, [
            'success' => true,
            'data' => [
                ['roomID' => 'CB_R101', 'roomCondition' => 'dirty', 'frontdeskStatus' => 'check-out', 'roomOccupied' => false],
                ['roomID' => 'CB_R102', 'roomCondition' => 'dirty', 'frontdeskStatus' => 'check-out', 'roomOccupied' => false],
            ],
        ]);
        $this->sync->sincronizar(null, 'manual');
        $this->assertCount(2, Database::fetchAll("SELECT * FROM alertas_activas WHERE tipo = 'aprobacion_deshecha'"));

        $id101 = (int) Database::fetchOne("SELECT id FROM habitaciones WHERE numero='101'")['id'];
        (new HabitacionService())->cambiarEstado($id101, 'aprobada', null, 'cron', forzar: true);

        $quedan = Database::fetchAll("SELECT titulo FROM alertas_activas WHERE tipo = 'aprobacion_deshecha'");
        $this->assertCount(1, $quedan);
        $this->assertStringContainsString('102', (string) $quedan[0]['titulo']);
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

    // ── R1 (v6.20): llegadas y cambios de huésped que no tienen que volver a la cola ─────────────
    // Pedido de la supervisora del 08/10/2026: el turnover ya limpiado volvía a la cola de la misma
    // trabajadora al llegar el huésped nuevo; y decisión de Nicolás del mismo día sobre la pieza
    // aprobada un día anterior que recibe huésped hoy.

    /** Se limpió con la pieza vacía, entre un huésped y otro: al llegar el nuevo, sigue aprobada. */
    public function testConservaUnTurnoverQueSeLimpioConLaPiezaVacia(): void
    {
        $this->terminarYAprobarHoy('101', ocupadaAlTerminar: 0);
        $this->encolarHousekeeping('101', 'dirty', 'turnover', true);

        $this->sync->sincronizar(null, 'manual');

        $this->assertSame('aprobada', $this->estado('101'), 'El turnover limpiado entre huéspedes no se limpia dos veces');
        $this->assertSame([], Database::fetchAll("SELECT * FROM alertas_activas WHERE tipo = 'aprobacion_deshecha'"));
    }

    /** La anotación queda en el historial al terminar la limpieza, con lo que veía Cloudbeds. */
    public function testAlTerminarLaLimpiezaQuedaAnotadaLaOcupacionDeCloudbeds(): void
    {
        $this->terminarYAprobarHoy('101', ocupadaAlTerminar: 0);

        $detalles = json_decode((string) Database::fetchColumn(
            "SELECT detalles_json FROM audit_log WHERE accion = 'habitacion.cambiar_estado' AND detalles_json LIKE '%\"hasta\":\"completada_pendiente_auditoria\"%' ORDER BY id DESC LIMIT 1"
        ), true);
        $this->assertSame(0, $detalles['cb_ocupada']);
        $this->assertSame('turnover', $detalles['cb_frontdesk']);
        $this->assertNotEmpty($detalles['cb_leida_at']);
    }

    /** Se limpió con el huésped anterior adentro: al irse, la pieza necesita aseo de verdad. */
    public function testUnTurnoverLimpiadoConElHuespedAdentroVuelveALaCola(): void
    {
        $this->terminarYAprobarHoy('101', ocupadaAlTerminar: 1);
        $this->encolarHousekeeping('101', 'dirty', 'turnover', true);

        $this->sync->sincronizar(null, 'manual');

        $this->assertSame('sucia', $this->estado('101'));
    }

    /** Limpiado vacío pero el «sucia» llega con la pieza todavía vacía (lo marcó Recepción): se respeta. */
    public function testUnTurnoverLimpiadoVacioQueSeEnsuciaSinHuespedVuelveALaCola(): void
    {
        $this->terminarYAprobarHoy('101', ocupadaAlTerminar: 0);
        $this->encolarHousekeeping('101', 'dirty', 'turnover', false);

        $this->sync->sincronizar(null, 'manual');

        $this->assertSame('sucia', $this->estado('101'));
    }

    /** Decisión de Nicolás: aprobada un día anterior y hoy llega un huésped → aprobada hasta mañana. */
    public function testLaPiezaAprobadaAyerQueRecibeHuespedHoyQuedaAprobada(): void
    {
        $this->envejecerCambiosDeEstado('101', 2);
        $this->encolarHousekeeping('101', 'dirty', 'check-in', true);

        $this->sync->sincronizar(null, 'manual');

        $this->assertSame('aprobada', $this->estado('101'), 'Estaba limpia y vacía cuando llegó el huésped');
        $this->assertSame([], Database::fetchAll("SELECT * FROM alertas_activas WHERE tipo = 'aprobacion_deshecha'"));
    }

    /** Antes de que llegue el huésped, un «sucia» de Recepción sobre la pieza vacía se respeta. */
    public function testLaPiezaAprobadaAyerQueSeEnsuciaAntesDeLaLlegadaVuelveALaCola(): void
    {
        $this->envejecerCambiosDeEstado('101', 2);
        $this->encolarHousekeeping('101', 'dirty', 'check-in', false);

        $this->sync->sincronizar(null, 'manual');

        $this->assertSame('sucia', $this->estado('101'));
    }

    /**
     * La aprobada por el cierre de la noche porque nadie terminó la limpieza (v6.19) no se da por
     * limpia: si llega un huésped, vuelve a la cola.
     */
    public function testNoConservaLaLlegadaSiLaUltimaLimpiezaLaCerroElSistema(): void
    {
        TestDatabase::sembrarChecklistTemplates();
        [$ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        $hab = (int) Database::fetchColumn("SELECT id FROM habitaciones WHERE numero = '101'");
        Database::execute('INSERT INTO asignaciones (habitacion_id, usuario_id, fecha, activa) VALUES (?, ?, ?, 1)', [$hab, $ana, date('Y-m-d', strtotime('-1 day'))]);
        $asignacion = Database::lastInsertId();
        Database::execute(
            "INSERT INTO ejecuciones_checklist (habitacion_id, asignacion_id, usuario_id, template_id, estado, timestamp_fin, cerrada_por_sistema)
             VALUES (?, ?, ?, (SELECT MIN(id) FROM checklists_template), 'auditada', ?, 1)",
            [$hab, $asignacion, $ana, gmdate('Y-m-d\TH:i:s.000\Z', time() - 86400)]
        );
        $this->envejecerCambiosDeEstado('101', 2);
        $this->encolarHousekeeping('101', 'dirty', 'check-in', true);

        $this->sync->sincronizar(null, 'manual');

        $this->assertSame('sucia', $this->estado('101'));
    }

    /**
     * Lleva la pieza por el camino real de hoy: en progreso → terminada (con la ocupación que tenía
     * Cloudbeds en ese momento) → aprobada, todo por HabitacionService::cambiarEstado().
     */
    private function terminarYAprobarHoy(string $numero, int $ocupadaAlTerminar): void
    {
        $id = (int) Database::fetchColumn('SELECT id FROM habitaciones WHERE numero = ?', [$numero]);
        Database::execute('DELETE FROM audit_log WHERE entidad = ? AND entidad_id = ?', ['habitacion', $id]);
        Database::execute(
            "UPDATE habitaciones SET estado = 'en_progreso', cb_ocupada = ?, cb_frontdesk_status = 'turnover', cb_ocupacion_sync_at = ? WHERE id = ?",
            [$ocupadaAlTerminar, gmdate('Y-m-d\TH:i:s.000\Z'), $id]
        );
        $habitaciones = new HabitacionService();
        $habitaciones->cambiarEstado($id, 'completada_pendiente_auditoria', null, 'ui');
        $habitaciones->cambiarEstado($id, 'aprobada', null, 'ui');
        // Un par de segundos antes de la lectura del sync: en el mismo milisegundo, el sync la
        // trataría como «cambió después de leer Cloudbeds» y la saltaría (piezaCambioTrasLeer).
        Database::execute(
            "UPDATE audit_log SET created_at = ? WHERE entidad = 'habitacion' AND entidad_id = ? AND accion = 'habitacion.cambiar_estado'",
            [gmdate('Y-m-d\TH:i:s.000\Z', time() - 2), $id]
        );
    }

    private function encolarHousekeeping(string $numero, string $condicion, string $frontdesk, bool $ocupada): void
    {
        $this->transport->encolarOk(200, [
            'success' => true,
            'data' => [
                ['roomID' => 'CB_R' . $numero, 'roomCondition' => $condicion, 'frontdeskStatus' => $frontdesk, 'roomOccupied' => $ocupada],
            ],
        ]);
    }

    private function estado(string $numero): string
    {
        return (string) Database::fetchColumn('SELECT estado FROM habitaciones WHERE numero = ?', [$numero]);
    }

    /**
     * Deja en audit_log el cambio de estado que en producción escribe cambiarEstado(): de ahí
     * sale «¿se aprobó hoy?» (HabitacionService::cambioDeEstadoHoy).
     */
    private function registrarCambioDeEstado(string $numero, string $hasta, int $haceDias): void
    {
        $id = (int) Database::fetchOne('SELECT id FROM habitaciones WHERE numero = ?', [$numero])['id'];
        Database::execute(
            "INSERT INTO audit_log (usuario_id, accion, entidad, entidad_id, detalles_json, origen, created_at)
             VALUES (NULL, 'habitacion.cambiar_estado', 'habitacion', ?, ?, 'ui', ?)",
            // Un segundo antes: con la hora redondeada al segundo, en Windows el reloj de SQLite (el de
            // la lectura del sync) puede ir unos ms atrás y el sync tomaría la pieza como cambiada
            // después de leer Cloudbeds (test intermitente).
            [$id, json_encode(['desde' => 'completada_pendiente_auditoria', 'hasta' => $hasta]), gmdate('Y-m-d\TH:i:s.000\Z', time() - $haceDias * 86400 - 1)]
        );
    }

    /** Corre hacia atrás todos los cambios de estado de la pieza (UTC: anteayer nunca es hoy en Chile). */
    private function envejecerCambiosDeEstado(string $numero, int $dias): void
    {
        $id = (int) Database::fetchOne('SELECT id FROM habitaciones WHERE numero = ?', [$numero])['id'];
        Database::execute(
            "UPDATE audit_log SET created_at = ? WHERE entidad = 'habitacion' AND entidad_id = ? AND accion = 'habitacion.cambiar_estado'",
            [gmdate('Y-m-d\TH:i:s.000\Z', time() - $dias * 86400), $id]
        );
    }
}
