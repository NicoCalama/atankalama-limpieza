<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Services\EstadoHabitacionService;
use Atankalama\Limpieza\Services\HabitacionException;
use PHPUnit\Framework\TestCase;

final class EstadoHabitacionServiceTest extends TestCase
{
    private EstadoHabitacionService $svc;

    protected function setUp(): void
    {
        $this->svc = new EstadoHabitacionService();
    }

    public function testSuciaAEnProgreso(): void
    {
        $this->assertTrue($this->svc->puedeTransicionar(Habitacion::ESTADO_SUCIA, Habitacion::ESTADO_EN_PROGRESO));
    }

    public function testEnProgresoACompletada(): void
    {
        $this->assertTrue($this->svc->puedeTransicionar(
            Habitacion::ESTADO_EN_PROGRESO,
            Habitacion::ESTADO_COMPLETADA_PENDIENTE_AUDITORIA
        ));
    }

    public function testCompletadaA3Veredictos(): void
    {
        foreach ([Habitacion::ESTADO_APROBADA, Habitacion::ESTADO_APROBADA_CON_OBSERVACION, Habitacion::ESTADO_RECHAZADA] as $dst) {
            $this->assertTrue($this->svc->puedeTransicionar(Habitacion::ESTADO_COMPLETADA_PENDIENTE_AUDITORIA, $dst));
        }
    }

    public function testNoSePuedeSaltarAuditoria(): void
    {
        $this->assertFalse($this->svc->puedeTransicionar(Habitacion::ESTADO_EN_PROGRESO, Habitacion::ESTADO_APROBADA));
        $this->assertFalse($this->svc->puedeTransicionar(Habitacion::ESTADO_SUCIA, Habitacion::ESTADO_COMPLETADA_PENDIENTE_AUDITORIA));
    }

    public function testAprobadaVuelveASuciaEnNuevoCiclo(): void
    {
        $this->assertTrue($this->svc->puedeTransicionar(Habitacion::ESTADO_APROBADA, Habitacion::ESTADO_SUCIA));
        $this->assertTrue($this->svc->puedeTransicionar(Habitacion::ESTADO_RECHAZADA, Habitacion::ESTADO_SUCIA));
    }

    public function testNoSePuedeReAuditar(): void
    {
        $this->assertFalse($this->svc->puedeTransicionar(Habitacion::ESTADO_APROBADA, Habitacion::ESTADO_RECHAZADA));
        $this->assertFalse($this->svc->puedeTransicionar(Habitacion::ESTADO_APROBADA, Habitacion::ESTADO_APROBADA_CON_OBSERVACION));
    }

    public function testEstadoInvalidoRetornaFalse(): void
    {
        $this->assertFalse($this->svc->puedeTransicionar('foo', Habitacion::ESTADO_SUCIA));
        $this->assertFalse($this->svc->puedeTransicionar(Habitacion::ESTADO_SUCIA, 'bar'));
    }

    public function testAserciarTransicionLanzaExcepcion(): void
    {
        try {
            $this->svc->aserciarTransicion(Habitacion::ESTADO_APROBADA, Habitacion::ESTADO_RECHAZADA);
            $this->fail('Debía lanzar');
        } catch (HabitacionException $e) {
            $this->assertSame('TRANSICION_INVALIDA', $e->codigo);
            $this->assertSame(409, $e->httpStatus);
        }
    }
}
