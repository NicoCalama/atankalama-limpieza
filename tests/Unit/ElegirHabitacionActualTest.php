<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Services\AsignacionService;
use PHPUnit\Framework\TestCase;

/**
 * Regla de la "habitación actual" (docs/home-trabajador.md §6.2): primero la que el
 * trabajador tiene en curso; si no tiene ninguna, la primera pendiente de la cola.
 */
final class ElegirHabitacionActualTest extends TestCase
{
    public function testLaQueTieneEnCursoGanaAunqueHayaPendientesAntes(): void
    {
        $cola = [
            ['habitacion_id' => 1, 'estado' => 'rechazada', 'en_curso_propia' => 0],
            ['habitacion_id' => 2, 'estado' => 'sucia', 'en_curso_propia' => 0],
            ['habitacion_id' => 3, 'estado' => 'en_progreso', 'en_curso_propia' => 1],
        ];
        $this->assertSame(3, AsignacionService::elegirHabitacionActual($cola)['habitacion_id']);
    }

    public function testSinNadaEnCursoEsLaPrimeraPendienteSaltandoLasTerminadas(): void
    {
        $cola = [
            ['habitacion_id' => 1, 'estado' => 'aprobada', 'en_curso_propia' => 0],
            ['habitacion_id' => 2, 'estado' => 'completada_pendiente_auditoria', 'en_curso_propia' => 0],
            ['habitacion_id' => 3, 'estado' => 'rechazada', 'en_curso_propia' => 0],
            ['habitacion_id' => 4, 'estado' => 'sucia', 'en_curso_propia' => 0],
        ];
        $this->assertSame(3, AsignacionService::elegirHabitacionActual($cola)['habitacion_id']);
    }

    public function testUnaEnProgresoSinEjecucionPropiaCuentaComoPendienteEnSuLugar(): void
    {
        // Pieza que le movieron a medio limpiar: la empieza de cero, respetando el orden.
        $cola = [
            ['habitacion_id' => 1, 'estado' => 'sucia', 'en_curso_propia' => 0],
            ['habitacion_id' => 2, 'estado' => 'en_progreso', 'en_curso_propia' => 0],
        ];
        $this->assertSame(1, AsignacionService::elegirHabitacionActual($cola)['habitacion_id']);
    }

    public function testSinPendientesNoHayActual(): void
    {
        $this->assertNull(AsignacionService::elegirHabitacionActual([]));
        $this->assertNull(AsignacionService::elegirHabitacionActual([
            ['habitacion_id' => 1, 'estado' => 'aprobada_automatica', 'en_curso_propia' => 0],
        ]));
    }
}
