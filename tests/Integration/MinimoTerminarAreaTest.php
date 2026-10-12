<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\EspaciosController;
use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\ChecklistException;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\EspacioException;
use Atankalama\Limpieza\Services\EspacioService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * v7.6: tiempo mínimo para terminar, propio de cada área común (habitaciones.minimo_terminar_min,
 * en minutos; 0 = sin mínimo, NULL = el general). Las habitaciones siguen con el general.
 * Ver docs/areas-comunes.md.
 *
 * El bootstrap pone el general en 0 (CHECKLIST_DELAY_MINIMO_SEGUNDOS); los casos que necesitan
 * los 3 min de producción lo cambian con conGeneral().
 */
final class MinimoTerminarAreaTest extends TestCase
{
    private EspacioService $espacios;
    private ChecklistService $checklist;
    private int $ana;
    private string $hoy;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES ('mañana', '08:00', '16:00')");
        $this->hoy = date('Y-m-d');
        [$this->ana] = TestDatabase::crearUsuario('16000001-5', 'Ana', 'Trabajador');
        $turnoId = (int) Database::fetchOne('SELECT id FROM turnos LIMIT 1')['id'];
        Database::execute(
            'INSERT INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)',
            [$this->ana, $turnoId, $this->hoy]
        );
        $this->espacios = new EspacioService();
        $this->checklist = new ChecklistService();
    }

    public function testAreaConUnMinutoNoSeTerminaAntesYSiDespues(): void
    {
        $area = $this->espacios->crear('Ascensor', '1_sur', ['Espejo', 'Botonera'], null, 1);
        $ejec = $this->limpiarSinTerminar($area);

        $this->assertSame(60, $this->checklist->estadoEjecucion($ejec)['delay_restante_segundos']);
        try {
            $this->checklist->completar($ejec, $this->ana);
            $this->fail('Con 1 min de mínimo no se debía poder terminar al tiro.');
        } catch (ChecklistException $e) {
            $this->assertSame('DELAY_MINIMO', $e->codigo);
            $this->assertStringContainsString('al menos 1 min', $e->getMessage());
        }

        $this->empezadaHace($ejec, 61);
        $this->assertSame(0, $this->checklist->estadoEjecucion($ejec)['delay_restante_segundos']);
        $this->checklist->completar($ejec, $this->ana);
        $this->assertSame('completada_pendiente_auditoria', $this->estado($area));
    }

    public function testAreaSinMinimoSeTerminaAlTiroAunqueElGeneralSeaTres(): void
    {
        $this->conGeneral(180, function (): void {
            $area = $this->espacios->crear('Basureros', '1_sur', ['Vaciar'], null, 0);
            $ejec = $this->limpiarSinTerminar($area);

            $this->assertSame(0, $this->checklist->estadoEjecucion($ejec)['delay_restante_segundos']);
            $this->checklist->completar($ejec, $this->ana);
            $this->assertSame('completada_pendiente_auditoria', $this->estado($area));
        });
    }

    public function testAreaSinValorPropioSigueConElGeneral(): void
    {
        $this->conGeneral(180, function (): void {
            $area = $this->espacios->crear('Piscina', '1_sur', ['Barrer']);
            $this->assertNull(Database::fetchOne('SELECT minimo_terminar_min FROM habitaciones WHERE id = ?', [$area])['minimo_terminar_min']);
            $ejec = $this->limpiarSinTerminar($area);

            $restante = $this->checklist->estadoEjecucion($ejec)['delay_restante_segundos'];
            $this->assertGreaterThan(170, $restante);
            $this->assertLessThanOrEqual(180, $restante);

            $this->expectException(ChecklistException::class);
            $this->expectExceptionMessage('al menos 3 min');
            $this->checklist->completar($ejec, $this->ana);
        });
    }

    public function testLaHabitacionNoUsaElMinimoDeLasAreas(): void
    {
        $this->conGeneral(180, function (): void {
            // Una pieza de huésped con la columna llena a mano: igual rige el general.
            Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
            $tipo = (int) Database::fetchOne("SELECT id FROM tipos_habitacion WHERE nombre = 'Doble'")['id'];
            $hotel = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo = '1_sur'")['id'];
            Database::execute(
                "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado, minimo_terminar_min) VALUES (?, '101', ?, 'sucia', 0)",
                [$hotel, $tipo]
            );
            $pieza = Database::lastInsertId();
            TestDatabase::sembrarChecklistTemplates();
            (new \Atankalama\Limpieza\Services\AsignacionService())->asignarManual($pieza, $this->ana, $this->hoy);
            $ejec = $this->checklist->iniciarEjecucion($pieza, $this->ana, $this->hoy)->id;

            $this->assertGreaterThan(170, $this->checklist->estadoEjecucion($ejec)['delay_restante_segundos']);
        });
    }

    public function testListarYDetalleDanElMinimoQueRige(): void
    {
        $this->conGeneral(180, function (): void {
            $conValor = $this->espacios->crear('Ascensor', '1_sur', ['Espejo'], null, 2);
            $sinValor = $this->espacios->crear('Piscina', '1_sur', ['Barrer']);

            $porId = [];
            foreach ($this->espacios->listar('1_sur') as $f) {
                $porId[(int) $f['id']] = $f['minimo_terminar_min'];
            }
            $this->assertSame(2, $porId[$conValor]);
            $this->assertSame(3, $porId[$sinValor]);
            $this->assertSame(3, $this->espacios->obtenerDetalle($sinValor)['espacio']['minimo_terminar_min']);
        });
    }

    public function testEditarCambiaElMinimoYSinElCampoNoLoToca(): void
    {
        $area = $this->espacios->crear('Ascensor', '1_sur', ['Espejo'], null, 5);

        $this->espacios->editar($area, 'Ascensor', ['Espejo'], null, 1);
        $this->assertSame(1, $this->minimoGuardado($area));

        // Una pantalla abierta desde antes de la v7.6 edita sin mandar el campo: se conserva.
        $this->espacios->editar($area, 'Ascensor A', ['Espejo', 'Botonera']);
        $this->assertSame(1, $this->minimoGuardado($area));
    }

    public function testValorFueraDeLaListaSeRechaza(): void
    {
        $area = $this->espacios->crear('Ascensor', '1_sur', ['Espejo']);

        foreach ([4, 7, 60] as $raro) {
            try {
                $this->espacios->editar($area, 'Ascensor', ['Espejo'], null, $raro);
                $this->fail("{$raro} min no está en la lista y debía rechazarse.");
            } catch (EspacioException $e) {
                $this->assertSame('MINIMO_INVALIDO', $e->codigo);
            }
        }
        $this->assertNull(Database::fetchOne('SELECT minimo_terminar_min FROM habitaciones WHERE id = ?', [$area])['minimo_terminar_min']);
    }

    public function testControladorGuardaElMinimoYRechazaTexto(): void
    {
        $ctrl = new EspaciosController();

        $crear = $ctrl->crear($this->req(['nombre' => 'Pasillo 2', 'hotel' => '1_sur', 'items' => ['Barrer'], 'minimo_terminar_min' => 2]));
        $this->assertSame(201, $crear->status);
        $id = (int) $this->json($crear)['data']['id'];
        $this->assertSame(2, $this->minimoGuardado($id));

        $editar = $ctrl->editar($this->req(['nombre' => 'Pasillo 2', 'items' => ['Barrer'], 'minimo_terminar_min' => '0'], ['id' => (string) $id]));
        $this->assertSame(200, $editar->status);
        $this->assertSame(0, $this->minimoGuardado($id));

        $malo = $ctrl->editar($this->req(['nombre' => 'Pasillo 2', 'items' => ['Barrer'], 'minimo_terminar_min' => 'dos'], ['id' => (string) $id]));
        $this->assertSame(400, $malo->status);
        $this->assertSame('MINIMO_INVALIDO', $this->json($malo)['error']['codigo']);
        $this->assertSame(0, $this->minimoGuardado($id));
    }

    // --- Helpers -----------------------------------------------------------------------

    /** Pide la limpieza a Ana, la empieza y marca todo, sin terminar. Devuelve la ejecución. */
    private function limpiarSinTerminar(int $area): int
    {
        $this->espacios->pedirLimpieza($area, $this->ana, $this->hoy);
        $ejec = $this->checklist->iniciarEjecucion($area, $this->ana, $this->hoy)->id;
        foreach ($this->checklist->estadoEjecucion($ejec)['items'] as $it) {
            $this->checklist->marcarItem($ejec, (int) $it['id'], true, $this->ana);
        }
        return $ejec;
    }

    private function empezadaHace(int $ejec, int $segundos): void
    {
        $inicio = gmdate('Y-m-d\TH:i:s.000\Z', time() - $segundos);
        Database::execute('UPDATE ejecuciones_checklist SET timestamp_inicio = ? WHERE id = ?', [$inicio, $ejec]);
    }

    private function estado(int $id): string
    {
        return (string) Database::fetchOne('SELECT estado FROM habitaciones WHERE id = ?', [$id])['estado'];
    }

    private function minimoGuardado(int $id): ?int
    {
        $v = Database::fetchOne('SELECT minimo_terminar_min FROM habitaciones WHERE id = ?', [$id])['minimo_terminar_min'];
        return $v === null ? null : (int) $v;
    }

    /** Corre $caso con el mínimo general en $segundos (en producción, 180) y lo deja como estaba. */
    private function conGeneral(int $segundos, callable $caso): void
    {
        $prop = new \ReflectionProperty(Config::class, 'values');
        $valores = $prop->getValue();
        $prop->setValue(null, array_merge($valores, ['CHECKLIST_DELAY_MINIMO_SEGUNDOS' => (string) $segundos]));
        try {
            $caso();
        } finally {
            $prop->setValue(null, $valores);
        }
    }

    private function req(array $cuerpo, array $ruta = []): Request
    {
        return new Request(metodo: 'POST', path: '/test', cuerpo: $cuerpo, ruta: $ruta);
    }

    /** @return array<string, mixed> */
    private function json(Response $resp): array
    {
        return json_decode($resp->cuerpo, true);
    }
}
