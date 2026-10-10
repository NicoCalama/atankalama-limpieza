<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\ReportesController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Models\Usuario;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Excel del resumen mensual con dos pestañas (pedido de Nicolás, 04/10/2026):
 *
 *   101 (1 Sur)  Ana limpia, Sofía aprueba CON OBSERVACIÓN (1 casilla)
 *   201 (INN)    Ana limpia, Sofía RECHAZA (2 casillas)
 *
 * «Trabajadores» respeta el hotel elegido; «Supervisores» trae SIEMPRE un bloque por hotel y uno con
 * el total, aunque arriba se haya elegido un solo hotel.
 */
final class ReportesExportarMensualTest extends TestCase
{
    private int $ana;
    private int $sofia;
    /** @var array<string,int> */
    private array $hab = [];

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur'), ('inn', 'Inn')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();
        $tipo = (int) Database::fetchOne('SELECT id FROM tipos_habitacion LIMIT 1')['id'];
        foreach (['101' => '1_sur', '201' => 'inn'] as $n => $codigo) {
            $hotel = (int) Database::fetchOne('SELECT id FROM hoteles WHERE codigo = ?', [$codigo])['id'];
            Database::execute("INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, ?, ?, 'sucia')", [$hotel, $n, $tipo]);
            $this->hab[(string) $n] = Database::lastInsertId();
        }
        [$this->ana]   = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        [$this->sofia] = TestDatabase::crearUsuario('33333333-3', 'Sofia', 'Supervisora');

        $this->limpiar('101');
        $this->limpiar('201');
        $aud = new AuditoriaService();
        $aud->emitirVeredicto($this->hab['101'], $this->sofia, Auditoria::VEREDICTO_APROBADO_CON_OBSERVACION, 'Faltó el espejo.', $this->items('101', 1));
        $aud->emitirVeredicto($this->hab['201'], $this->sofia, Auditoria::VEREDICTO_RECHAZADO, 'Rehacer el baño.', $this->items('201', 2));
    }

    public function testObservacionesDelInspectorPorHotel(): void
    {
        $rep = new ReportesService();
        $obs = static fn (string $hotel): int => $rep->resumenMensualAuditores((int) date('Y'), (int) date('n'), $hotel)[0]['observaciones'];

        $this->assertSame(1, $obs('1_sur'));
        $this->assertSame(2, $obs('inn'));
        $this->assertSame(3, $obs('ambos'));
    }

    public function testTrabajadoresRespetaElHotelYSupervisoresTraeLosDosYElTotal(): void
    {
        $hojas = (new ReportesService())->hojasMensual((int) date('Y'), (int) date('n'), '1_sur');
        $this->assertSame(['Trabajadores', 'Supervisores', 'Recepción'], array_keys($hojas));
        $this->assertContains(['Sin inspecciones pre-entrega en el mes'], $hojas['Recepción'], 'sin inspecciones de Recepción en este escenario');

        // Trabajadores, solo 1 Sur: la 101 (hecha); la rechazada de INN no entra.
        $ana = $this->filaQueEmpiezaCon($hojas['Trabajadores'], '11111111-1');
        $this->assertSame([1, 1], [$ana[4], $ana[5]], 'hab. limpiadas y hab. hechas del 1 Sur');

        // Supervisores: tres bloques aunque el filtro sea 1 Sur.
        $sup = $hojas['Supervisores'];
        $this->assertSame(['ATANKALAMA', 'ATANKALAMA INN', 'TOTAL AMBOS HOTELES'], $this->titulosDeBloque($sup));
        $this->assertSame(
            [
                'Inspector', 'Total inspeccionadas', 'Aprobadas', 'Aprobadas con observación', 'Rechazadas', 'Observaciones',
                'Recepción: aprobadas', 'Recepción: rechazadas', 'Recepción: calidad %',
            ],
            $sup[array_search(['ATANKALAMA'], $sup, true) + 1]
        );
        // Sin inspecciones pre-entrega de Recepción en este escenario: la columna «Recepción» va en cero y la
        // calidad, sin dato (celda vacía).
        $this->assertSame(
            [['Sofia', 1, 0, 1, 0, 1, 0, 0, ''], ['Sofia', 1, 0, 0, 1, 2, 0, 0, ''], ['Sofia', 2, 0, 1, 1, 3, 0, 0, '']],
            array_values(array_filter($sup, static fn (array $f): bool => ($f[0] ?? null) === 'Sofia'))
        );
        $this->assertSame(['TOTAL', 2, 0, 1, 1, 3, 0, 0, ''], $sup[count($sup) - 1]);
    }

    public function testElControladorDevuelveUnExcelConLasDosPestanas(): void
    {
        $r = new Request(metodo: 'GET', path: '/api/reportes/exportar-mensual', query: ['anio' => date('Y'), 'mes' => date('n'), 'hotel' => 'inn']);
        $r->usuario = new Usuario(
            id: $this->sofia, rut: '33333333-3', nombre: 'Sofia', email: null, activo: true,
            requiereCambioPwd: false, hotelDefault: null, temaPreferido: 'claro',
            permisos: ['reportes.ver'], roles: ['Supervisora'],
        );
        $resp = (new ReportesController())->exportarMensual($r);

        $this->assertSame(200, $resp->status);
        $this->assertStringContainsString('spreadsheetml', $resp->contentType);
        $this->assertStringContainsString(sprintf('reporte_mensual_%s.xlsx', date('Y-m')), $resp->headers()['Content-Disposition'] ?? '');
        $this->assertStringStartsWith('PK', $resp->cuerpo, 'un .xlsx es un ZIP');
        $this->assertStringContainsString('xl/worksheets/sheet2.xml', $resp->cuerpo, 'segunda pestaña');
    }

    /**
     * @param list<list<mixed>> $filas
     * @return list<mixed>
     */
    private function filaQueEmpiezaCon(array $filas, string $primera): array
    {
        foreach ($filas as $f) {
            if (($f[0] ?? null) === $primera) {
                return $f;
            }
        }
        $this->fail("No hay una fila que empiece con {$primera}");
    }

    /**
     * @param list<list<mixed>> $filas
     * @return list<string>
     */
    private function titulosDeBloque(array $filas): array
    {
        return array_values(array_map(
            static fn (array $f): string => $f[0],
            array_filter($filas, static fn (array $f): bool => count($f) === 1 && in_array($f[0], ['ATANKALAMA', 'ATANKALAMA INN', 'TOTAL AMBOS HOTELES'], true))
        ));
    }

    private function limpiar(string $numero): void
    {
        $asig = new AsignacionService();
        $chk  = new ChecklistService();
        $asig->asignarManual($this->hab[$numero], $this->ana, date('Y-m-d'));
        $e = $chk->iniciarEjecucion($this->hab[$numero], $this->ana, date('Y-m-d'));
        foreach ($chk->itemsDelTemplate($e->templateId) as $it) {
            if ((int) $it['obligatorio'] === 1) {
                $chk->marcarItem($e->id, (int) $it['id'], true, $this->ana);
            }
        }
        // Antifraude de 3 min: el inicio se lleva 20 min atrás (mismo día) para poder completar.
        Database::execute(
            'UPDATE ejecuciones_checklist SET timestamp_inicio = ? WHERE id = ?',
            [gmdate('Y-m-d\TH:i:s.000\Z', max(strtotime('today'), time() - 1200)), $e->id]
        );
        $chk->completar($e->id, $this->ana);
    }

    /** @return list<int> los primeros $cuantos ítems obligatorios del checklist de la pieza */
    private function items(string $numero, int $cuantos): array
    {
        $templateId = (int) Database::fetchColumn(
            'SELECT template_id FROM ejecuciones_checklist WHERE habitacion_id = ? ORDER BY id DESC LIMIT 1',
            [$this->hab[$numero]]
        );
        $ids = [];
        foreach ((new ChecklistService())->itemsDelTemplate($templateId) as $it) {
            if ((int) $it['obligatorio'] === 1 && count($ids) < $cuantos) {
                $ids[] = (int) $it['id'];
            }
        }
        $this->assertCount($cuantos, $ids);
        return $ids;
    }
}
