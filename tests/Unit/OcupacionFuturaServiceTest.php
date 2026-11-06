<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\OcupacionFuturaService;
use Atankalama\Limpieza\Tests\Support\FakeHttpTransport;
use PHPUnit\Framework\TestCase;

/**
 * Qué pasa en cada pieza un día futuro según las reservas de Cloudbeds (v7.15): la clasificación
 * (se va / cambio / sigue / llega) y la cache de 10 minutos. Ver docs/asignacion.md.
 */
final class OcupacionFuturaServiceTest extends TestCase
{
    private const DIA = '2026-11-08';

    private string $dirCache;

    protected function setUp(): void
    {
        $this->dirCache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ocupacion-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dirCache . DIRECTORY_SEPARATOR . '*') ?: [] as $archivo) {
            @unlink($archivo);
        }
        @rmdir($this->dirCache);
    }

    /** Una reserva de getReservations con una pieza. */
    private static function reserva(string $roomId, string $entra, string $sale, string $roomStatus = 'not_checked_in', string $status = 'confirmed'): array
    {
        return [
            'status' => $status,
            'rooms' => [[
                'roomID' => $roomId,
                'roomCheckIn' => $entra,
                'roomCheckOut' => $sale,
                'roomStatus' => $roomStatus,
                'adults' => 1,
                'children' => 0,
            ]],
        ];
    }

    public function testClasificaSeVaCambioSigueYLlega(): void
    {
        $piezas = OcupacionFuturaService::clasificar([
            self::reserva('A', '2026-11-03', '2026-11-12', 'in_house', 'checked_in'),      // sigue
            self::reserva('B', '2026-11-05', self::DIA, 'in_house', 'checked_in'),          // se va
            self::reserva('C', '2026-11-04', self::DIA, 'in_house', 'checked_in'),          // sale uno…
            self::reserva('C', self::DIA, '2026-11-10'),                                    // …y llega otro: cambio
            self::reserva('D', self::DIA, '2026-11-09'),                                    // solo llega
        ], self::DIA);

        $this->assertSame(['tipo' => 'sigue', 'llegada' => '2026-11-03'], $piezas['A']);
        $this->assertSame(['tipo' => 'se_va', 'llegada' => null], $piezas['B']);
        $this->assertSame(['tipo' => 'cambio', 'llegada' => null], $piezas['C']);
        $this->assertSame(['tipo' => 'llega', 'llegada' => null], $piezas['D']);
        $this->assertCount(4, $piezas);
    }

    public function testIgnoraCanceladasNoShowYaSalidasYSinPieza(): void
    {
        $piezas = OcupacionFuturaService::clasificar([
            self::reserva('A', '2026-11-03', '2026-11-12', 'cancelled'),
            self::reserva('B', '2026-11-03', '2026-11-12', 'not_checked_in', 'canceled'),
            self::reserva('C', '2026-11-03', '2026-11-12', 'not_checked_in', 'no_show'),
            self::reserva('D', '2026-11-03', '2026-11-12', 'checked_out', 'checked_out'),
            self::reserva('', '2026-11-03', '2026-11-12'),
            self::reserva('E', '', '2026-11-12'),
            ['status' => 'confirmed'],
            ['status' => 'confirmed', 'rooms' => ['no es una pieza']],
        ], self::DIA);

        $this->assertSame([], $piezas);
    }

    public function testElDiaDeSalidaNoEsSigueYAceptaFechasConHora(): void
    {
        $piezas = OcupacionFuturaService::clasificar([
            self::reserva('A', '2026-11-01 15:00:00', '2026-11-08 12:00:00', 'in_house', 'checked_in'),
            self::reserva('B', '2026-11-07', '2026-11-09'),
        ], self::DIA);

        $this->assertSame('se_va', $piezas['A']['tipo']);
        $this->assertSame(['tipo' => 'sigue', 'llegada' => '2026-11-07'], $piezas['B']);
    }

    public function testRoomIdNumericoSigueSiendoTexto(): void
    {
        $piezas = OcupacionFuturaService::clasificar([self::reserva('12345', '2026-11-03', '2026-11-12')], self::DIA);

        $this->assertSame(['12345'], array_map('strval', array_keys($piezas)));
        $this->assertSame('sigue', $piezas['12345']['tipo']);
    }

    private function servicio(FakeHttpTransport $http, int &$ahora): OcupacionFuturaService
    {
        $cliente = new CloudbedsClient(transport: $http, baseUrl: 'https://cb.test', apiKey: 'k', backoffs: [], dormir: static fn(int $s) => null);
        return new OcupacionFuturaService($cliente, $this->dirCache, static function () use (&$ahora): int {
            return $ahora;
        });
    }

    private static function respuesta(array $reservas): array
    {
        return ['success' => true, 'data' => $reservas, 'count' => count($reservas), 'total' => count($reservas)];
    }

    public function testGuardaLaRespuestaDiezMinutos(): void
    {
        $http = new FakeHttpTransport();
        $http->encolarOk(200, self::respuesta([self::reserva('A', '2026-11-03', '2026-11-12')]));
        $http->encolarOk(200, self::respuesta([self::reserva('A', '2026-11-03', self::DIA)]));
        $ahora = 1_000_000;
        $svc = $this->servicio($http, $ahora);

        $this->assertSame('sigue', $svc->piezasDelDia('P1', self::DIA)['A']['tipo'] ?? null);
        $ahora += OcupacionFuturaService::SEGUNDOS_CACHE - 1;
        $this->assertSame('sigue', $svc->piezasDelDia('P1', self::DIA)['A']['tipo'] ?? null);
        $this->assertCount(1, $http->peticiones, 'la segunda vez sale de la cache');

        $ahora += 1;
        $this->assertSame('se_va', $svc->piezasDelDia('P1', self::DIA)['A']['tipo'] ?? null, 'pasados 10 minutos vuelve a preguntar');
        $this->assertCount(2, $http->peticiones);
        $this->assertStringContainsString('checkInTo=' . self::DIA, $http->peticiones[0]['url']);
        $this->assertStringContainsString('checkOutFrom=' . self::DIA, $http->peticiones[0]['url']);
    }

    public function testSiCloudbedsFallaDevuelveNullSinReintentarNiGuardar(): void
    {
        $http = new FakeHttpTransport();
        $http->encolarFallo(503);
        $http->encolarOk(200, self::respuesta([]));
        $ahora = 1_000_000;
        $svc = $this->servicio($http, $ahora);

        $this->assertNull($svc->piezasDelDia('P1', self::DIA));
        $this->assertCount(1, $http->peticiones, 'sin reintentos: la pantalla está esperando');
        $this->assertSame([], $svc->piezasDelDia('P1', self::DIA), 'el fallo no queda guardado');
        $this->assertCount(2, $http->peticiones);
    }

    public function testSinSuccessDevuelveNull(): void
    {
        $http = new FakeHttpTransport();
        $http->encolarOk(200, ['success' => false, 'message' => 'Access denied']);
        $ahora = 1_000_000;

        $this->assertNull($this->servicio($http, $ahora)->piezasDelDia('P1', self::DIA));
    }

    public function testFechaRaraOHotelSinPropiedadNoConsultan(): void
    {
        $http = new FakeHttpTransport();
        $ahora = 1_000_000;
        $svc = $this->servicio($http, $ahora);

        $this->assertNull($svc->piezasDelDia('P1', '2026-11-08/../../x'));
        $this->assertNull($svc->piezasDelDia('', self::DIA));
        $this->assertSame([], $http->peticiones);
    }

    public function testSinCredencialDevuelveNullSinLlamar(): void
    {
        $http = new FakeHttpTransport();
        $cliente = new CloudbedsClient(transport: $http, baseUrl: 'https://cb.test', apiKey: '', backoffs: []);

        $this->assertNull((new OcupacionFuturaService($cliente, ''))->piezasDelDia('P1', self::DIA));
        $this->assertSame([], $http->peticiones);
    }
}
