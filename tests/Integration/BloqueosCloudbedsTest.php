<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\AlertaActiva;
use Atankalama\Limpieza\Services\AsignacionException;
use Atankalama\Limpieza\Services\BloqueosService;
use Atankalama\Limpieza\Tests\Support\EscenarioCicloLimpieza;
use PHPUnit\Framework\TestCase;

/**
 * Habitaciones en mantención o cerradas en Cloudbeds (v7.8, decisiones de Nicolás del 15/10/2026),
 * de punta a punta contra el Cloudbeds simulado: el sync anota el bloqueo, la pieza sale de la cola de
 * hoy y no se asigna; al terminar el bloqueo se genera su limpieza completa (sucia, aviso a Cloudbeds,
 * alerta P2 sin asignar, checklist con sábanas). Cada tipo tiene su interruptor en Ajustes → Cloudbeds.
 * Ver docs/cloudbeds.md §4.5.
 */
final class BloqueosCloudbedsTest extends TestCase
{
    use EscenarioCicloLimpieza;

    protected function setUp(): void
    {
        $this->prepararEscenario();
    }

    public function testLaMantencionSeAnotaYSacaLaPiezaDeLaColaConAviso(): void
    {
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $hasta = date('Y-m-d', strtotime('+3 days'));
        $this->cb->bloquear('CB_101', 'out_of_service', 'Cambio de alfombra', $hasta);

        $this->sincronizar();

        $fila = $this->fila('101');
        $this->assertSame('mantencion', $fila['cb_bloqueo_tipo']);
        $this->assertSame('Cambio de alfombra', $fila['cb_bloqueo_motivo']);
        $this->assertSame($hasta, $fila['cb_bloqueo_hasta']);
        $this->assertNull($this->asig->obtenerActivaDeHabitacion($this->hab['101'], $this->hoy), 'salió de la cola de Ana');
        $aviso = Database::fetchOne(
            "SELECT titulo, cuerpo FROM notificaciones WHERE usuario_id = ? AND titulo = 'Habitación retirada de tu cola' ORDER BY id DESC",
            [$this->ana]
        );
        $this->assertNotNull($aviso);
        $this->assertStringContainsString('en mantención', (string) $aviso['cuerpo']);
    }

    public function testUnaPiezaBloqueadaNoSeAsignaHoyPeroSiSePreasigna(): void
    {
        $this->cb->bloquear('CB_101', 'blocked_dates', 'Remodelación');
        $this->sincronizar();

        try {
            $this->asig->asignarManual($this->hab['101'], $this->berta, $this->hoy);
            $this->fail('Debía lanzar HABITACION_BLOQUEADA');
        } catch (AsignacionException $e) {
            $this->assertSame('HABITACION_BLOQUEADA', $e->codigo);
            $this->assertStringContainsString('cerrada', $e->getMessage());
        }

        $manana = date('Y-m-d', strtotime('+1 day'));
        $this->asig->asignarManual($this->hab['101'], $this->berta, $manana);
        $this->assertNotNull($this->asig->obtenerActivaDeHabitacion($this->hab['101'], $manana));

        // Tampoco aparece en el pool de hoy del tablero.
        $pool = $this->asig->vistaConsolidada('1_sur', $this->hoy)['sin_asignar'];
        $this->assertNotContains($this->hab['101'], array_map(static fn(array $h) => (int) $h['id'], $pool));
    }

    public function testSiYaLaEstabaLimpiandoLaTermina(): void
    {
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        $this->cb->bloquear('CB_101');

        $this->sincronizar();

        $this->assertNotNull($this->asig->obtenerActivaDeHabitacion($this->hab['101'], $this->hoy), 'no se le quita a mitad de limpieza');
        $this->terminar($e->id, $this->ana);
        $this->assertEstado('101', 'completada_pendiente_auditoria');
    }

    public function testAlTerminarLaMantencionSeGeneraLaLimpiezaCompletaSinAsignar(): void
    {
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        // Pieza libre que hoy recibe a alguien: por la regla normal NO lleva sábanas.
        $this->cb->pieza('CB_101', 'clean', 'check-in', false);
        $this->cb->bloquear('CB_101', 'out_of_service', 'Gasfitería');
        $this->sincronizar();
        $this->assertEstado('101', 'aprobada', 'mientras dura, la condición de Cloudbeds manda como siempre');

        $this->cb->desbloquear('CB_101');
        $escriturasAntes = count($this->cb->escrituras);
        $this->sincronizar();

        $fila = $this->fila('101');
        $this->assertNull($fila['cb_bloqueo_tipo']);
        $this->assertSame(1, (int) $fila['limpieza_completa_pendiente']);
        $this->assertEstado('101', 'sucia');
        $this->assertSame(['roomID' => 'CB_101', 'condicion' => 'dirty'], $this->cb->escrituras[$escriturasAntes] ?? null, 'se le avisa a Cloudbeds');

        $this->assertNull(
            $this->asig->obtenerActivaDeHabitacion($this->hab['101'], $this->hoy),
            'queda sin asignar: no vuelve sola a la cola de Ana, que la limpió antes del bloqueo'
        );
        $alerta = $this->alertaSalida('101');
        $this->assertNotNull($alerta, 'aviso P2 a la supervisora');
        $this->assertSame(2, (int) $alerta['prioridad']);
        $this->assertSame('Habitación 101 salió de mantención', $alerta['titulo']);

        // Aunque Cloudbeds la vuelva a dar limpia, no se da por limpia hasta hacer la limpieza completa.
        $this->cb->pieza('CB_101', 'clean', 'check-in', false);
        $this->sincronizar();
        $this->assertEstado('101', 'sucia');

        // Asignarla cierra el aviso; la limpieza lleva sábanas aunque la regla normal no lo pediría.
        $this->asig->asignarManual($this->hab['101'], $this->berta, $this->hoy);
        $this->assertNull($this->alertaSalida('101'), 'el aviso se cierra al asignarla');
        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->berta, $this->hoy);
        $this->assertTrue($e->conCambioSabanas);
        $this->terminar($e->id, $this->berta);
        $this->assertSame(0, (int) $this->fila('101')['limpieza_completa_pendiente'], 'se apaga al terminarla');
    }

    /** Si alguien la estaba limpiando durante todo el bloqueo, al salir no hay aviso: ya tiene quién. */
    public function testSiYaTieneQuienLaLimpieHoyNoHayAviso(): void
    {
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $e = $this->chk->iniciarEjecucion($this->hab['101'], $this->ana, $this->hoy);
        $this->cb->bloquear('CB_101');
        $this->sincronizar();
        $this->cb->desbloquear('CB_101');
        $this->sincronizar();

        $this->assertNull($this->alertaSalida('101'));
        $this->assertEstado('101', 'en_progreso', 'su limpieza en curso sigue');
        $this->assertSame(1, (int) $this->fila('101')['limpieza_completa_pendiente']);
        $this->terminar($e->id, $this->ana);
        $this->assertSame(0, (int) $this->fila('101')['limpieza_completa_pendiente']);
    }

    public function testConElInterruptorApagadoSoloSeQuitaLaEtiqueta(): void
    {
        (new BloqueosService())->configurar(BloqueosService::TIPO_CERRADA, false, $this->sofia);
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $this->cb->bloquear('CB_101', 'blocked_dates', 'Bodega');
        $this->sincronizar();
        $this->assertSame('cerrada', $this->fila('101')['cb_bloqueo_tipo']);

        $this->cb->desbloquear('CB_101');
        $this->sincronizar();

        $fila = $this->fila('101');
        $this->assertNull($fila['cb_bloqueo_tipo']);
        $this->assertSame(0, (int) $fila['limpieza_completa_pendiente']);
        $this->assertEstado('101', 'aprobada');
        $this->assertNull($this->alertaSalida('101'));

        // El de mantención sigue prendido: son interruptores separados.
        $this->assertSame(['mantencion' => true, 'cerrada' => false], (new BloqueosService())->config());
    }

    public function testSinPermisoParaLeerBloqueosQuedaBloqueadaSinTipo(): void
    {
        $this->cb->fallarBloqueos = true;
        $this->limpiar('101', $this->ana);
        $this->aprobar('101');
        $this->cb->bloquear('CB_101', 'blocked_dates');
        $this->sincronizar();
        $this->assertSame('bloqueada', $this->fila('101')['cb_bloqueo_tipo'], 'solo se sabe que está bloqueada');

        // Al salir sigue el interruptor de mantención (prendido por defecto).
        $this->cb->desbloquear('CB_101');
        $this->sincronizar();
        $this->assertEstado('101', 'sucia');
        $this->assertSame('Habitación 101 dejó de estar bloqueada en Cloudbeds', $this->alertaSalida('101')['titulo'] ?? null);
    }

    public function testLaReservaProvisoriaNoEsUnBloqueo(): void
    {
        $this->asig->asignarManual($this->hab['101'], $this->ana, $this->hoy);
        $this->cb->bloquear('CB_101', 'courtesy_hold', null);
        $this->sincronizar();

        $this->assertNull($this->fila('101')['cb_bloqueo_tipo']);
        $this->assertNotNull($this->asig->obtenerActivaDeHabitacion($this->hab['101'], $this->hoy));
    }

    public function testElAvisoSeCierraSiVuelveABloquearse(): void
    {
        $this->cb->bloquear('CB_101');
        $this->sincronizar();
        $this->cb->desbloquear('CB_101');
        $this->sincronizar();
        $this->assertNotNull($this->alertaSalida('101'));

        $this->cb->bloquear('CB_101');
        $this->sincronizar();
        $this->assertNull($this->alertaSalida('101'));
    }

    /** @return array<string, mixed> */
    private function fila(string $numero): array
    {
        return Database::fetchOne(
            'SELECT cb_bloqueo_tipo, cb_bloqueo_motivo, cb_bloqueo_hasta, limpieza_completa_pendiente FROM habitaciones WHERE id = ?',
            [$this->hab[$numero]]
        ) ?? [];
    }

    /** @return array<string, mixed>|null */
    private function alertaSalida(string $numero): ?array
    {
        return Database::fetchOne(
            'SELECT titulo, prioridad FROM alertas_activas WHERE tipo = ? AND contexto_json LIKE ?',
            [AlertaActiva::TIPO_HABITACION_SALIO_BLOQUEO, '%"_dedupe":"' . BloqueosService::dedupe($this->hab[$numero]) . '"%']
        );
    }
}
