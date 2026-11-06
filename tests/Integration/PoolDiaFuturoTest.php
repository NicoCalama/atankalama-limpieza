<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\Http\HttpResponse;
use Atankalama\Limpieza\Services\Http\HttpTransport;
use Atankalama\Limpieza\Services\OcupacionFuturaService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Pool «Sin asignar» de un día futuro (v7.15, decisiones de Nicolás del 06/11/2026): solo las piezas
 * que ese día necesitan limpieza según las reservas de Cloudbeds, cada una con una sola etiqueta
 * (se va / cambio / sábanas / sigue). Si Cloudbeds no contesta, ese hotel muestra todo con aviso.
 */
final class PoolDiaFuturoTest extends TestCase
{
    private string $dia;

    /** @var array<string, int> numero => id */
    private array $hab = [];

    /** @var list<string> */
    private array $urls = [];

    /** @var array<string, list<array<string, mixed>>> propertyID => reservas (lo que no está, falla) */
    private array $reservasPorPropiedad = [];

    protected function setUp(): void
    {
        TestDatabase::recrear();
        $this->dia = date('Y-m-d', strtotime('+2 days'));

        Database::execute("INSERT INTO #__hoteles (codigo, nombre, cloudbeds_property_id) VALUES ('1_sur', 'Atankalama', 'P_SUR')");
        $sur = Database::lastInsertId();
        Database::execute("INSERT INTO #__hoteles (codigo, nombre, cloudbeds_property_id) VALUES ('inn', 'Atankalama INN', 'P_INN')");
        $inn = Database::lastInsertId();
        Database::execute("INSERT INTO #__tipos_habitacion (nombre) VALUES ('Doble')");
        $tipo = Database::lastInsertId();

        // 107 no está enlazada a Cloudbeds; 108 ya está preasignada ese día.
        $piezas = [
            ['101', $sur, 'CB_101'], ['102', $sur, 'CB_102'], ['103', $sur, 'CB_103'], ['104', $sur, 'CB_104'],
            ['105', $sur, 'CB_105'], ['106', $sur, 'CB_106'], ['107', $sur, null], ['108', $sur, 'CB_108'],
            ['201', $inn, 'CB_201'],
        ];
        foreach ($piezas as [$numero, $hotel, $roomId]) {
            Database::execute(
                "INSERT INTO #__habitaciones (hotel_id, numero, tipo_habitacion_id, cloudbeds_room_id, estado, es_espacio_comun) VALUES (?, ?, ?, ?, 'aprobada', 0)",
                [$hotel, $numero, $tipo, $roomId]
            );
            $this->hab[$numero] = Database::lastInsertId();
        }

        $d = fn(int $dias): string => date('Y-m-d', strtotime("{$this->dia} {$dias} days"));
        $this->reservasPorPropiedad['P_SUR'] = [
            self::reserva('CB_101', $d(-1), $d(+3), 'in_house'),      // sigue, 1 noche: sin sábanas
            self::reserva('CB_102', $d(-4), $d(+3), 'in_house'),      // sigue, 4 noches: sábanas
            self::reserva('CB_103', $d(-2), $this->dia, 'in_house'),  // se va
            self::reserva('CB_104', $d(-3), $this->dia, 'in_house'),  // cambio: sale uno…
            self::reserva('CB_104', $this->dia, $d(+2)),              // …y llega otro
            self::reserva('CB_105', $this->dia, $d(+5)),              // solo llega
            self::reserva('CB_108', $d(-1), $d(+1), 'in_house'),      // sigue, pero ya está preasignada
        ];
        // P_INN no tiene respuesta: Cloudbeds «falla» para ese hotel.

        [$ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');
        $this->servicio()->asignarManual($this->hab['108'], $ana, $this->dia);
    }

    private static function reserva(string $roomId, string $entra, string $sale, string $roomStatus = 'not_checked_in'): array
    {
        return ['status' => 'confirmed', 'rooms' => [[
            'roomID' => $roomId, 'roomCheckIn' => $entra, 'roomCheckOut' => $sale, 'roomStatus' => $roomStatus,
        ]]];
    }

    private function servicio(): AsignacionService
    {
        $test = $this;
        $transporte = new class ($test) implements HttpTransport {
            public function __construct(private readonly PoolDiaFuturoTest $test)
            {
            }

            public function request(string $metodo, string $url, array $headers = [], ?array $cuerpo = null, int $timeoutSegundos = 10, string $contentType = 'application/json'): HttpResponse
            {
                return $this->test->responder($url);
            }
        };
        $cliente = new CloudbedsClient(transport: $transporte, baseUrl: 'https://cb.test', apiKey: 'k', backoffs: [], dormir: static fn(int $s) => null);
        return new AsignacionService(ocupacionFutura: new OcupacionFuturaService($cliente, ''));
    }

    /** Respuesta del Cloudbeds simulado según el propertyID de la URL. */
    public function responder(string $url): HttpResponse
    {
        $this->urls[] = $url;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $reservas = $this->reservasPorPropiedad[(string) ($query['propertyID'] ?? '')] ?? null;
        if ($reservas === null) {
            return new HttpResponse(503, '');
        }
        return new HttpResponse(200, (string) json_encode(['success' => true, 'data' => $reservas, 'count' => count($reservas), 'total' => count($reservas)]));
    }

    /** @return array<string, mixed> numero => fila del pool */
    private function poolPorNumero(array $vista): array
    {
        $pool = [];
        foreach ($vista['sin_asignar'] as $fila) {
            $pool[(string) $fila['numero']] = $fila;
        }
        return $pool;
    }

    public function testSoloSalenLasQueNecesitanLimpiezaConSuEtiqueta(): void
    {
        $vista = $this->servicio()->vistaConsolidada('1_sur', $this->dia);
        $pool = $this->poolPorNumero($vista);

        $this->assertSame(['101', '102', '103', '104', '107'], array_map('strval', array_keys($pool)));
        $this->assertSame('sigue', $pool['101']['tipo_dia']);
        $this->assertSame('sabanas', $pool['102']['tipo_dia']);
        $this->assertSame('se_va', $pool['103']['tipo_dia']);
        $this->assertSame('cambio', $pool['104']['tipo_dia']);
        $this->assertNull($pool['107']['tipo_dia'], 'sin enlace a Cloudbeds: sale igual, sin etiqueta');
        $this->assertSame(['hoteles_sin_dato' => []], $vista['reservas']);
        // Lo que se usa para filtrar no viaja a la pantalla.
        $this->assertArrayNotHasKey('cloudbeds_room_id', $pool['101']);
        $this->assertArrayNotHasKey('cloudbeds_property_id', $pool['101']);
        $this->assertArrayNotHasKey('sabanas_cada_n_dias', $pool['101']);
    }

    public function testLasSabanasSiguenElNDelHotel(): void
    {
        Database::execute("UPDATE #__hoteles SET sabanas_cada_n_dias = 2 WHERE codigo = '1_sur'");

        $pool = $this->poolPorNumero($this->servicio()->vistaConsolidada('1_sur', $this->dia));

        $this->assertSame('sigue', $pool['101']['tipo_dia'], '1 noche, cada 2: no toca');
        $this->assertSame('sabanas', $pool['102']['tipo_dia'], '4 noches, cada 2: toca');
    }

    public function testSiCloudbedsFallaEseHotelMuestraTodoConAviso(): void
    {
        $vista = $this->servicio()->vistaConsolidada('ambos', $this->dia);
        $pool = $this->poolPorNumero($vista);

        $this->assertArrayHasKey('201', $pool, 'el INN muestra todo');
        $this->assertNull($pool['201']['tipo_dia']);
        $this->assertArrayNotHasKey('105', $pool, 'el otro hotel sigue filtrado');
        $this->assertSame(['hoteles_sin_dato' => ['Atankalama INN']], $vista['reservas']);
        $this->assertCount(2, $this->urls, 'una consulta por hotel');
    }

    public function testLaColaDeEseDiaLlevaLaEtiquetaYMarcaLasQueNoNecesitanLimpieza(): void
    {
        $svc = $this->servicio();
        $ana = (int) Database::fetchColumn("SELECT id FROM usuarios WHERE rut = '11111111-1'");
        foreach (['103', '105', '106', '107'] as $numero) {
            $svc->asignarManual($this->hab[$numero], $ana, $this->dia);
        }

        $vista = $svc->vistaConsolidada('1_sur', $this->dia);

        $cola = [];
        foreach ($vista['trabajadores'][0]['cola'] as $fila) {
            $cola[(string) $fila['numero']] = $fila['tipo_dia'];
        }
        $this->assertSame([
            '108' => 'sigue',
            '103' => 'se_va',
            '105' => 'sin_limpieza', // solo llega
            '106' => 'sin_limpieza', // sin reserva
            '107' => null,           // no está enlazada a Cloudbeds
        ], $cola);
        $this->assertCount(1, $this->urls, 'pool y colas comparten la consulta del hotel');
    }

    public function testSiCloudbedsFallaParaUnaPiezaDeLaColaTambienAvisa(): void
    {
        $svc = $this->servicio();
        $ana = (int) Database::fetchColumn("SELECT id FROM usuarios WHERE rut = '11111111-1'");
        $svc->asignarManual($this->hab['201'], $ana, $this->dia);

        $vista = $svc->vistaConsolidada('ambos', $this->dia);

        $cola = array_column($vista['trabajadores'][0]['cola'], 'tipo_dia', 'numero');
        $this->assertNull($cola['201'], 'sin dato: queda el estado de hoy');
        $this->assertSame(['hoteles_sin_dato' => ['Atankalama INN']], $vista['reservas']);
    }

    public function testHoyYLosDiasPasadosNoConsultanCloudbeds(): void
    {
        $hoy = $this->servicio()->vistaConsolidada('1_sur', date('Y-m-d'));
        $ayer = $this->servicio()->vistaConsolidada('1_sur', date('Y-m-d', strtotime('-1 day')));

        $this->assertSame([], $this->urls);
        $this->assertNull($hoy['reservas']);
        $this->assertNull($ayer['reservas']);
        // Un día pasado sigue mostrando todo el inventario del hotel sin asignar (101–108), sin
        // columnas internas.
        $this->assertCount(8, $ayer['sin_asignar']);
        $this->assertArrayNotHasKey('cloudbeds_property_id', $ayer['sin_asignar'][0]);
        $this->assertArrayNotHasKey('tipo_dia', $ayer['sin_asignar'][0]);
    }
}
