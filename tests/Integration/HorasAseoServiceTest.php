<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\HorasAseoService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Horas en Aseo (v7.18): turnos de los días trabajados + extras del mes − horas en otras áreas. Ver
 * docs/kpis-sueldos.md.
 */
final class HorasAseoServiceTest extends TestCase
{
    private HorasAseoService $svc;
    private int $ana;
    private int $manana;
    private int $corto;
    private int $area;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        [$this->ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');
        Database::execute("INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES ('mañana', '08:00', '16:00')");
        $this->manana = Database::lastInsertId();
        Database::execute("INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES ('corto', '08:00', '12:00')");
        $this->corto = Database::lastInsertId();
        $this->area = TestDatabase::opcionId('area_apoyo', 'Lavandería');
        $this->svc = new HorasAseoService();
    }

    public function testLaJornadaCompletaDescuentaLaColacionYLaParcialNo(): void
    {
        $this->turno('2026-11-03', $this->manana);

        $this->assertSame(7.5, $this->horas(['2026-11-03'], 'completa')['aseo']);
        $this->assertSame(8.0, $this->horas(['2026-11-03'], 'parcial')['aseo']);
    }

    public function testUnDiaConTurnoSinTrabajarNoSuma(): void
    {
        $this->turno('2026-11-03', $this->manana);
        $this->turno('2026-11-04', $this->manana);

        $this->assertSame(7.5, $this->horas(['2026-11-03'], 'completa')['aseo'], 'el 04 tiene turno pero no está entre los días trabajados');
    }

    public function testUnDiaSinTurnoValeLaJornadaEstandarAunqueTengaOtrosTurnosEnElMes(): void
    {
        $this->turno('2026-11-03', $this->manana); // 450 min netos
        $this->turno('2026-11-04', $this->corto);  // 210 min netos: no cambia lo que vale el día sin turno

        $h = $this->horas(['2026-11-03', '2026-11-04', '2026-11-05'], 'completa', '2026-11-01', '2026-11-07');
        $this->assertSame(1, $h['dias_estimados']);
        $this->assertSame(450 + 210 + 450, $h['minutos_aseo'], 'el 05 vale 7,5 h');
    }

    public function testSinNingunTurnoCadaDiaValeLaJornadaEstandar(): void
    {
        $completa = $this->horas(['2026-11-03', '2026-11-04'], 'completa');
        $this->assertSame(HorasAseoService::ESTADO_OK, $completa['estado'], 'si limpió, vino');
        $this->assertSame(15.0, $completa['aseo']);
        $this->assertSame(2, $completa['dias_estimados']);

        $parcial = $this->horas(['2026-11-03', '2026-11-04'], 'parcial');
        $this->assertSame(7.5, $parcial['aseo'], 'parcial = 3,75 h por día');
        $this->assertSame(2, $parcial['dias_estimados']);
    }

    public function testUnTurnoSinMinutosSeTrataComoSinTurno(): void
    {
        Database::execute("INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES ('nulo', '08:00', '08:30')");
        $this->turno('2026-11-03', Database::lastInsertId()); // 30 min − 30 de colación = 0

        $h = $this->horas(['2026-11-03'], 'completa', '2026-11-01', '2026-11-07');
        $this->assertSame(450, $h['minutos_aseo']);
        $this->assertSame(1, $h['dias_estimados']);
    }

    public function testSinJornadaNoSeCalculan(): void
    {
        $this->turno('2026-11-03', $this->manana);

        $h = $this->horas(['2026-11-03'], null);
        $this->assertSame(HorasAseoService::ESTADO_SIN_JORNADA, $h['estado']);
        $this->assertNull($h['aseo']);

        $sinTurno = $this->horas(['2026-11-05'], null);
        $this->assertSame(HorasAseoService::ESTADO_SIN_JORNADA, $sinTurno['estado'], 'sin jornada tampoco hay estándar');
        $this->assertNull($sinTurno['aseo']);
    }

    public function testLasExtrasSumanSoloSiElPeriodoCubreElMes(): void
    {
        $this->turno('2026-11-03', $this->manana);
        Database::execute("INSERT INTO horas_extras (usuario_id, mes, minutos) VALUES (?, '2026-11', 600)", [$this->ana]);

        $completo = $this->horas(['2026-11-03'], 'completa', '2026-11-01', '2026-11-30');
        $this->assertSame(10.0, $completo['extras']);
        $this->assertSame(17.5, $completo['aseo']);

        $parcial = $this->horas(['2026-11-03'], 'completa', '2026-11-01', '2026-11-29');
        $this->assertSame(0.0, $parcial['extras']);
        $this->assertSame(7.5, $parcial['aseo']);
    }

    public function testLasOtrasAreasSeDescuentanSoloDeLosDiasDeLaFila(): void
    {
        $this->turno('2026-11-03', $this->manana);
        $this->turno('2026-11-04', $this->manana);
        $this->otraArea('2026-11-03', 120);
        $this->otraArea('2026-11-04', 240);

        $h = $this->horas(['2026-11-03'], 'completa', '2026-11-01', '2026-11-07');
        $this->assertSame(2.0, $h['otras_areas'], 'el 04 no es de esta fila (p. ej. fue en el otro hotel)');
        $this->assertSame(5.5, $h['aseo']);
    }

    public function testLasOtrasAreasMayoresQueElTurnoSeCompensanConExtras(): void
    {
        $this->turno('2026-11-03', $this->manana); // 7,5 h
        $this->otraArea('2026-11-03', 540);        // 9 h: se quedó después de hora apoyando
        Database::execute("INSERT INTO horas_extras (usuario_id, mes, minutos) VALUES (?, '2026-11', 180)", [$this->ana]);

        $h = $this->horas(['2026-11-03'], 'completa', '2026-11-01', '2026-11-30');
        $this->assertSame(1.5, $h['aseo'], '7,5 + 3 − 9: el descuento es sobre el total, no por día');
    }

    public function testSiNoQuedanHorasElEstadoLoDice(): void
    {
        $this->turno('2026-11-03', $this->manana);
        $this->otraArea('2026-11-03', 540);

        $h = $this->horas(['2026-11-03'], 'completa');
        $this->assertSame(HorasAseoService::ESTADO_SIN_HORAS, $h['estado']);
        $this->assertNull($h['aseo']);
        $this->assertSame(7.5, $h['turno'], 'el desglose se conserva para poder revisarlo');
        $this->assertSame(9.0, $h['otras_areas']);
    }

    public function testAnotarElLargoDelTurnoEsElDiaCompleto(): void
    {
        $this->turno('2026-11-03', $this->manana);
        $this->turno('2026-11-04', $this->manana);
        $this->otraArea('2026-11-04', 480); // 8 h en un turno 08–16, que son 7,5 h de trabajo

        $h = $this->horas(['2026-11-03', '2026-11-04'], 'completa', '2026-11-01', '2026-11-07');
        $this->assertSame(7.5, $h['otras_areas']);
        $this->assertSame(7.5, $h['aseo'], 'el día en otra área queda en 0, no en media hora menos');
    }

    public function testAnotarLasHorasNetasDaLoMismoQueElLargoDelTurno(): void
    {
        $this->turno('2026-11-03', $this->manana);
        $this->turno('2026-11-04', $this->manana);
        $this->otraArea('2026-11-04', 450);

        $this->assertSame(7.5, $this->horas(['2026-11-03', '2026-11-04'], 'completa', '2026-11-01', '2026-11-07')['aseo']);
    }

    public function testAnotarMasQueElLargoDelTurnoNoSeAjusta(): void
    {
        $this->turno('2026-11-03', $this->manana);
        $this->turno('2026-11-04', $this->manana);
        $this->otraArea('2026-11-04', 510); // 8,5 h: se quedó después de hora, se compensa con las extras

        $h = $this->horas(['2026-11-03', '2026-11-04'], 'completa', '2026-11-01', '2026-11-07');
        $this->assertSame(8.5, $h['otras_areas']);
        $this->assertSame(6.5, $h['aseo']);
    }

    public function testElLargoDelDiaSinTurnoTambienEsElDiaCompleto(): void
    {
        $this->turno('2026-11-03', $this->manana);
        $this->otraArea('2026-11-04', 480); // sin turno el 04: vale la jornada estándar

        $h = $this->horas(['2026-11-03', '2026-11-04'], 'completa', '2026-11-01', '2026-11-07');
        $this->assertSame(7.5, $h['aseo']);
    }

    public function testEnJornadaParcialNoHayColacionQueAjustar(): void
    {
        $this->turno('2026-11-03', $this->corto); // 08–12: 240 min en parcial
        $this->turno('2026-11-04', $this->corto);
        $this->otraArea('2026-11-04', 240);

        $h = $this->horas(['2026-11-03', '2026-11-04'], 'parcial', '2026-11-01', '2026-11-07');
        $this->assertSame(4.0, $h['otras_areas']);
        $this->assertSame(4.0, $h['aseo']);
    }

    public function testMesesCubiertos(): void
    {
        $this->assertSame(['2026-11'], HorasAseoService::mesesCubiertos('2026-11-01', '2026-11-30'));
        $this->assertSame([], HorasAseoService::mesesCubiertos('2026-11-01', '2026-11-29'));
        $this->assertSame([], HorasAseoService::mesesCubiertos('2026-11-02', '2026-11-30'));
        $this->assertSame(['2028-02'], HorasAseoService::mesesCubiertos('2028-02-01', '2028-02-29'), 'febrero bisiesto');
        $this->assertSame([], HorasAseoService::mesesCubiertos('2028-02-01', '2028-02-28'));
        $this->assertSame(['2026-12'], HorasAseoService::mesesCubiertos('2026-11-15', '2027-01-10'), 'solo el del medio');
        $this->assertSame(['2026-11', '2026-12'], HorasAseoService::mesesCubiertos('2026-11-01', '2026-12-31'));
    }

    public function testAvisoDeExtras(): void
    {
        $this->assertNull(HorasAseoService::avisoExtras('2026-11-01', '2026-11-30'));
        $this->assertSame('sin extras del mes', HorasAseoService::avisoExtras('2026-11-01', '2026-11-07'));
        $this->assertSame('sin extras de los meses incompletos', HorasAseoService::avisoExtras('2026-11-01', '2026-12-15'));
        $this->assertNull(HorasAseoService::avisoExtras('2026-12-01', '2027-01-31'), 'entre dos años');
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /**
     * @param list<string> $fechas
     * @return array{turno: float, extras: float, otras_areas: float, aseo: ?float, minutos_aseo: ?int, dias_estimados: int, estado: string}
     */
    private function horas(array $fechas, ?string $jornada, string $desde = '2026-11-01', string $hasta = '2026-11-30'): array
    {
        return $this->svc->calcular([$this->ana => $fechas], [$this->ana => $jornada], $desde, $hasta)[$this->ana];
    }

    private function turno(string $fecha, int $turnoId): void
    {
        Database::execute('INSERT INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)', [$this->ana, $turnoId, $fecha]);
    }

    private function otraArea(string $fecha, int $minutos): void
    {
        Database::execute('INSERT INTO horas_otras_areas (usuario_id, fecha, area_id, minutos) VALUES (?, ?, ?, ?)', [$this->ana, $fecha, $this->area, $minutos]);
    }
}
