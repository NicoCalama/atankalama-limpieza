<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\CierreDiaService;
use Atankalama\Limpieza\Services\EspacioService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Cierre de la noche con limpiezas sin terminar (v6.19, decisión de Nicolás del 08/10/2026): toda
 * limpieza que siga en progreso, de habitaciones y de áreas comunes, se termina y la pieza queda
 * aprobada para liberarla al día siguiente; quien no apretó «terminar» no recibe créditos de ella.
 * Ver CierreDiaService::cerrarSinTerminar() y ReportesService::NO_CERRADA_POR_SISTEMA.
 *
 *   Ana   limpia y TERMINA la 410 (queda por inspeccionar)      → créditos normales
 *   Ana   abre la Piscina y marca todo, sin apretar «terminar»  → aprobada, sin créditos
 *   Beto  abre la 305 y marca una casilla                       → aprobada, sin créditos
 */
final class CierreSinTerminarTest extends TestCase
{
    private AsignacionService $asig;
    private ChecklistService $checklist;
    private EspacioService $espacios;
    private CierreDiaService $cierre;
    private string $hoy;
    private int $ana;
    private int $beto;
    private int $sofia;
    private int $sistema;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();
        [$this->ana]     = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        [$this->beto]    = TestDatabase::crearUsuario('33333333-3', 'Beto', 'Trabajador');
        [$this->sofia]   = TestDatabase::crearUsuario('22222222-2', 'Sofía', 'Supervisora');
        [$this->sistema] = TestDatabase::crearUsuario('SISTEMA-CRON', 'Sistema', 'Admin');
        $this->hoy       = date('Y-m-d');
        $this->asig      = new AsignacionService();
        $this->checklist = new ChecklistService();
        $this->espacios  = new EspacioService();
        $this->cierre    = new CierreDiaService();
    }

    public function testLasSinTerminarQuedanAprobadasPeroSinCreditos(): void
    {
        $terminada = $this->crearHabitacion('410');
        $this->asig->asignarManual($terminada, $this->ana, $this->hoy);
        $ejecTerminada = $this->checklist->iniciarEjecucion($terminada, $this->ana, $this->hoy);
        $this->marcarTodo($ejecTerminada->id, $this->ana);
        $this->checklist->completar($ejecTerminada->id, $this->ana);

        $piscina = $this->espacios->crear('Piscina', '1_sur', ['Barrer', 'Vidrios', 'Cloro']);
        $this->espacios->pedirLimpieza($piscina, $this->ana, $this->hoy);
        $ejecPiscina = $this->checklist->iniciarEjecucion($piscina, $this->ana, $this->hoy);
        $this->marcarTodo($ejecPiscina->id, $this->ana); // marca todo y no aprieta «terminar»

        $pieza = $this->crearHabitacion('305');
        $this->asig->asignarManual($pieza, $this->beto, $this->hoy);
        $ejecPieza = $this->checklist->iniciarEjecucion($pieza, $this->beto, $this->hoy);
        $primerItem = (int) $this->checklist->estadoEjecucion($ejecPieza->id)['items'][0]['id'];
        $this->checklist->marcarItem($ejecPieza->id, $primerItem, true, $this->beto);

        $cerradas = $this->cierre->cerrarSinTerminar();
        $r = $this->cierre->aprobarPendientes($this->sistema, null, array_column($cerradas, 'habitacion_id'));
        $this->cierre->avisarCerradasSinTerminar($cerradas, $this->hoy);

        $this->assertSame([$pieza, $piscina], array_column($cerradas, 'habitacion_id'));
        $this->assertSame(['aprobadas' => 3, 'fallidas' => 0], $r);
        $this->assertSame(0, (int) Database::fetchColumn("SELECT COUNT(*) FROM ejecuciones_checklist WHERE estado = 'en_progreso'"));
        foreach ([$terminada, $piscina, $pieza] as $id) {
            $this->assertSame('aprobada_automatica', $this->estado($id), 'quedan libres para mañana');
        }
        $this->assertSame(0, $this->cerradaPorSistema($ejecTerminada->id));
        $this->assertSame(1, $this->cerradaPorSistema($ejecPiscina->id));
        $this->assertSame(1, $this->cerradaPorSistema($ejecPieza->id));
        $this->assertSame(CierreDiaService::COMENTARIO, $this->comentarioAuditoria($terminada));
        $this->assertSame(CierreDiaService::COMENTARIO_SIN_TERMINAR, $this->comentarioAuditoria($piscina));
        $this->assertSame(CierreDiaService::COMENTARIO_SIN_TERMINAR, $this->comentarioAuditoria($pieza));

        // Créditos: Ana cobra solo la 410; la Piscina (todo marcado) y la 305 de Beto no suman nada.
        $rep = new ReportesService();
        $ficha = array_column($rep->fichaKpis($this->hoy, $this->hoy, 'ambos', false)['trabajadores'], null, 'nombre');
        $creditosDe410 = (int) Database::fetchColumn(
            'SELECT SUM(ic.creditos) FROM items_checklist ic WHERE ic.template_id = ? AND ic.obligatorio = 1 AND ic.activo = 1',
            [$ejecTerminada->templateId]
        );
        $this->assertSame($creditosDe410, $ficha['Ana']['creditos']);
        $this->assertSame(1, $ficha['Ana']['habitaciones']);
        $this->assertSame(0, $ficha['Beto']['creditos']);
        $this->assertSame(0, $ficha['Beto']['habitaciones']);
        // La 305 le sigue contando como asignada y no hecha, y ese día como trabajado.
        $this->assertSame(1, $ficha['Beto']['esperado_hab']);
        $this->assertSame(1, $ficha['Beto']['dias_trabajados']);
        $this->assertSame(0, $ficha['Beto']['ejecuciones'], 'no entra a los tiempos');

        // El resumen mensual (el de sueldos) dice lo mismo.
        $mensual = array_column($rep->resumenMensual((int) date('Y'), (int) date('n'), 'ambos'), null, 'nombre');
        $this->assertSame($creditosDe410, $mensual['Ana']['creditos']);
        $this->assertSame(0, $mensual['Beto']['creditos']);

        // «Inspecciones pendientes al corte» no las lista: nadie las terminó.
        $pendientes = $rep->auditoriasPendientes($this->hoy, 'ambos')['turnos'];
        $numeros = array_column(array_merge($pendientes['mañana']['pendientes'], $pendientes['tarde']['pendientes']), 'numero');
        $this->assertSame(['410'], $numeros);

        // Campanita a la supervisora, no a las trabajadoras.
        $avisos = $this->avisos($this->sofia);
        $this->assertCount(1, $avisos);
        $this->assertSame('2 limpiezas sin terminar al cerrar el día', $avisos[0]['titulo']);
        $this->assertStringContainsString('305 (1 Sur), de Beto · Piscina (1 Sur), de Ana', $avisos[0]['cuerpo']);
        $this->assertStringContainsString('no suman créditos', $avisos[0]['cuerpo']);
        $this->assertSame('/habitaciones', $avisos[0]['url']);
        $this->assertSame([], $this->avisos($this->ana));
        $this->assertSame([], $this->avisos($this->beto));
    }

    public function testLaLimpiezaColgadaDeUnaPiezaQueYaSiguioSoloSeCierra(): void
    {
        // Ana empieza la 410 y se la pasan a Beto (un Admin), que la termina: la de Ana queda colgada.
        $pieza = $this->crearHabitacion('410');
        $this->asig->asignarManual($pieza, $this->ana, $this->hoy);
        $ejecAna = $this->checklist->iniciarEjecucion($pieza, $this->ana, $this->hoy);
        $primerItem = (int) $this->checklist->estadoEjecucion($ejecAna->id)['items'][0]['id'];
        $this->checklist->marcarItem($ejecAna->id, $primerItem, true, $this->ana);
        $this->asig->reasignar($pieza, $this->beto, $this->hoy, 'Ana se fue', null, true);
        $ejecBeto = $this->checklist->iniciarEjecucion($pieza, $this->beto, $this->hoy);
        $this->marcarTodo($ejecBeto->id, $this->beto);
        $this->checklist->completar($ejecBeto->id, $this->beto);

        $cerradas = $this->cierre->cerrarSinTerminar();
        $this->cierre->aprobarPendientes($this->sistema, null, array_column($cerradas, 'habitacion_id'));
        $this->cierre->avisarCerradasSinTerminar($cerradas, $this->hoy);

        $this->assertSame([], $cerradas, 'la pieza ya estaba por inspeccionar: no es una limpieza sin terminar');
        $this->assertSame(1, $this->cerradaPorSistema($ejecAna->id));
        $this->assertSame('completada', (string) Database::fetchColumn('SELECT estado FROM ejecuciones_checklist WHERE id = ?', [$ejecAna->id]));
        // La aprobación es la de la limpieza de Beto, con el comentario de siempre.
        $this->assertSame($ejecBeto->id, (int) Database::fetchColumn('SELECT ejecucion_id FROM auditorias WHERE habitacion_id = ?', [$pieza]));
        $this->assertSame(CierreDiaService::COMENTARIO, $this->comentarioAuditoria($pieza));
        $this->assertSame('aprobada_automatica', $this->estado($pieza));
        $this->assertSame([], $this->avisos($this->sofia));

        $ficha = array_column((new ReportesService())->fichaKpis($this->hoy, $this->hoy, 'ambos', false)['trabajadores'], null, 'nombre');
        $this->assertSame(1, $ficha['Beto']['habitaciones']);
        $this->assertGreaterThan(0, $ficha['Beto']['creditos']);
        $this->assertSame(0, $ficha['Ana']['creditos'] ?? 0);
    }

    public function testUnaPiezaSinLimpiezaEnCursoNoSeToca(): void
    {
        $sucia = $this->crearHabitacion('101');
        $this->asig->asignarManual($sucia, $this->ana, $this->hoy);

        $this->assertSame([], $this->cierre->cerrarSinTerminar());
        $this->assertSame('sucia', $this->estado($sucia));
        $this->assertSame([], $this->cierre->limpiezasSinTerminar());
    }

    private function marcarTodo(int $ejecucionId, int $usuarioId): void
    {
        foreach ($this->checklist->estadoEjecucion($ejecucionId)['items'] as $item) {
            $this->checklist->marcarItem($ejecucionId, (int) $item['id'], true, $usuarioId);
        }
    }

    private function crearHabitacion(string $numero): int
    {
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado)
             VALUES ((SELECT id FROM hoteles WHERE codigo = '1_sur'), ?, (SELECT id FROM tipos_habitacion WHERE nombre = 'Doble'), 'sucia')",
            [$numero]
        );
        return Database::lastInsertId();
    }

    private function estado(int $habitacionId): string
    {
        return (string) Database::fetchColumn('SELECT estado FROM habitaciones WHERE id = ?', [$habitacionId]);
    }

    private function cerradaPorSistema(int $ejecucionId): int
    {
        return (int) Database::fetchColumn('SELECT cerrada_por_sistema FROM ejecuciones_checklist WHERE id = ?', [$ejecucionId]);
    }

    private function comentarioAuditoria(int $habitacionId): string
    {
        return (string) Database::fetchColumn('SELECT comentario FROM auditorias WHERE habitacion_id = ? ORDER BY id DESC LIMIT 1', [$habitacionId]);
    }

    /** @return list<array<string, mixed>> */
    private function avisos(int $usuarioId): array
    {
        return Database::fetchAll(
            'SELECT titulo, cuerpo, url FROM notificaciones WHERE usuario_id = ? AND tipo = ?',
            [$usuarioId, CierreDiaService::NOTIF_CERRADAS_SIN_TERMINAR]
        );
    }
}
