<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\AuditoriaController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Services\AuditoriaException;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Services\UsuarioService;
use Atankalama\Limpieza\Tests\Support\EscenarioCicloLimpieza;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * v7.11 «No se pudo inspeccionar» (pedido de Nicolás, 25/10/2026; en reportes «Sin inspección»): la
 * supervisora no puede entrar a la pieza (ya hay un huésped) y la marca así para que salga de la bandeja y
 * otra no vaya a molestar de nuevo. La pieza queda igual que con el cierre automático (aprobada_automatica,
 * Clean en Cloudbeds) y cuenta como él en los KPIs; en los reportes sale con su propio nombre.
 */
final class SinInspeccionV711Test extends TestCase
{
    use EscenarioCicloLimpieza;

    private int $huesped;

    protected function setUp(): void
    {
        $this->prepararEscenario();
        $this->huesped = TestDatabase::opcionId('sin_inspeccion', 'Huésped en la pieza');
    }

    private function sinInspeccion(string $numero, ?int $motivo = null): Auditoria
    {
        return $this->aud->emitirVeredicto(
            $this->hab[$numero],
            $this->sofia,
            Auditoria::VEREDICTO_SIN_INSPECCION,
            null,
            [],
            $motivo ?? $this->huesped
        );
    }

    public function testSaleDeLaBandejaYQuedaComoLaQueApruebaElSistema(): void
    {
        $ejecucion = $this->limpiar('101', $this->ana);
        $this->assertCount(1, $this->aud->bandejaPendientes(), 'antes, por inspeccionar');

        $a = $this->sinInspeccion('101');

        $this->assertSame('sin_inspeccion', $a->veredicto);
        $this->assertSame('Huésped en la pieza', $a->comentario, 'el comentario es el nombre del motivo');
        $this->assertSame($this->sofia, $a->auditorId, 'la firma quien la marcó, no Sistema');
        $this->assertSame($this->huesped, (int) Database::fetchColumn('SELECT motivo_id FROM #__auditorias WHERE id = ?', [$a->id]));
        $this->assertEstado('101', Habitacion::ESTADO_APROBADA_AUTOMATICA);
        $this->assertSame('auditada', Database::fetchColumn('SELECT estado FROM #__ejecuciones_checklist WHERE id = ?', [$ejecucion]));
        $this->assertSame([], $this->aud->bandejaPendientes(), 'nadie más la ve en Inspección');
        $this->assertSame('clean', $this->cb->condicion('CB_101'), 'Clean en Cloudbeds, como el cierre automático');
        $this->assertSame(
            'ui',
            Database::fetchColumn("SELECT origen FROM #__audit_log WHERE accion = 'habitacion.cambiar_estado' ORDER BY id DESC LIMIT 1"),
            'la hizo una persona'
        );
    }

    public function testNoSePuedeVolverAInspeccionar(): void
    {
        $this->limpiar('101', $this->ana);
        $this->sinInspeccion('101');

        try {
            $this->aprobar('101');
            $this->fail('Debía rechazar el segundo veredicto');
        } catch (AuditoriaException $e) {
            $this->assertSame(409, $e->httpStatus);
        }
        $this->assertSame(1, (int) Database::fetchColumn('SELECT COUNT(*) FROM #__auditorias'));
    }

    public function testExigeUnMotivoDeSuListaYNoAceptaCasillas(): void
    {
        $this->limpiar('101', $this->ana);
        $intentos = [
            'OPCION_REQUERIDA' => [null, []],
            'OPCION_INVALIDA' => [TestDatabase::opcionId('rechazo', 'Baño sucio'), []], // motivo de otra lista
            'ITEMS_DESMARCADOS_NO_APLICABLE' => [$this->huesped, [$this->obligatorios($this->templateDe(
                (int) Database::fetchColumn('SELECT MAX(id) FROM #__ejecuciones_checklist')
            ))[0]]],
        ];
        foreach ($intentos as $codigo => [$motivo, $items]) {
            try {
                $this->aud->emitirVeredicto($this->hab['101'], $this->sofia, Auditoria::VEREDICTO_SIN_INSPECCION, null, $items, $motivo);
                $this->fail("Debía dar {$codigo}");
            } catch (AuditoriaException $e) {
                $this->assertSame($codigo, $e->codigo);
                $this->assertSame(400, $e->httpStatus);
            }
        }
        $this->assertEstado('101', Habitacion::ESTADO_COMPLETADA_PENDIENTE_AUDITORIA, 'sigue por inspeccionar');
    }

    public function testElControladorExigeSuPermisoYElMotivo(): void
    {
        $this->limpiar('101', $this->ana);
        $pedir = function (int $usuarioId, array $cuerpo): int {
            $req = new Request(
                metodo: 'POST',
                path: '/api/auditoria/' . $this->hab['101'],
                cuerpo: $cuerpo,
                ruta: ['id' => (string) $this->hab['101']],
            );
            $req->usuario = (new UsuarioService())->buscarPorId($usuarioId);
            return (new AuditoriaController())->emitirVeredicto($req)->status;
        };
        $cuerpo = ['veredicto' => 'sin_inspeccion', 'motivo_id' => $this->huesped];

        $this->assertSame(403, $pedir($this->ana, $cuerpo), 'una trabajadora no puede');
        $this->assertSame(400, $pedir($this->sofia, ['veredicto' => 'sin_inspeccion']), 'sin motivo');

        Database::execute(
            "DELETE FROM #__rol_permisos WHERE permiso_codigo = 'auditoria.sin_inspeccion'
               AND rol_id = (SELECT id FROM #__roles WHERE nombre = 'Supervisora')"
        );
        $this->assertSame(403, $pedir($this->sofia, $cuerpo), 'sin el permiso, aunque pueda aprobar');
        $this->assertEstado('101', Habitacion::ESTADO_COMPLETADA_PENDIENTE_AUDITORIA);
    }

    public function testCuentaComoElCierreAutomaticoEnLosKpis(): void
    {
        $this->limpiar('101', $this->ana);
        $this->sinInspeccion('101');
        $this->limpiar('102', $this->ana);
        $this->cierreDeDia();

        $ana = $this->filaDe('Ana');
        $this->assertSame(2, $ana['habitaciones'], 'las dos le cuentan como hechas');
        $this->assertSame(0, $ana['hab_auditadas']);
        $this->assertSame(2, $ana['hab_no_auditadas'], 'las dos como no inspeccionadas');
        $this->assertSame($ana['creditos'], $ana['creditos_no_auditados']);

        $seccion = (new ReportesService())->fichaKpis($this->hoy, $this->hoy, 'ambos')['supervisoras']['seccion'];
        $this->assertSame([2, 0], [$seccion['completadas'], $seccion['auditadas_humanas']], 'baja la cobertura');
    }

    public function testElResumenMensualLaCuentaAparte(): void
    {
        $this->limpiar('101', $this->ana);
        $this->sinInspeccion('101');
        $this->limpiar('102', $this->ana);
        $this->aprobar('102');

        $sofia = array_column(
            (new ReportesService())->resumenMensualAuditores((int) date('Y'), (int) date('n'), 'ambos'),
            null,
            'nombre'
        )['Sofia'];
        $this->assertSame(
            ['total' => 1, 'aprobadas' => 1, 'sin_inspeccion' => 1],
            ['total' => $sofia['total'], 'aprobadas' => $sofia['aprobadas'], 'sin_inspeccion' => $sofia['sin_inspeccion']],
            'no es una inspección: no suma en el total ni en aprobadas'
        );

        $sup = (new ReportesService())->hojasMensual((int) date('Y'), (int) date('n'), 'ambos')['Supervisores'];
        $encabezado = $sup[array_search(['ATANKALAMA'], $sup, true) + 1];
        $this->assertSame('Sin inspección', $encabezado[5]);
        $this->assertSame(['TOTAL', 1, 1, 0, 0, 1], array_slice($sup[count($sup) - 1], 0, 6));
    }

    public function testPendientesAlCorteLaMuestranComoSinInspeccionConSuMotivo(): void
    {
        $this->limpiar('101', $this->ana);
        $this->sinInspeccion('101');

        $pendientes = array_merge(...array_column(
            (new ReportesService())->auditoriasPendientes($this->hoy, 'ambos')['turnos'],
            'pendientes'
        ));
        $this->assertCount(1, $pendientes);
        $this->assertSame(['sin_inspeccion', 'Huésped en la pieza'], [$pendientes[0]['estado_auditoria'], $pendientes[0]['motivo']]);
        $this->assertSame('Sin inspección (Huésped en la pieza)', ReportesService::textoEstadoPendiente($pendientes[0]));

        $csv = (new ReportesService())->exportarCsvAuditoriasPendientes($this->hoy, 'ambos');
        $this->assertStringContainsString('Sin inspección (Huésped en la pieza)', $csv);
    }
}
