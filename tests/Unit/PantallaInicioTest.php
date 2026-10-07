<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Unit;

use Atankalama\Limpieza\Models\Usuario;
use Atankalama\Limpieza\Support\PantallaInicio;
use PHPUnit\Framework\TestCase;

/**
 * El Inicio de cada usuario se decide por permisos, nunca por nombre de rol. Quien inspecciona sin
 * administrar ni supervisar (Recepción) no tiene Inicio propio: su punto de partida es Habitaciones y
 * el menú no le muestra «Inicio» (pedido de Nicolás, 05/10/2026).
 */
final class PantallaInicioTest extends TestCase
{
    /** @param list<string> $permisos */
    private function usuario(array $permisos): Usuario
    {
        return new Usuario(
            id: 1,
            rut: '11111111-1',
            nombre: 'Prueba',
            email: null,
            activo: true,
            requiereCambioPwd: false,
            hotelDefault: null,
            temaPreferido: 'claro',
            permisos: $permisos,
            roles: ['Cualquiera'], // el nombre del rol no influye
        );
    }

    public function testLaCascadaVaPorPermisos(): void
    {
        $admin = $this->usuario(['ajustes.acceder', 'auditoria.ver_bandeja', 'alertas.recibir_predictivas', 'asignaciones.asignar_manual']);
        $supervisora = $this->usuario(['alertas.recibir_predictivas', 'asignaciones.asignar_manual', 'auditoria.ver_bandeja']);
        $recepcion = $this->usuario(['auditoria.ver_bandeja', 'habitaciones.ver_todas', 'revision_entrega.registrar']);
        $trabajador = $this->usuario(['habitaciones.ver_asignadas_propias']);
        $soloAlertas = $this->usuario(['alertas.recibir_predictivas']); // sin asignar: no es supervisora

        $this->assertSame(PantallaInicio::ADMIN, PantallaInicio::de($admin));
        $this->assertSame(PantallaInicio::SUPERVISORA, PantallaInicio::de($supervisora));
        $this->assertSame(PantallaInicio::HABITACIONES, PantallaInicio::de($recepcion));
        $this->assertSame(PantallaInicio::TRABAJADOR, PantallaInicio::de($trabajador));
        $this->assertSame(PantallaInicio::TRABAJADOR, PantallaInicio::de($soloAlertas));
    }

    public function testSoloQuienNoTieneInicioPropioPierdeLaPestana(): void
    {
        $this->assertFalse(PantallaInicio::tienePantallaPropia($this->usuario(['auditoria.ver_bandeja'])));
        $this->assertTrue(PantallaInicio::tienePantallaPropia($this->usuario(['habitaciones.ver_asignadas_propias'])));
        $this->assertTrue(PantallaInicio::tienePantallaPropia($this->usuario(['alertas.recibir_predictivas', 'asignaciones.asignar_manual'])));
        $this->assertTrue(PantallaInicio::tienePantallaPropia($this->usuario(['ajustes.acceder'])));
    }
}
