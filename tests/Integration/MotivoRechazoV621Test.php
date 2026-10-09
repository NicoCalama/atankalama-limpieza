<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Tests\Support\EscenarioCicloLimpieza;
use PHPUnit\Framework\TestCase;

/**
 * v6.21 · motivo del rechazo en la re-limpieza: al volver a limpiar una pieza rechazada, el
 * checklist trae lo que escribió la supervisora y marca los ítems que ella desmarcó
 * (ChecklistService::estadoEjecucion → 'rechazo' e ítems con 'rechazado_por_supervisora').
 * Misma regla que la herencia de ítems: solo en el mismo ciclo (mismo día y franja).
 */
final class MotivoRechazoV621Test extends TestCase
{
    use EscenarioCicloLimpieza;

    private const MOTIVO = 'Quedó polvo en el velador y el baño sin secar.';

    protected function setUp(): void
    {
        $this->prepararEscenario();
    }

    public function testLaMismaTrabajadoraVeElMotivoYLosItemsQueDesmarcoLaSupervisora(): void
    {
        $primera = $this->limpiar('101', $this->ana);
        $fallidos = $this->rechazar('101');

        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        $estado = $this->chk->estadoEjecucion($e->id);

        $this->assertNotNull($estado['rechazo']);
        $this->assertSame(self::MOTIVO, $estado['rechazo']['comentario']);
        $this->assertSame('Sofia', $estado['rechazo']['auditor_nombre']);
        $this->assertEqualsCanonicalizing($fallidos, $estado['rechazo']['items']);
        $this->assertSame($fallidos, $this->itemsEnRojo($estado), 'solo los desmarcados van en rojo');

        // El intento rechazado no es la re-limpieza de nada.
        $this->assertNull($this->chk->estadoEjecucion($primera)['rechazo']);
    }

    public function testElItemVuelveAMarcarseYSigueIdentificadoComoRechazado(): void
    {
        $this->limpiar('101', $this->ana);
        $fallidos = $this->rechazar('101');
        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);

        $this->chk->marcarItem($e->id, $fallidos[0], true, $this->ana);

        $estado = $this->chk->estadoEjecucion($e->id);
        $this->assertSame($fallidos, $this->itemsEnRojo($estado));
        $this->assertNotNull($estado['rechazo']);
    }

    public function testQuienRecibeLaPiezaReasignadaVeElMismoMotivo(): void
    {
        $this->limpiar('101', $this->ana);
        $fallidos = $this->rechazar('101');

        $this->asig->reasignar($this->hab['101'], $this->berta, $this->hoy, 're-limpieza');
        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->berta, $this->hoy);
        $estado = $this->chk->estadoEjecucion($e->id);

        $this->assertNotNull($estado['rechazo']);
        $this->assertSame(self::MOTIVO, $estado['rechazo']['comentario']);
        $this->assertSame($fallidos, $this->itemsEnRojo($estado));
    }

    public function testUnaLimpiezaNormalNoTraeMotivo(): void
    {
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        $estado = $this->chk->estadoEjecucion($e->id);

        $this->assertNull($estado['rechazo']);
        $this->assertSame([], $this->itemsEnRojo($estado));
        foreach ($estado['items'] as $item) {
            $this->assertSame(0, $item['rechazado_por_supervisora']);
        }
    }

    public function testUnRechazoDeAyerNoSeMuestraEnLaLimpiezaDeHoy(): void
    {
        $ayer = date('Y-m-d', (int) strtotime($this->hoy . ' -1 day'));
        $this->asig->asignarManual($this->hab['101'], $this->ana, $ayer);
        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $ayer);
        $this->terminar($e->id, $this->ana);
        $this->rechazar('101');

        // Hoy es otro aseo (R4, v6.17): parte de cero y sin el motivo de ayer.
        $this->asig->asignarManual($this->hab['101'], $this->berta, $this->hoy);
        $hoy = $this->chk->iniciarEjecucion($this->hab['101'], $this->berta, $this->hoy);
        $estado = $this->chk->estadoEjecucion($hoy->id);

        $this->assertNull($estado['rechazo']);
        $this->assertSame([], $this->itemsEnRojo($estado));
    }

    public function testDespuesDeAprobarLaRelimpiezaLaLimpiezaSiguienteNoTraeMotivo(): void
    {
        $this->limpiar('101', $this->ana);
        $this->rechazar('101');
        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        $this->terminar($e->id, $this->ana);
        $this->aprobar('101');

        // Se vuelve a ensuciar el mismo día (p. ej. un nochero): el último veredicto ya es una aprobación.
        $this->habs->cambiarEstado($this->hab['101'], Habitacion::ESTADO_SUCIA, $this->sofia, 'ui');
        $otra = $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);

        $this->assertNull($this->chk->estadoEjecucion($otra->id)['rechazo']);
    }

    public function testSiSeEditaElChecklistLosItemsSeEmparejanPorDescripcion(): void
    {
        $primera = $this->limpiar('101', $this->ana);
        $fallidosV1 = $this->rechazar('101');
        $itemsV1 = $this->chk->itemsDelTemplate($this->templateDe($primera));
        $descFallidas = [];
        $payload = [];
        foreach ($itemsV1 as $i) {
            if (in_array((int) $i['id'], $fallidosV1, true)) {
                $descFallidas[] = $i['descripcion'];
            }
            $payload[] = [
                'id' => (int) $i['id'],
                'descripcion' => $i['descripcion'],
                'obligatorio' => (int) $i['obligatorio'] === 1,
                'creditos' => (int) $i['creditos'],
            ];
        }

        // Un admin edita el checklist (mismos textos): nace la v2 con ids nuevos.
        $creada = $this->chk->editarTemplate($this->templateDe($primera), null, $payload, $this->sofia);

        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        $this->assertSame($creada['template_id'], $e->templateId);
        $estado = $this->chk->estadoEjecucion($e->id);

        $this->assertCount(2, $estado['rechazo']['items']);
        $enRojo = array_values(array_filter($estado['items'], static fn(array $i) => $i['rechazado_por_supervisora'] === 1));
        $this->assertEqualsCanonicalizing($descFallidas, array_column($enRojo, 'descripcion'));
        foreach ($enRojo as $item) {
            $this->assertNotContains((int) $item['id'], $fallidosV1, 'son los ítems de la v2');
        }
    }

    // ── Ayudas ───────────────────────────────────────────────────────────────

    /**
     * Rechaza el último intento de la pieza desmarcando sus dos primeros obligatorios.
     *
     * @return list<int> los ítems desmarcados
     */
    private function rechazar(string $numero): array
    {
        $e = (int) Database::fetchColumn(
            'SELECT id FROM ejecuciones_checklist WHERE habitacion_id = ? ORDER BY id DESC LIMIT 1',
            [$this->hab[$numero]]
        );
        $fallidos = array_slice($this->obligatorios($this->templateDe($e)), 0, 2);
        $this->aud->emitirVeredicto($this->hab[$numero], $this->sofia, Auditoria::VEREDICTO_RECHAZADO, self::MOTIVO, $fallidos);
        return $fallidos;
    }

    /**
     * @param array<string, mixed> $estado
     * @return list<int>
     */
    private function itemsEnRojo(array $estado): array
    {
        $ids = [];
        foreach ($estado['items'] as $item) {
            if ($item['rechazado_por_supervisora'] === 1) {
                $ids[] = (int) $item['id'];
            }
        }
        return $ids;
    }
}
