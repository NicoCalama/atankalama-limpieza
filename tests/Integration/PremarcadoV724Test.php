<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Services\AlertasPredictivasService;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\EsquemaService;
use Atankalama\Limpieza\Services\HoraTardeService;
use Atankalama\Limpieza\Tests\Support\EscenarioCicloLimpieza;
use PHPUnit\Framework\TestCase;

/**
 * v7.24 (reunión del 08/11/2026; plan aprobado el 09/11): checklist premarcado. Pieza con chapa Noche o Día/Noche
 * vigente, limpieza que empieza desde la hora de la tarde → todos los ítems nacen marcados a nombre de quien limpia
 * (créditos normales, se termina de un toque), y la ejecución queda premarcada = 1.
 */
final class PremarcadoV724Test extends TestCase
{
    use EscenarioCicloLimpieza;

    protected function setUp(): void
    {
        $this->prepararEscenario();
    }

    private function chkALas(string $hora): ChecklistService
    {
        return new ChecklistService(asignaciones: new AsignacionService(cloudbeds: $this->sync), reloj: static fn(): string => $hora);
    }

    /** Rechaza la última limpieza de la pieza desmarcando sus dos primeros ítems. */
    private function rechazar(string $numero): void
    {
        $e = (int) Database::fetchColumn('SELECT MAX(id) FROM ejecuciones_checklist WHERE habitacion_id = ?', [$this->hab[$numero]]);
        $items = array_map('intval', array_column(
            Database::fetchAll('SELECT item_id FROM ejecuciones_items WHERE ejecucion_id = ? AND marcado = 1 ORDER BY item_id LIMIT 2', [$e]),
            'item_id'
        ));
        $this->aud->emitirVeredicto($this->hab[$numero], $this->sofia, 'rechazado', 'Rehacer estos ítems.', $items);
    }

    private function totalItems(int $templateId): int
    {
        return (int) Database::fetchColumn('SELECT COUNT(*) FROM items_checklist WHERE template_id = ? AND activo = 1', [$templateId]);
    }

    /** @return array{marcados: int, de_ana: int, premarcada: int} */
    private function resumen(int $ejecucionId): array
    {
        return [
            'marcados' => (int) Database::fetchColumn('SELECT COUNT(*) FROM ejecuciones_items WHERE ejecucion_id = ? AND marcado = 1', [$ejecucionId]),
            'de_ana' => (int) Database::fetchColumn('SELECT COUNT(*) FROM ejecuciones_items WHERE ejecucion_id = ? AND marcado_por = ?', [$ejecucionId, $this->ana]),
            'premarcada' => (int) Database::fetchColumn('SELECT premarcada FROM ejecuciones_checklist WHERE id = ?', [$ejecucionId]),
        ];
    }

    public function testNocheDesdeLaHoraLlegaMarcadaYSeTerminaDeUnToque(): void
    {
        $this->habs->marcarNochero($this->hab['101'], $this->hoy, $this->sofia, null, '09:00', Habitacion::NOCHERO_NOCHE);
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);

        $chk = $this->chkALas('16:00');
        $e = $chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);

        $total = $this->totalItems($e->templateId);
        $this->assertSame(['marcados' => $total, 'de_ana' => $total, 'premarcada' => 1], $this->resumen($e->id));
        $this->assertTrue($chk->obtenerEjecucion($e->id)->premarcada);
        $this->assertTrue($chk->estadoEjecucion($e->id)['ejecucion']['premarcada']);

        // Un toque: terminar sin marcar nada.
        $chk->completar($e->id, $this->ana);
        $this->assertEstado('101', 'completada_pendiente_auditoria');
    }

    public function testDiaNocheTambienYDiaNo(): void
    {
        $this->habs->marcarNochero($this->hab['101'], $this->hoy, $this->sofia, null, '09:00', Habitacion::NOCHERO_DIA_NOCHE);
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $this->asig->asignarManual($this->hab['102'], $this->berta, $this->hoy);
        $chk = $this->chkALas('17:00');

        $e1 = $chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        $this->assertSame(1, $this->resumen($e1->id)['premarcada']);

        $e2 = $chk->iniciarEjecucion($this->hab['102'], $this->berta, $this->hoy);
        $this->assertSame(['marcados' => 0, 'de_ana' => 0, 'premarcada' => 0], $this->resumen($e2->id), 'Día: arranca de cero');
    }

    public function testAntesDeLaHoraNo(): void
    {
        $this->habs->marcarNochero($this->hab['101'], $this->hoy, $this->sofia, null, '09:00', Habitacion::NOCHERO_NOCHE);
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $e = $this->chkALas('15:59')->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        $this->assertSame(['marcados' => 0, 'de_ana' => 0, 'premarcada' => 0], $this->resumen($e->id));
    }

    public function testRespetaLaHoraDeAjustes(): void
    {
        $this->habs->marcarNochero($this->hab['101'], $this->hoy, $this->sofia, null, '09:00', Habitacion::NOCHERO_NOCHE);
        // Asignada antes de mover la hora: desde la v7.25 una pieza Noche no se asigna antes de la hora de la tarde.
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        (new HoraTardeService())->guardar('18:00', $this->sofia);

        $e = $this->chkALas('17:30')->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        $this->assertSame(0, $this->resumen($e->id)['premarcada'], 'a las 17:30 todavía no es la tarde si la hora es 18:00');
    }

    public function testMarcaVencidaNo(): void
    {
        $ayer = date('Y-m-d', (int) strtotime($this->hoy . ' -1 day'));
        Database::execute("UPDATE habitaciones SET es_nochero = 1, nochero_hasta = ?, nochero_modo = 'noche' WHERE id = ?", [$ayer, $this->hab['101']]);
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $e = $this->chkALas('17:00')->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        $this->assertSame(0, $this->resumen($e->id)['premarcada']);
    }

    public function testLaReLimpiezaDeUnRechazoNoSePremarca(): void
    {
        $this->habs->marcarNochero($this->hab['101'], $this->hoy, $this->sofia, null, '09:00', Habitacion::NOCHERO_NOCHE);
        $chk = $this->chkALas('17:00');
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $e = $chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        $chk->completar($e->id, $this->ana);
        $this->rechazar('101');

        $this->asig->asignarManual($this->hab['101'], $this->berta, $this->hoy);
        $re = $chk->iniciarEjecucion($this->hab['101'], $this->berta, $this->hoy);
        $resumen = $this->resumen($re->id);
        $this->assertSame(0, $resumen['premarcada'], 'la re-limpieza hereda lo que quedó bien y corrige lo desmarcado');
        $this->assertSame($this->totalItems($re->templateId) - 2, $resumen['marcados'], 'hereda menos los 2 que desmarcó la supervisora');
    }

    public function testSinCambioDeSabanasNoMarcaLosItemsDeSabanas(): void
    {
        $this->habs->marcarNochero($this->hab['101'], $this->hoy, $this->sofia, null, '09:00', Habitacion::NOCHERO_NOCHE);
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        // Un ítem de sábanas en el checklist de la pieza y una ocupación que hoy no lleva cambio.
        $tpl = (int) Database::fetchColumn('SELECT template_id FROM items_checklist LIMIT 1');
        Database::execute("INSERT INTO items_checklist (template_id, orden, descripcion, obligatorio, es_cambio_sabanas) VALUES (?, 99, 'Cambiar sábanas', 1, 1)", [$tpl]);
        Database::execute("UPDATE habitaciones SET cb_frontdesk_status = 'stayover', cb_arrival_date = ?, cb_ocupacion_sync_at = ? WHERE id = ?", [$this->hoy, date('c'), $this->hab['101']]);

        $e = $this->chkALas('17:00')->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        if ($e->conCambioSabanas) {
            $this->markTestSkipped('La ocupación simulada lleva cambio de sábanas: nada que probar.');
        }
        $sabanas = (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM ejecuciones_items ei JOIN items_checklist ic ON ic.id = ei.item_id WHERE ei.ejecucion_id = ? AND ic.es_cambio_sabanas = 1',
            [$e->id]
        );
        $this->assertSame(0, $sabanas);
        $this->assertSame(1, $this->resumen($e->id)['premarcada']);
    }

    public function testElTiempoPromedioNoCuentaLasPremarcadas(): void
    {
        $svc = new AlertasPredictivasService();
        // 5 limpiezas normales de 30 min y 5 premarcadas de 1 min: el promedio sigue en 30.
        $asig = (int) Database::fetchColumn('SELECT id FROM asignaciones LIMIT 1');
        if ($asig === 0) {
            $this->asig->asignarManual($this->hab['103'], $this->ana, $this->hoy);
            $asig = (int) Database::fetchColumn('SELECT id FROM asignaciones LIMIT 1');
        }
        $tpl = (int) Database::fetchColumn('SELECT id FROM checklists_template LIMIT 1');
        for ($i = 0; $i < 10; $i++) {
            $premarcada = $i % 2;
            $min = $premarcada ? 1 : 30;
            Database::execute(
                "INSERT INTO ejecuciones_checklist (habitacion_id, asignacion_id, usuario_id, template_id, estado, timestamp_inicio, timestamp_fin, premarcada)
                 VALUES (?, ?, ?, ?, 'auditada', ?, ?, ?)",
                [$this->hab['103'], $asig, $this->ana, $tpl, '2026-11-01T12:00:00.000Z', sprintf('2026-11-01T12:%02d:00.000Z', $min), $premarcada]
            );
        }
        $this->assertSame(30, $svc->tiempoPromedioPersonal($this->ana));
    }

    public function testLaMigracionAgregaLaColumna(): void
    {
        Database::pdo()->exec('ALTER TABLE ejecuciones_checklist DROP COLUMN premarcada');
        EsquemaService::limpiarCache();
        $this->assertContains('ejecuciones_checklist.premarcada', (new EsquemaService())->faltantes(true)['columnas']);

        ob_start();
        try {
            include dirname(__DIR__, 2) . '/scripts/migrate-add-premarcada.php';
            include dirname(__DIR__, 2) . '/scripts/migrate-add-premarcada.php';
        } finally {
            $salida = (string) ob_get_clean();
        }
        EsquemaService::limpiarCache();
        $this->assertStringContainsString('ya existe', $salida, 'correrla dos veces no falla');
        $esquema = (new EsquemaService())->faltantes(true);
        $this->assertTrue($esquema['ok'], 'faltan: ' . json_encode($esquema));
    }
}
