<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\ReportesController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Models\Usuario;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Los filtros de Reportes viajan en la URL de un GET. Hasta el 30/09/2026 el controlador los
 * leía con Request::input() (solo el cuerpo), así que se ignoraban: la pantalla mostraba el
 * rango elegido pero los números eran siempre de hoy / del mes en curso / de ambos hoteles, y
 * los exports igual. Los tests del servicio no lo veían porque no pasan por el controlador.
 */
final class ReportesControllerTest extends TestCase
{
    private const FECHA_PASADA = '2026-09-10';

    private int $ana;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur'), ('inn', 'Inn')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();
        $hotelId = (int) Database::fetchColumn("SELECT id FROM hoteles WHERE codigo = '1_sur'");
        $tipoId  = (int) Database::fetchColumn('SELECT id FROM tipos_habitacion LIMIT 1');
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado, es_espacio_comun) VALUES (?, '101', ?, 'sucia', 0)",
            [$hotelId, $tipoId]
        );
        $habId = Database::lastInsertId();
        [$this->ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');

        // Una limpieza completa el 10/09 (fuera de "hoy"): solo aparece si el filtro llega.
        $asig = new AsignacionService();
        $chk  = new ChecklistService();
        $asig->asignarManual($habId, $this->ana, self::FECHA_PASADA);
        $e = $chk->iniciarEjecucion($habId, $this->ana, self::FECHA_PASADA);
        Database::execute(
            'UPDATE ejecuciones_checklist SET timestamp_inicio = ? WHERE id = ?',
            [(new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i:s.v\Z'), $e->id]
        );
        foreach ($chk->itemsDelTemplate($e->templateId) as $it) {
            if ((int) $it['obligatorio'] === 1) {
                $chk->marcarItem($e->id, (int) $it['id'], true, $this->ana);
            }
        }
        $chk->completar($e->id, $this->ana);
        Database::execute(
            'UPDATE ejecuciones_checklist SET timestamp_inicio = ?, timestamp_fin = ? WHERE id = ?',
            ['2026-09-10T13:00:00.000Z', '2026-09-10T13:30:00.000Z', $e->id]
        );
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query): Request
    {
        // Igual que Request::desdeGlobales() en un GET: la query llena $query y el cuerpo va vacío.
        $r = new Request(metodo: 'GET', path: $path, cuerpo: [], ruta: [], query: $query, cookies: [], headers: []);
        $r->usuario = new Usuario(
            id: 99,
            rut: '99999999-9',
            nombre: 'Admin Prueba',
            email: null,
            activo: true,
            requiereCambioPwd: false,
            hotelDefault: null,
            temaPreferido: 'claro',
            permisos: ['reportes.ver', 'reportes.ver_supervisoras'],
            roles: ['Admin'],
        );
        return $r;
    }

    /** @return array<string, mixed> */
    private function data(Response $resp): array
    {
        $this->assertSame(200, $resp->status, $resp->cuerpo);
        return json_decode($resp->cuerpo, true)['data'];
    }

    public function testKpisUsanElRangoHotelYTrabajadoraDeLaUrl(): void
    {
        $data = $this->data((new ReportesController())->kpis($this->get('/api/reportes/kpis', [
            'desde' => '2026-09-01', 'hasta' => '2026-09-30', 'hotel' => '1_sur', 'usuario_id' => (string) $this->ana,
        ])));

        $this->assertSame(
            ['desde' => '2026-09-01', 'hasta' => '2026-09-30', 'hotel' => '1_sur', 'usuario_id' => $this->ana],
            $data['filtros']
        );
        $this->assertSame('1 limpiezas', $data['kpis']['tiempo_promedio']['contexto']);
        $this->assertEquals(30, $data['kpis']['tiempo_promedio']['valor']);
    }

    public function testLaFichaUsaElRangoDeLaUrl(): void
    {
        $ctrl = new ReportesController();

        $conRango = $this->data($ctrl->ficha($this->get('/api/reportes/ficha', ['desde' => '2026-09-01', 'hasta' => '2026-09-30'])));
        $this->assertSame('2026-09-01', $conRango['filtros']['desde']);
        $this->assertSame(['Ana'], array_column($conRango['trabajadores'], 'nombre'));

        // Otro hotel: la limpieza del 1 Sur no entra.
        $otroHotel = $this->data($ctrl->ficha($this->get('/api/reportes/ficha', ['desde' => '2026-09-01', 'hasta' => '2026-09-30', 'hotel' => 'inn'])));
        $this->assertSame('inn', $otroHotel['filtros']['hotel']);
        $this->assertSame([], array_column($otroHotel['trabajadores'], 'nombre'));
    }

    public function testResumenesMensualesUsanElMesDeLaUrl(): void
    {
        $ctrl = new ReportesController();

        $trab = $this->data($ctrl->resumenMensual($this->get('/api/reportes/resumen-mensual', ['anio' => '2026', 'mes' => '9'])));
        $this->assertSame([2026, 9], [$trab['anio'], $trab['mes']]);
        $this->assertSame(['Ana'], array_column($trab['trabajadores'], 'nombre'));

        $aud = $this->data($ctrl->resumenMensualAuditores($this->get('/api/reportes/resumen-mensual-auditores', ['anio' => '2026', 'mes' => '8'])));
        $this->assertSame([2026, 8], [$aud['anio'], $aud['mes']]);

        $fuera = $ctrl->resumenMensual($this->get('/api/reportes/resumen-mensual', ['anio' => '2026', 'mes' => '13']));
        $this->assertSame(400, $fuera->status);
    }

    public function testPendientesAlCorteUsanLaFechaDeLaUrl(): void
    {
        $data = $this->data((new ReportesController())->auditoriasPendientes(
            $this->get('/api/reportes/auditorias-pendientes', ['fecha' => self::FECHA_PASADA, 'hotel' => 'inn'])
        ));

        $this->assertSame(self::FECHA_PASADA, $data['fecha']);
        $this->assertSame('inn', $data['hotel']);
    }

    public function testLosExportsUsanLosFiltrosDeLaUrl(): void
    {
        $ctrl = new ReportesController();

        $kpis = $ctrl->exportar($this->get('/api/reportes/exportar', ['desde' => '2026-09-01', 'hasta' => '2026-09-30']));
        $this->assertStringContainsString('reporte_kpis_2026-09-01_2026-09-30.csv', $kpis->headers()['Content-Disposition'] ?? '');

        $mensual = $ctrl->exportarMensual($this->get('/api/reportes/exportar-mensual', ['anio' => '2026', 'mes' => '8']));
        $this->assertStringContainsString('reporte_mensual_2026-08.csv', $mensual->headers()['Content-Disposition'] ?? '');

        $pend = $ctrl->exportarAuditoriasPendientes($this->get('/api/reportes/exportar-auditorias-pendientes', ['fecha' => self::FECHA_PASADA]));
        $this->assertStringContainsString('reporte_auditorias_pendientes_' . self::FECHA_PASADA . '.csv', $pend->headers()['Content-Disposition'] ?? '');
    }

    public function testFiltrosInvalidosCaenEnHoySinRomper(): void
    {
        $hoy  = date('Y-m-d');
        $data = $this->data((new ReportesController())->kpis($this->get('/api/reportes/kpis', [
            'desde' => '2026-13-45', 'hasta' => '2026-02-30', 'hotel' => 'otro', 'usuario_id' => 'abc',
        ])));

        $this->assertSame(['desde' => $hoy, 'hasta' => $hoy, 'hotel' => 'ambos', 'usuario_id' => null], $data['filtros']);
    }
}
