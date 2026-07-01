<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaException;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class AuditoriaServiceTest extends TestCase
{
    private AuditoriaService $svc;
    private int $habitacionId;
    private int $trabajadorId;
    private int $auditorId;
    private int $ejecucionId;
    private int $itemFallidoId;
    private string $fecha = '2026-04-14';

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();

        $hotelId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $tipoId = (int) Database::fetchOne("SELECT id FROM tipos_habitacion LIMIT 1")['id'];

        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, '101', ?, 'sucia')",
            [$hotelId, $tipoId]
        );
        $this->habitacionId = Database::lastInsertId();

        [$this->trabajadorId] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        [$this->auditorId] = TestDatabase::crearUsuario('22222222-2', 'Eva', 'Supervisora');

        $asig = new AsignacionService();
        $asig->asignarManual($this->habitacionId, $this->trabajadorId, $this->fecha);

        $checklist = new ChecklistService();
        $ejec = $checklist->iniciarEjecucion($this->habitacionId, $this->trabajadorId, $this->fecha);

        $items = $checklist->itemsDelTemplate($ejec->templateId);
        foreach ($items as $item) {
            if ((int) $item['obligatorio'] === 1) {
                $checklist->marcarItem($ejec->id, (int) $item['id'], true, $this->trabajadorId);
            }
        }
        $checklist->completar($ejec->id, $this->trabajadorId);
        $this->ejecucionId = $ejec->id;
        $this->itemFallidoId = (int) $items[0]['id']; // un obligatorio, para los rechazos (exigen ≥1 ítem fallido)

        $this->svc = new AuditoriaService();
    }

    public function testObtenerDeEjecucionDistingueRelimpiezaPendiente(): void
    {
        // Escenario del bug: se rechaza la ejecución #1, se reasigna y re-limpia (ejecución #2
        // pendiente). obtenerDeEjecucion debe scoping por ejecución: null para la #2 (sin auditar),
        // aunque obtenerDeHabitacion siga devolviendo el rechazo de la #1. Sin esto, la pantalla
        // de auditoría ocultaría los botones de veredicto de la re-limpieza.
        $this->svc->emitirVeredicto(
            $this->habitacionId,
            $this->auditorId,
            Auditoria::VEREDICTO_RECHAZADO,
            'Faltó limpiar el baño a fondo.',
            [$this->itemFallidoId]
        );

        [$trabajador2] = TestDatabase::crearUsuario('33333333-3', 'Berta', 'Trabajador');
        (new AsignacionService())->reasignar($this->habitacionId, $trabajador2, $this->fecha, 're-limpieza');

        $checklist = new ChecklistService();
        $ejec2 = $checklist->iniciarEjecucion($this->habitacionId, $trabajador2, $this->fecha);
        foreach ($checklist->itemsDelTemplate($ejec2->templateId) as $item) {
            if ((int) $item['obligatorio'] === 1) {
                $checklist->marcarItem($ejec2->id, (int) $item['id'], true, $trabajador2);
            }
        }
        $checklist->completar($ejec2->id, $trabajador2);

        // La ejecución vieja tiene su rechazo; la nueva (pendiente) no tiene auditoría.
        $this->assertSame('rechazado', $this->svc->obtenerDeEjecucion($this->ejecucionId)?->veredicto);
        $this->assertNull($this->svc->obtenerDeEjecucion($ejec2->id));
        // obtenerDeHabitacion sí devolvería el rechazo viejo (el comportamiento que causaba el bug).
        $this->assertSame('rechazado', $this->svc->obtenerDeHabitacion($this->habitacionId)?->veredicto);
    }

    public function testAlertaDeRechazoSeResuelveAlReasignar(): void
    {
        $this->svc->emitirVeredicto(
            $this->habitacionId,
            $this->auditorId,
            Auditoria::VEREDICTO_RECHAZADO,
            'Faltó limpiar el baño a fondo.',
            [$this->itemFallidoId]
        );
        // El rechazo levanta la alerta P1.
        $this->assertNotNull(
            Database::fetchOne("SELECT id FROM alertas_activas WHERE tipo = 'habitacion_rechazada'")
        );

        [$trabajador2] = TestDatabase::crearUsuario('33333333-3', 'Berta', 'Trabajador');
        (new AsignacionService())->reasignar($this->habitacionId, $trabajador2, $this->fecha, 're-limpieza');

        // Al reasignar (rechazada→sucia) la alerta se resuelve y ya no queda activa.
        $this->assertNull(
            Database::fetchOne("SELECT id FROM alertas_activas WHERE tipo = 'habitacion_rechazada'")
        );
    }

    public function testAprobadoCambiaEstadoYMarcaEjecucionAuditada(): void
    {
        $auditoria = $this->svc->emitirVeredicto(
            $this->habitacionId,
            $this->auditorId,
            Auditoria::VEREDICTO_APROBADO
        );

        $this->assertSame('aprobado', $auditoria->veredicto);

        $hab = Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$this->habitacionId]);
        $this->assertSame(Habitacion::ESTADO_APROBADA, $hab['estado']);

        $ejec = Database::fetchOne('SELECT estado FROM ejecuciones_checklist WHERE id = ?', [$this->ejecucionId]);
        $this->assertSame('auditada', $ejec['estado']);
    }

    public function testAprobadoConObservacionRequiereComentario(): void
    {
        try {
            $this->svc->emitirVeredicto(
                $this->habitacionId,
                $this->auditorId,
                Auditoria::VEREDICTO_APROBADO_CON_OBSERVACION
            );
            $this->fail('Debía lanzar');
        } catch (AuditoriaException $e) {
            $this->assertSame('COMENTARIO_REQUERIDO', $e->codigo);
        }
    }

    public function testAprobadoConObservacionDesmarcaItems(): void
    {
        $items = Database::fetchAll(
            'SELECT id FROM items_checklist WHERE template_id = (SELECT template_id FROM ejecuciones_checklist WHERE id = ?) ORDER BY orden',
            [$this->ejecucionId]
        );
        $itemIds = [(int) $items[0]['id'], (int) $items[1]['id']];

        $auditoria = $this->svc->emitirVeredicto(
            $this->habitacionId,
            $this->auditorId,
            Auditoria::VEREDICTO_APROBADO_CON_OBSERVACION,
            'Faltaba reponer jabón. Ya resuelto.',
            $itemIds
        );

        $this->assertSame($itemIds, $auditoria->itemsDesmarcados);

        $hab = Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$this->habitacionId]);
        $this->assertSame(Habitacion::ESTADO_APROBADA_CON_OBSERVACION, $hab['estado']);

        foreach ($itemIds as $itemId) {
            $ei = Database::fetchOne(
                'SELECT marcado, desmarcado_por_auditor FROM ejecuciones_items WHERE ejecucion_id = ? AND item_id = ?',
                [$this->ejecucionId, $itemId]
            );
            $this->assertSame(0, (int) $ei['marcado']);
            $this->assertSame(1, (int) $ei['desmarcado_por_auditor']);
        }
    }

    public function testRechazadoGeneraAlertaP1YCambiaEstado(): void
    {
        $this->svc->emitirVeredicto(
            $this->habitacionId,
            $this->auditorId,
            Auditoria::VEREDICTO_RECHAZADO,
            'Baño sin limpiar. Requiere re-limpieza.',
            [$this->itemFallidoId]
        );

        $hab = Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$this->habitacionId]);
        $this->assertSame(Habitacion::ESTADO_RECHAZADA, $hab['estado']);

        $alerta = Database::fetchOne(
            "SELECT * FROM alertas_activas WHERE tipo = 'habitacion_rechazada'"
        );
        $this->assertNotNull($alerta);
        $this->assertSame(1, (int) $alerta['prioridad']);
    }

    public function testRechazoSinItemsFallidosLanza(): void
    {
        try {
            $this->svc->emitirVeredicto(
                $this->habitacionId,
                $this->auditorId,
                Auditoria::VEREDICTO_RECHAZADO,
                'Comentario de rechazo válido pero sin ítems.'
            );
            $this->fail('Debía exigir al menos un ítem fallido');
        } catch (AuditoriaException $e) {
            $this->assertSame('ITEMS_FALLIDOS_REQUERIDOS', $e->codigo);
            $this->assertSame(400, $e->httpStatus);
        }
    }

    public function testRechazoDesmarcaLosItemsFallidos(): void
    {
        $this->svc->emitirVeredicto(
            $this->habitacionId,
            $this->auditorId,
            Auditoria::VEREDICTO_RECHAZADO,
            'Faltó el baño a fondo, rehacer.',
            [$this->itemFallidoId]
        );

        // El ítem fallido queda desmarcado y sin atribución (libre para la re-limpieza).
        $fila = Database::fetchOne(
            'SELECT marcado, desmarcado_por_auditor, marcado_por FROM ejecuciones_items WHERE ejecucion_id = ? AND item_id = ?',
            [$this->ejecucionId, $this->itemFallidoId]
        );
        $this->assertSame(0, (int) $fila['marcado']);
        $this->assertSame(1, (int) $fila['desmarcado_por_auditor']);
        $this->assertNull($fila['marcado_por']);

        // Se guarda el JSON de ítems desmarcados en la auditoría.
        $json = Database::fetchOne(
            'SELECT items_desmarcados_json FROM auditorias WHERE ejecucion_id = ?',
            [$this->ejecucionId]
        );
        $this->assertStringContainsString((string) $this->itemFallidoId, (string) $json['items_desmarcados_json']);
    }

    public function testInmutabilidadRechaza409(): void
    {
        $this->svc->emitirVeredicto($this->habitacionId, $this->auditorId, Auditoria::VEREDICTO_APROBADO);

        try {
            $this->svc->emitirVeredicto($this->habitacionId, $this->auditorId, Auditoria::VEREDICTO_APROBADO);
            $this->fail('Debía lanzar AUDITORIA_YA_EXISTE (estado cambió)');
        } catch (AuditoriaException $e) {
            // HABITACION_NO_PENDIENTE porque estado ya no es pendiente tras aprobación.
            $this->assertSame('HABITACION_NO_PENDIENTE', $e->codigo);
            $this->assertSame(409, $e->httpStatus);
        }
    }

    public function testComentarioMuyCortoLanza(): void
    {
        try {
            $this->svc->emitirVeredicto(
                $this->habitacionId,
                $this->auditorId,
                Auditoria::VEREDICTO_RECHAZADO,
                'corto'
            );
            $this->fail('Debía lanzar');
        } catch (AuditoriaException $e) {
            $this->assertSame('COMENTARIO_REQUERIDO', $e->codigo);
        }
    }

    public function testHabitacionNoPendienteLanza(): void
    {
        Database::execute(
            "UPDATE habitaciones SET estado = 'sucia' WHERE id = ?",
            [$this->habitacionId]
        );
        try {
            $this->svc->emitirVeredicto($this->habitacionId, $this->auditorId, Auditoria::VEREDICTO_APROBADO);
            $this->fail('Debía lanzar');
        } catch (AuditoriaException $e) {
            $this->assertSame('HABITACION_NO_PENDIENTE', $e->codigo);
        }
    }

    public function testBandejaPendientes(): void
    {
        $pendientes = $this->svc->bandejaPendientes('1_sur');
        $this->assertCount(1, $pendientes);
        $this->assertSame('101', (string) $pendientes[0]['numero']);

        $this->svc->emitirVeredicto($this->habitacionId, $this->auditorId, Auditoria::VEREDICTO_APROBADO);

        $this->assertCount(0, $this->svc->bandejaPendientes('1_sur'));
    }

    public function testItemsDesmarcadosEnAprobadoLimpioLanza(): void
    {
        try {
            $this->svc->emitirVeredicto(
                $this->habitacionId,
                $this->auditorId,
                Auditoria::VEREDICTO_APROBADO,
                null,
                [1, 2]
            );
            $this->fail('Debía lanzar');
        } catch (AuditoriaException $e) {
            $this->assertSame('ITEMS_DESMARCADOS_NO_APLICABLE', $e->codigo);
        }
    }
}
