<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\EspaciosController;
use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\EspacioService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * v7.10 (decisión de Nicolás, 18/10/2026): sin tiempo mínimo para terminar una limpieza. Antes
 * eran 3 min (CHECKLIST_DELAY_MINIMO_SEGUNDOS) y, desde la v7.6, un mínimo propio por área común
 * (habitaciones.minimo_terminar_min, que queda en la BD sin uso).
 */
final class SinTiempoMinimoV710Test extends TestCase
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

    public function testHabitacionSeTerminaApenasSeMarcaTodo(): void
    {
        // Un .env viejo que todavía traiga la variable de los 3 min ya no cambia nada.
        $this->conVariableVieja(function (): void {
            Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
            $tipo = (int) Database::fetchOne("SELECT id FROM tipos_habitacion WHERE nombre = 'Doble'")['id'];
            $hotel = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo = '1_sur'")['id'];
            Database::execute(
                "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, '101', ?, 'sucia')",
                [$hotel, $tipo]
            );
            $pieza = Database::lastInsertId();
            TestDatabase::sembrarChecklistTemplates();
            (new AsignacionService())->asignarManual($pieza, $this->ana, $this->hoy);
            $ejec = $this->checklist->iniciarEjecucion($pieza, $this->ana, $this->hoy)->id;
            $this->marcarTodo($ejec);

            $this->assertArrayNotHasKey('delay_restante_segundos', $this->checklist->estadoEjecucion($ejec));
            $this->checklist->completar($ejec, $this->ana);
            $this->assertSame('completada_pendiente_auditoria', $this->estado($pieza));
        });
    }

    public function testAreaConMinimoGuardadoSeTerminaAlTiro(): void
    {
        $area = $this->espacios->crear('Ascensor', '1_sur', ['Espejo', 'Botonera']);
        // Un área que alguien dejó con 10 min en la v7.6: el valor quedó en la BD pero ya no rige.
        Database::execute('UPDATE habitaciones SET minimo_terminar_min = 10 WHERE id = ?', [$area]);

        $this->espacios->pedirLimpieza($area, $this->ana, $this->hoy);
        $ejec = $this->checklist->iniciarEjecucion($area, $this->ana, $this->hoy)->id;
        $this->marcarTodo($ejec);
        $this->checklist->completar($ejec, $this->ana);

        $this->assertSame('completada_pendiente_auditoria', $this->estado($area));
    }

    public function testLasAreasYaNoMuestranElMinimo(): void
    {
        $area = $this->espacios->crear('Piscina', '1_sur', ['Barrer']);
        Database::execute('UPDATE habitaciones SET minimo_terminar_min = 5 WHERE id = ?', [$area]);

        foreach ($this->espacios->listar('1_sur') as $f) {
            $this->assertArrayNotHasKey('minimo_terminar_min', $f);
        }
        $this->assertArrayNotHasKey('minimo_terminar_min', $this->espacios->obtenerDetalle($area)['espacio']);
    }

    public function testPantallaViejaQueMandaElMinimoNoFallaYNoLoGuarda(): void
    {
        // Una pestaña de Áreas comunes abierta desde antes de la v7.10 todavía manda el campo.
        $ctrl = new EspaciosController();

        $crear = $ctrl->crear($this->req(['nombre' => 'Pasillo 2', 'hotel' => '1_sur', 'items' => ['Barrer'], 'minimo_terminar_min' => 5]));
        $this->assertSame(201, $crear->status);
        $id = (int) $this->json($crear)['data']['id'];
        $this->assertNull($this->minimoGuardado($id));

        $editar = $ctrl->editar($this->req(['nombre' => 'Pasillo 2', 'items' => ['Barrer'], 'minimo_terminar_min' => 'dos'], ['id' => (string) $id]));
        $this->assertSame(200, $editar->status);
        $this->assertNull($this->minimoGuardado($id));
    }

    // --- Helpers -----------------------------------------------------------------------

    private function marcarTodo(int $ejec): void
    {
        foreach ($this->checklist->estadoEjecucion($ejec)['items'] as $it) {
            $this->checklist->marcarItem($ejec, (int) $it['id'], true, $this->ana);
        }
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

    /** Corre $caso con CHECKLIST_DELAY_MINIMO_SEGUNDOS = 180, como la tenía producción, y la deja como estaba. */
    private function conVariableVieja(callable $caso): void
    {
        $prop = new \ReflectionProperty(Config::class, 'values');
        $valores = $prop->getValue();
        $prop->setValue(null, array_merge($valores, ['CHECKLIST_DELAY_MINIMO_SEGUNDOS' => '180']));
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
