<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * «Días trabajados» y jornada en las tablas de trabajadores de Reportes (pedido de Nicolás, 30/09/2026).
 *
 * Un día cuenta si ese día la persona tuvo al menos una asignación, aunque sea de una sola pieza. No
 * cuenta la asignación retirada sin trabajo (desasignada, o pasada a otra persona sin que la tocara)
 * ni la fila técnica del atajo «Marcar limpia».
 *
 *   ayer  Ana    asignada a 103, nunca la empieza (sigue siendo suya)   → día de Ana
 *   ayer  Berta  asignada a 104 y desasignada sin trabajo               → no es día de Berta
 *   hoy   Ana    limpia 101 y 102                                       → UN día de Ana (no dos)
 *   hoy   Berta  asignada a 301 (hotel INN), sin empezar                → día de Berta solo en INN / ambos
 *   hoy   Carla  asignada a 105; se la pasan a Dora sin que la toque    → día de Dora, no de Carla
 *   hoy   Sofía  «Marcar limpia» sobre la 106                           → no es trabajo de Sofía
 */
final class ReportesDiasTrabajadosTest extends TestCase
{
    private ReportesService $rep;
    private string $hoy;
    private string $ayer;
    private int $tipoId;
    /** @var array<string,int> número de pieza → id */
    private array $hab = [];

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur'), ('inn', 'Inn')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();

        $this->tipoId = (int) Database::fetchOne('SELECT id FROM tipos_habitacion LIMIT 1')['id'];
        $sur = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $inn = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='inn'")['id'];
        foreach (['101', '102', '103', '104', '105', '106'] as $numero) {
            $this->crearPieza($numero, $sur);
        }
        $this->crearPieza('301', $inn);

        [$ana]   = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        [$berta] = TestDatabase::crearUsuario('22222222-2', 'Berta', 'Trabajador');
        [$carla] = TestDatabase::crearUsuario('44444444-4', 'Carla', 'Trabajador');
        [$dora]  = TestDatabase::crearUsuario('55555555-5', 'Dora', 'Trabajador');
        [$sofia] = TestDatabase::crearUsuario('33333333-3', 'Sofia', 'Supervisora');
        Database::execute("UPDATE usuarios SET jornada = 'parcial' WHERE id = ?", [$ana]);
        Database::execute("UPDATE usuarios SET jornada = 'completa' WHERE id = ?", [$dora]);

        $this->hoy  = date('Y-m-d');
        $this->ayer = date('Y-m-d', strtotime('-1 day'));
        $asig = new AsignacionService();
        $chk  = new ChecklistService();

        // Ayer
        $asig->asignarManual($this->hab['103'], $ana, $this->ayer);
        $asig->asignarManual($this->hab['104'], $berta, $this->ayer);
        $asig->desasignar($this->hab['104'], $this->ayer);

        // Hoy
        $this->limpiarCompleta($asig, $chk, '101', $ana);
        $this->limpiarCompleta($asig, $chk, '102', $ana);
        $asig->asignarManual($this->hab['301'], $berta, $this->hoy);
        $asig->asignarManual($this->hab['105'], $carla, $this->hoy);
        $asig->reasignar($this->hab['105'], $dora, $this->hoy, 'Carla no vino');
        $chk->marcarLimpiaManual($this->hab['106'], $sofia);

        $this->rep = new ReportesService();
    }

    public function testVariasPiezasElMismoDiaSonUnSoloDia(): void
    {
        $this->assertSame(1, $this->filaDe('Ana', $this->hoy, $this->hoy)['dias_trabajados']);
    }

    public function testAsignacionSinEmpezarQueSigueSiendoSuyaCuenta(): void
    {
        // Ayer la 103 (nunca empezada, sigue activa) + hoy 101/102.
        $this->assertSame(2, $this->filaDe('Ana', $this->ayer, $this->hoy)['dias_trabajados']);
    }

    public function testAsignacionDesasignadaSinTrabajoNoCuenta(): void
    {
        // Ayer Berta solo tuvo la 104, que se retiró sin trabajo: ese día no cuenta ni aparece.
        $this->assertNull($this->filaOpcional('Berta', $this->ayer, $this->ayer));
    }

    public function testPiezaPasadaAOtraSinTrabajoCuentaParaQuienLaRecibe(): void
    {
        $this->assertSame(1, $this->filaDe('Dora', $this->hoy, $this->hoy)['dias_trabajados']);
        // Carla sigue en la ficha (la pieza asignada es pegajosa) pero ese día no lo trabajó.
        $carla = $this->filaDe('Carla', $this->hoy, $this->hoy);
        $this->assertSame(1, $carla['esperado_hab']);
        $this->assertSame(0, $carla['dias_trabajados']);
    }

    public function testElAtajoMarcarLimpiaNoEsDiaTrabajado(): void
    {
        $this->assertNull($this->filaOpcional('Sofia', $this->hoy, $this->hoy));
    }

    public function testRespetaElFiltroDeHotel(): void
    {
        $this->assertSame(1, $this->filaDe('Berta', $this->hoy, $this->hoy, 'ambos')['dias_trabajados']);
        $this->assertSame(1, $this->filaDe('Berta', $this->hoy, $this->hoy, 'inn')['dias_trabajados']);
        $this->assertNull($this->filaOpcional('Berta', $this->hoy, $this->hoy, '1_sur'));
    }

    public function testJornadaViajaConCadaFila(): void
    {
        $this->assertSame('parcial', $this->filaDe('Ana', $this->hoy, $this->hoy)['jornada']);
        $this->assertSame('completa', $this->filaDe('Dora', $this->hoy, $this->hoy)['jornada']);
        $this->assertNull($this->filaDe('Carla', $this->hoy, $this->hoy)['jornada'], 'sin definir');
    }

    public function testDetallePorTrabajadoraYResumenMensualLlevanDiasYJornada(): void
    {
        $detalle = [];
        foreach ($this->rep->reporteKpis($this->ayer, $this->hoy, 'ambos')['por_trabajadora'] as $t) {
            $detalle[$t['nombre']] = $t;
        }
        $this->assertSame(2, $detalle['Ana']['dias_trabajados']);
        $this->assertSame('parcial', $detalle['Ana']['jornada']);

        // El resumen mensual es la ficha del mes: mismos días.
        $mensual = [];
        foreach ($this->rep->resumenMensual((int) date('Y'), (int) date('n'), 'ambos') as $t) {
            $mensual[$t['nombre']] = $t;
        }
        $ficha = $this->filaDe('Ana', date('Y-m-01'), date('Y-m-t'));
        $this->assertSame($ficha['dias_trabajados'], $mensual['Ana']['dias_trabajados']);
        $this->assertSame('parcial', $mensual['Ana']['jornada']);
    }

    public function testCsvLlevanLasColumnasNuevas(): void
    {
        $mensual = $this->rep->hojasMensual((int) date('Y'), (int) date('n'), 'ambos')['Trabajadores'];
        $this->assertSame(['RUT', 'Trabajador', 'Jornada', 'Días trabajados', 'Hab. limpiadas', 'Hab. hechas'], array_slice($mensual[5], 0, 6));
        $this->assertSame(['11111111-1', 'Ana', 'Tiempo parcial'], array_slice($mensual[6], 0, 3));

        $csv = $this->rep->exportarCsv($this->hoy, $this->hoy, 'ambos');
        $this->assertStringContainsString('"Trabajadora";"Jornada";"Días trabajados";"T. Prom. (min)"', $csv);
        $this->assertStringContainsString('"Ana";"Tiempo parcial";"1";', $csv);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /** KPI 1.4: «aunque sea de una sola pieza (o área común)». Antes el filtro de piezas la sacaba. */
    public function testUnAreaComunCuentaComoDiaTrabajadoPeroNoComoPiezaAsignada(): void
    {
        [$eli] = TestDatabase::crearUsuario('66666666-6', 'Eli', 'Trabajador');
        $sur = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado, es_espacio_comun) VALUES (?, 'Piscina', ?, 'sucia', 1)",
            [$sur, $this->tipoId]
        );
        (new AsignacionService())->asignarManual(Database::lastInsertId(), $eli, $this->hoy);

        $fila = $this->filaDe('Eli', $this->hoy, $this->hoy);
        $this->assertSame(1, $fila['dias_trabajados']);
        $this->assertSame(0, $fila['esperado_hab'], 'el área común no es una pieza asignada');
    }

    /** Decisión de Nicolás (07/10/2026): lo planificado para días que no llegan no cuenta. */
    public function testLoPlanificadoParaDiasQueNoLleganNoCuenta(): void
    {
        $manana = date('Y-m-d', strtotime('+1 day'));
        $antes = $this->filaDe('Ana', $this->hoy, $this->hoy);
        (new AsignacionService())->asignarManual($this->hab['104'], (int) Database::fetchOne("SELECT id FROM usuarios WHERE nombre = 'Ana'")['id'], $manana);

        $fila = $this->filaDe('Ana', $this->hoy, $manana);
        $this->assertSame(1, $fila['dias_trabajados'], 'mañana todavía no se trabaja');
        $this->assertSame($antes['esperado_hab'], $fila['esperado_hab'], 'ni suma piezas asignadas');
    }

    private function limpiarCompleta(AsignacionService $asig, ChecklistService $chk, string $numero, int $usuario): void
    {
        $habId = $this->hab[$numero];
        $asig->asignarManual($habId, $usuario, $this->hoy);
        $e = $chk->iniciarEjecucion($habId, $usuario, $this->hoy);
        foreach ($chk->itemsDelTemplate($e->templateId) as $it) {
            if ((int) $it['obligatorio'] === 1) {
                $chk->marcarItem($e->id, (int) $it['id'], true, $usuario);
            }
        }
        $chk->completar($e->id, $usuario);
    }

    /** @return array<string, mixed>|null */
    private function filaOpcional(string $nombre, string $desde, string $hasta, string $hotel = 'ambos'): ?array
    {
        foreach ($this->rep->fichaKpis($desde, $hasta, $hotel, false)['trabajadores'] as $t) {
            if ($t['nombre'] === $nombre) {
                return $t;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function filaDe(string $nombre, string $desde, string $hasta, string $hotel = 'ambos'): array
    {
        $fila = $this->filaOpcional($nombre, $desde, $hasta, $hotel);
        if ($fila === null) {
            $this->fail("No aparece {$nombre} en la ficha");
        }
        return $fila;
    }

    private function crearPieza(string $numero, int $hotelId): void
    {
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, ?, ?, 'sucia')",
            [$hotelId, $numero, $this->tipoId]
        );
        $this->hab[$numero] = Database::lastInsertId();
    }
}
