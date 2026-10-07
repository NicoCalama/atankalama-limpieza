<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Services\TurnosImportService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Import de turnos (CSV de Breik y calendario .xlsx): fechas normalizadas, filas con problemas
 * rechazadas con su motivo y sin 500 por columnas de más (auditoría de código, 07/10/2026).
 */
final class TurnosImportServiceTest extends TestCase
{
    private TurnosImportService $svc;

    private const ENCABEZADO = 'DNI,NOMBRE,APELLIDOS,FECHA,TIPO,NOMBRE TURNO,HORA INICIO,HORA TERMINO';

    protected function setUp(): void
    {
        TestDatabase::recrear();
        TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        $this->svc = new TurnosImportService();
    }

    public function testUnaFilaConColumnasDeMasNoRevienta(): void
    {
        // Coma al final: antes array_combine lanzaba ValueError y la carga respondía 500.
        $filas = $this->svc->parsearCsv(self::ENCABEZADO . "\n11111111-1,Ana,Pérez,2026-10-07,TURNO,Mañana,08:00,16:00,\n");
        $this->assertCount(1, $filas);
        $this->assertSame('16:00', $filas[0]['HORA TERMINO']);
    }

    public function testNormalizaLasFechasDelArchivo(): void
    {
        $this->assertSame('2026-10-07', TurnosImportService::normalizarFecha('2026-10-07'));
        $this->assertSame('2026-10-07', TurnosImportService::normalizarFecha('07/10/2026'));
        $this->assertSame('2026-10-07', TurnosImportService::normalizarFecha('7-10-2026'));
        // Serie de Excel (celda con formato fecha): 46302 = 07/10/2026.
        $this->assertSame('2026-10-07', TurnosImportService::normalizarFecha('46302'));
        $this->assertNull(TurnosImportService::normalizarFecha(''));
        $this->assertNull(TurnosImportService::normalizarFecha('31/02/2026'));
        $this->assertNull(TurnosImportService::normalizarFecha('lunes'));
    }

    public function testElPreviewGuardaLaFechaNormalizadaYRechazaLasFilasConProblemas(): void
    {
        $csv = self::ENCABEZADO . "\n"
            . "11111111-1,Ana,Pérez,07/10/2026,TURNO,Mañana,8:00,16:00\n"
            . "11111111-1,Ana,Pérez,08/10/2026,TURNO,Noche,22:00,06:00\n"
            . "11111111-1,Ana,Pérez,mañana,TURNO,Mañana,08:00,16:00\n"
            . "11111111-1,Ana,Pérez,09/10/2026,TURNO,Mañana,8am,16:00\n";

        $preview = $this->svc->preview($this->svc->parsearCsv($csv));

        $this->assertCount(1, $preview['filas_importar']);
        $this->assertSame('2026-10-07', $preview['filas_importar'][0]['fecha'], 'antes quedaba «07/10/2026» y nunca coincidía con hoy');
        $this->assertSame(['desde' => '2026-10-07', 'hasta' => '2026-10-07'], $preview['rango_fechas']);

        $motivos = array_column($preview['filas_rechazadas'], 'motivo');
        $this->assertSame([
            TurnosImportService::MOTIVO_MEDIANOCHE,
            TurnosImportService::MOTIVO_FECHA,
            TurnosImportService::MOTIVO_HORA,
        ], $motivos);
        $this->assertNotContains('Noche', array_column($preview['turnos_nuevos'], 'nombre'), 'el turno nocturno no se crea');
    }
}
