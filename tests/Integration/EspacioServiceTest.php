<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\EspacioException;
use Atankalama\Limpieza\Services\EspacioService;
use Atankalama\Limpieza\Services\HabitacionService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Áreas comunes (espacios). Ver docs/areas-comunes.md
 */
final class EspacioServiceTest extends TestCase
{
    private EspacioService $svc;
    private int $tipoPiezaId;
    private string $hoy;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('inn', 'Inn')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        Database::execute("INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES ('mañana', '08:00', '16:00')");

        $this->tipoPiezaId = (int) Database::fetchOne("SELECT id FROM tipos_habitacion WHERE nombre='Doble'")['id'];
        $this->hoy = date('Y-m-d');
        $this->svc = new EspacioService();
    }

    private function crearTrabajadorConTurno(string $rut, string $nombre): int
    {
        [$id] = TestDatabase::crearUsuario($rut, $nombre, 'Trabajador');
        $turnoId = (int) Database::fetchOne('SELECT id FROM turnos LIMIT 1')['id'];
        Database::execute(
            'INSERT INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)',
            [$id, $turnoId, $this->hoy]
        );
        return $id;
    }

    public function testCrearEspacioBasico(): void
    {
        $id = $this->svc->crear('Piscina', '1_sur', ['Barrer bordes', 'Limpiar vidrios']);

        $fila = Database::fetchOne('SELECT * FROM habitaciones WHERE id = ?', [$id]);
        $this->assertNotNull($fila);
        $this->assertSame(1, (int) $fila['es_espacio_comun']);
        $this->assertNull($fila['cloudbeds_room_id']);
        $this->assertSame('aprobada', (string) $fila['estado']); // idle / listo
        $this->assertSame('Piscina', (string) $fila['numero']);

        // El tipo "Área común" se creó lazy
        $tipo = Database::fetchOne('SELECT nombre FROM tipos_habitacion WHERE id = ?', [(int) $fila['tipo_habitacion_id']]);
        $this->assertSame(EspacioService::TIPO_NOMBRE, (string) $tipo['nombre']);
    }

    public function testListarIncluyeItemsCountYExcluyePiezas(): void
    {
        $this->svc->crear('Piscina', '1_sur', ['A', 'B', 'C']);

        // Una pieza de huésped no debe aparecer en el listado de espacios
        Database::execute(
            'INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, ?, ?, ?)',
            [1, '101', $this->tipoPiezaId, 'sucia']
        );

        $lista = $this->svc->listar('ambos');
        $this->assertCount(1, $lista);
        $this->assertSame('Piscina', (string) $lista[0]['numero']);
        $this->assertSame(3, (int) $lista[0]['items_count']);
    }

    public function testHabitacionServiceListarNoIncluyeEspacios(): void
    {
        $this->svc->crear('Piscina', '1_sur', ['A']);
        Database::execute(
            'INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, ?, ?, ?)',
            [1, '101', $this->tipoPiezaId, 'sucia']
        );

        $piezas = new HabitacionService();
        $filas = $piezas->listar('ambos');
        $numeros = array_map(static fn(array $f) => (string) $f['numero'], $filas);
        $this->assertContains('101', $numeros);
        $this->assertNotContains('Piscina', $numeros);
    }

    public function testTemplatePropioNoInterfiereConPiezas(): void
    {
        TestDatabase::sembrarChecklistTemplates(); // template por tipo (piezas)
        $espacioId = $this->svc->crear('Piscina', '1_sur', ['Solo tarea de piscina']);

        Database::execute(
            'INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, ?, ?, ?)',
            [1, '101', $this->tipoPiezaId, 'sucia']
        );
        $piezaId = Database::lastInsertId();

        $chk = new ChecklistService();
        $habs = new HabitacionService();

        $tplEspacio = $chk->templateParaHabitacion($habs->obtener($espacioId));
        $tplPieza = $chk->templateParaHabitacion($habs->obtener($piezaId));

        $this->assertNotNull($tplEspacio);
        $this->assertNotNull($tplPieza);
        $this->assertNotSame($tplEspacio, $tplPieza);

        // El template del espacio apunta a la habitación; el de la pieza no
        $filaEsp = Database::fetchOne('SELECT habitacion_id FROM checklists_template WHERE id = ?', [$tplEspacio]);
        $this->assertSame($espacioId, (int) $filaEsp['habitacion_id']);
        $filaPieza = Database::fetchOne('SELECT habitacion_id FROM checklists_template WHERE id = ?', [$tplPieza]);
        $this->assertNull($filaPieza['habitacion_id']);
    }

    public function testPedirLimpiezaYAutoCierreSinAuditoria(): void
    {
        $trabajadorId = $this->crearTrabajadorConTurno('16000001-5', 'Ana');
        $espacioId = $this->svc->crear('Piscina', '1_sur', ['Barrer', 'Vidrios', 'Cloro']);

        // Pedir limpieza: terminal (aprobada) -> sucia + asignación
        $asig = $this->svc->pedirLimpieza($espacioId, $trabajadorId, $this->hoy);
        $this->assertGreaterThan(0, $asig->id);
        $estado = (string) Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$espacioId])['estado'];
        $this->assertSame('sucia', $estado);

        // Limpiar
        $chk = new ChecklistService();
        $ejec = $chk->iniciarEjecucion($espacioId, $trabajadorId, $this->hoy);
        $detalle = $chk->estadoEjecucion($ejec->id);
        $this->assertCount(3, $detalle['items']);
        foreach ($detalle['items'] as $it) {
            $chk->marcarItem($ejec->id, (int) $it['id'], true, $trabajadorId);
        }
        $chk->completar($ejec->id, $trabajadorId);

        // Auto-cierre: estado aprobada, ejecución completada, sin auditoría
        $estadoFin = (string) Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$espacioId])['estado'];
        $this->assertSame('aprobada', $estadoFin);
        $ejecFin = $chk->obtenerEjecucion($ejec->id);
        $this->assertSame('completada', $ejecFin->estado);
        $this->assertNull(Database::fetchOne('SELECT id FROM auditorias WHERE ejecucion_id = ?', [$ejec->id]));
    }

    public function testPedirLimpiezaConTrabajadorInvalidoLanza(): void
    {
        $espacioId = $this->svc->crear('Piscina', '1_sur', ['A']);
        $this->assertExcepcion('TRABAJADOR_INVALIDO', fn() => $this->svc->pedirLimpieza($espacioId, 99999, $this->hoy));
        // No debe haber quedado ninguna asignación
        $this->assertSame(0, (int) Database::fetchColumn('SELECT COUNT(*) FROM asignaciones WHERE habitacion_id = ?', [$espacioId]));
        // Y el espacio sigue idle (no se reseteó a sucia)
        $this->assertSame('aprobada', (string) Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$espacioId])['estado']);
    }

    public function testEspaciosNoContaminanKpisDePiezas(): void
    {
        $trabajadorId = $this->crearTrabajadorConTurno('16000001-5', 'Ana');
        $espacioId = $this->svc->crear('Piscina', '1_sur', ['Barrer', 'Vidrios']);
        $this->svc->pedirLimpieza($espacioId, $trabajadorId, $this->hoy);

        $chk = new ChecklistService();
        $ejec = $chk->iniciarEjecucion($espacioId, $trabajadorId, $this->hoy);
        foreach ($chk->estadoEjecucion($ejec->id)['items'] as $it) {
            $chk->marcarItem($ejec->id, (int) $it['id'], true, $trabajadorId);
        }
        $chk->completar($ejec->id, $trabajadorId);

        // No hay piezas de huésped limpiadas: los KPIs de piezas no deben contar el espacio.
        $rep = new ReportesService();
        $kpis = $rep->kpis($this->hoy, $this->hoy, 'ambos');
        $this->assertNull($kpis['tiempo_promedio']['valor'], 'tiempo_promedio no debe contar el espacio');
        $this->assertNull($kpis['creditos']['valor'], 'creditos no debe contar el espacio');
    }

    public function testEditarReemplazaChecklist(): void
    {
        $espacioId = $this->svc->crear('Piscina', '1_sur', ['Viejo 1', 'Viejo 2']);
        $this->svc->editar($espacioId, 'Piscina techada', ['Nuevo 1', 'Nuevo 2', 'Nuevo 3']);

        $detalle = $this->svc->obtenerDetalle($espacioId);
        $this->assertSame('Piscina techada', (string) $detalle['espacio']['numero']);
        $descs = array_map(static fn(array $i) => (string) $i['descripcion'], $detalle['items']);
        $this->assertSame(['Nuevo 1', 'Nuevo 2', 'Nuevo 3'], $descs);
    }

    public function testArchivarSacaDeLaLista(): void
    {
        $espacioId = $this->svc->crear('Piscina', '1_sur', ['A']);
        $this->svc->archivar($espacioId);
        $this->assertCount(0, $this->svc->listar('ambos'));
        $this->assertSame(0, (int) Database::fetchOne('SELECT activa FROM habitaciones WHERE id = ?', [$espacioId])['activa']);
    }

    public function testValidaciones(): void
    {
        $this->assertExcepcion('NOMBRE_REQUERIDO', fn() => $this->svc->crear('   ', '1_sur', ['A']));
        $this->assertExcepcion('NOMBRE_MUY_LARGO', fn() => $this->svc->crear(str_repeat('x', 21), '1_sur', ['A']));
        $this->assertExcepcion('CHECKLIST_VACIO', fn() => $this->svc->crear('Piscina', '1_sur', ['  ', '']));
        $this->assertExcepcion('HOTEL_INVALIDO', fn() => $this->svc->crear('Piscina', 'no_existe', ['A']));

        $this->svc->crear('Piscina', '1_sur', ['A']);
        $this->assertExcepcion('NOMBRE_DUPLICADO', fn() => $this->svc->crear('Piscina', '1_sur', ['B']));
    }

    private function assertExcepcion(string $codigoEsperado, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Debía lanzar EspacioException {$codigoEsperado}");
        } catch (EspacioException $e) {
            $this->assertSame($codigoEsperado, $e->codigo);
        }
    }
}
