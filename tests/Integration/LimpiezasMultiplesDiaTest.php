<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\AsignacionException;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Varias limpiezas por día (feature F). Ver docs/limpiezas-multiples-dia.md
 */
final class LimpiezasMultiplesDiaTest extends TestCase
{
    private AsignacionService $asig;
    private ChecklistService $chk;
    private AuditoriaService $aud;
    private int $roomId;
    private int $anaId;
    private int $bertaId;
    private int $auditorId;
    private int $oblCount;
    private string $hoy;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('inn', 'Inn')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        Database::execute("INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES ('mañana', '08:00', '16:00')");
        TestDatabase::sembrarChecklistTemplates();

        $tipoId = (int) Database::fetchOne("SELECT id FROM tipos_habitacion WHERE nombre='Doble'")['id'];
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (1, '101', ?, 'sucia')",
            [$tipoId]
        );
        $this->roomId = Database::lastInsertId();

        [$this->anaId] = TestDatabase::crearUsuario('16000001-5', 'Ana', 'Trabajador');
        [$this->bertaId] = TestDatabase::crearUsuario('17000001-3', 'Berta', 'Trabajador');
        [$this->auditorId] = TestDatabase::crearUsuario('15000001-7', 'Sofía', 'Supervisora');

        $this->hoy = date('Y-m-d');
        $this->asig = new AsignacionService();
        $this->chk = new ChecklistService();
        $this->aud = new AuditoriaService();

        $tpl = $this->chk->templateParaTipo($tipoId);
        $this->oblCount = count(array_filter(
            $this->chk->itemsDelTemplate((int) $tpl),
            static fn(array $i) => (int) $i['obligatorio'] === 1
        ));
    }

    /** Limpia y aprueba la pieza con un trabajador; devuelve el id de la ejecución. */
    private function limpiarYAprobar(int $workerId, ?string $franja): int
    {
        $this->asig->asignarManual($this->roomId, $workerId, $this->hoy, null, $franja);
        $ejec = $this->chk->iniciarEjecucion($this->roomId, $workerId, $this->hoy);
        foreach ($this->chk->estadoEjecucion($ejec->id)['items'] as $it) {
            if ((int) $it['obligatorio'] === 1) {
                $this->chk->marcarItem($ejec->id, (int) $it['id'], true, $workerId);
            }
        }
        $this->chk->completar($ejec->id, $workerId);
        $this->aud->emitirVeredicto($this->roomId, $this->auditorId, 'aprobado');
        return $ejec->id;
    }

    private function itemsMarcados(int $ejecucionId): int
    {
        return (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM ejecuciones_items WHERE ejecucion_id = ? AND marcado = 1',
            [$ejecucionId]
        );
    }

    public function testReLimpiarPiezaAprobadaArrancaDeCeroYNoAfectaAlPrimero(): void
    {
        // 1) Ana limpia la 101 en la mañana y se aprueba.
        $ejecAna = $this->limpiarYAprobar($this->anaId, 'mañana');
        $this->assertSame('aprobada', (string) Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$this->roomId])['estado']);
        $this->assertSame($this->oblCount, $this->itemsMarcados($ejecAna));

        // 2) El coordinador re-abre la pieza (aprobada → sucia) y la asigna a Berta para la noche.
        $this->asig->asignarManual($this->roomId, $this->bertaId, $this->hoy, null, 'noche');
        $this->assertSame('sucia', (string) Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$this->roomId])['estado']);

        // 3) INVARIANTE: la nueva limpieza arranca DE CERO (no hereda, porque el veredicto previo fue
        //    'aprobado', no 'rechazado'). Ver docs/limpiezas-multiples-dia.md §2.
        $ejecBerta = $this->chk->iniciarEjecucion($this->roomId, $this->bertaId, $this->hoy);
        $this->assertNotSame($ejecAna, $ejecBerta->id);
        $this->assertSame(0, $this->itemsMarcados($ejecBerta->id), 'La re-limpieza tras aprobación no debe heredar ítems');

        // 4) Berta completa su propia limpieza y se aprueba.
        foreach ($this->chk->estadoEjecucion($ejecBerta->id)['items'] as $it) {
            if ((int) $it['obligatorio'] === 1) {
                $this->chk->marcarItem($ejecBerta->id, (int) $it['id'], true, $this->bertaId);
            }
        }
        $this->chk->completar($ejecBerta->id, $this->bertaId);
        $this->aud->emitirVeredicto($this->roomId, $this->auditorId, 'aprobado');

        // 5) Cada ítem queda atribuido a quien lo hizo: los de Ana a Ana, los de Berta a Berta.
        $marcadoPorAjeno = (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM ejecuciones_items WHERE ejecucion_id = ? AND marcado = 1 AND marcado_por <> ?',
            [$ejecAna, $this->anaId]
        );
        $this->assertSame(0, $marcadoPorAjeno, 'Los créditos de Ana no deben cambiar');
        $bertaEnSuEjec = (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM ejecuciones_items WHERE ejecucion_id = ? AND marcado = 1 AND marcado_por = ?',
            [$ejecBerta->id, $this->bertaId]
        );
        $this->assertSame($this->oblCount, $bertaEnSuEjec);

        // 6) A nivel de KPI: cada una acredita su propia limpieza completa (ninguna perjudicada).
        $anio = (int) date('Y', strtotime($this->hoy));
        $mes = (int) date('n', strtotime($this->hoy));
        $resumen = (new ReportesService())->resumenMensual($anio, $mes, 'ambos');
        $porId = [];
        foreach ($resumen as $r) {
            $porId[(int) $r['usuario_id']] = $r;
        }
        $this->assertArrayHasKey($this->anaId, $porId);
        $this->assertArrayHasKey($this->bertaId, $porId);
        $this->assertSame($this->oblCount, (int) $porId[$this->anaId]['creditos']);
        $this->assertSame($this->oblCount, (int) $porId[$this->bertaId]['creditos']);
        // Sin desmarcados del auditor, el % de cada una es 100% (créditos == créditos_maximos).
        $this->assertSame((int) $porId[$this->anaId]['creditos'], (int) $porId[$this->anaId]['creditos_maximos']);
    }

    public function testFranjaSePersisteYViajaALaCola(): void
    {
        $a = $this->asig->asignarManual($this->roomId, $this->anaId, $this->hoy, null, 'noche');
        $this->assertSame('noche', $a->franja);

        $cola = $this->asig->colaDelTrabajador($this->anaId, $this->hoy);
        $this->assertCount(1, $cola);
        $this->assertSame('noche', (string) $cola[0]['franja']);
    }

    public function testFranjaInvalidaLanza(): void
    {
        try {
            $this->asig->asignarManual($this->roomId, $this->anaId, $this->hoy, null, 'madrugada');
            $this->fail('Debía lanzar FRANJA_INVALIDA');
        } catch (AsignacionException $e) {
            $this->assertSame('FRANJA_INVALIDA', $e->codigo);
        }
    }

    public function testSinFranjaQuedaNull(): void
    {
        $a = $this->asig->asignarManual($this->roomId, $this->anaId, $this->hoy);
        $this->assertNull($a->franja);
    }

    public function testVistaConsolidadaListaPiezasReLimpiables(): void
    {
        // 102 sucia sin limpiar hoy; 101 la limpiamos y aprobamos hoy.
        Database::execute("INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (1, '102', (SELECT id FROM tipos_habitacion LIMIT 1), 'sucia')");
        $this->limpiarYAprobar($this->anaId, 'mañana');

        $vista = $this->asig->vistaConsolidada('1_sur', $this->hoy);
        $reNums = array_map(static fn(array $h) => (string) $h['numero'], $vista['re_limpiar']);
        $sinNums = array_map(static fn(array $h) => (string) $h['numero'], $vista['sin_asignar']);

        $this->assertContains('101', $reNums, '101 (limpiada hoy) debe estar en re_limpiar');
        $this->assertNotContains('101', $sinNums);
        $this->assertContains('102', $sinNums, '102 (sucia) debe estar en sin_asignar');
        $this->assertNotContains('102', $reNums);
    }
}
