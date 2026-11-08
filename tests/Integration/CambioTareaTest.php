<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\CambioTareaController;
use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Services\CambioTareaException;
use Atankalama\Limpieza\Services\CambioTareaService;
use Atankalama\Limpieza\Services\ListasOpcionesService;
use Atankalama\Limpieza\Services\UsuarioService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * v7.16 «Cambio Tarea» (gerencia, 06/11/2026; plan aprobado por Nicolás el 07/11/2026): la supervisora
 * anota cuántas horas estuvo HOY alguien de Aseo en otra área. Solo el mismo día; solo personal de Aseo;
 * áreas de la lista «area_apoyo»; de media en media hora.
 */
final class CambioTareaTest extends TestCase
{
    private string $hoy;
    private int $manana;
    private int $sup;
    private CambioTareaService $svc;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        $this->hoy = date('Y-m-d');
        Database::execute("INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES ('mañana', '08:00', '16:00')");
        $this->manana = Database::lastInsertId();
        [$this->sup] = TestDatabase::crearUsuario('99999999-9', 'Sofía', 'Supervisora');
        $this->svc = new CambioTareaService();
    }

    protected function tearDown(): void
    {
        Config::sobrescribir('CAMBIO_TAREA_NAVEGAR_DIAS', false);
    }

    public function testListaElPersonalDeAseoDelDia(): void
    {
        [$ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');
        TestDatabase::crearUsuario('22222222-2', 'Bea', 'Aseo');          // sin turno ni piezas: no sale
        [$cata] = TestDatabase::crearUsuario('33333333-3', 'Cata', 'Aseo'); // sin turno, con pieza: sale
        [$pia] = TestDatabase::crearUsuario('44444444-4', 'Pía', 'Apoyo');  // otra área: no sale
        Database::execute("UPDATE usuarios SET jornada = 'completa' WHERE id = ?", [$ana]);
        $this->darTurno($ana);
        $this->darTurno($pia);
        $this->darTurno($this->sup);
        $this->crearPieza('101');
        $this->crearPieza('102');
        $this->asignar(1, $cata);
        $this->asignar(2, $cata, terminada: true);

        $personas = $this->porNombre($this->svc->listar($this->hoy));

        $this->assertSame(['Ana', 'Cata'], array_keys($personas));
        $this->assertSame('completa', $personas['Ana']['jornada']);
        $this->assertSame(450, $personas['Ana']['turno']['minutos_netos'], '08–16 con jornada completa = 7,5 h');
        $this->assertNull($personas['Cata']['turno']);
        $this->assertSame(2, $personas['Cata']['piezas_asignadas']);
        $this->assertSame(1, $personas['Cata']['piezas_hechas']);
        $this->assertSame([], $personas['Ana']['otras_areas']);
    }

    public function testGuardaHoyYElJuegoNuevoReemplazaAlAnterior(): void
    {
        [$ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');
        $this->darTurno($ana);
        $recepcion = TestDatabase::opcionId('area_apoyo', 'Recepción');
        $lavanderia = TestDatabase::opcionId('area_apoyo', 'Lavandería');

        $r = $this->svc->guardar($ana, $this->hoy, [
            ['area_id' => $lavanderia, 'minutos' => 60],
            ['area_id' => $recepcion, 'minutos' => 120],
        ], $this->sup);
        // En el orden de la lista de Ajustes (Recepción va primero).
        $this->assertSame([['Recepción', 120], ['Lavandería', 60]], array_map(fn(array $a): array => [$a['nombre'], $a['minutos']], $r));

        $r = $this->svc->guardar($ana, $this->hoy, [['area_id' => $recepcion, 'minutos' => 90]], $this->sup);
        $this->assertSame([['Recepción', 90]], array_map(fn(array $a): array => [$a['nombre'], $a['minutos']], $r));
        $this->assertSame(1, (int) Database::fetchColumn('SELECT COUNT(*) FROM horas_otras_areas'));
        $this->assertSame($this->sup, (int) Database::fetchColumn('SELECT registrado_por FROM horas_otras_areas'));

        // Cada cambio queda en el registro de auditoría con el antes y el después.
        $logs = Database::fetchAll("SELECT detalles_json FROM audit_log WHERE accion = 'cambio_tarea.guardar' ORDER BY id");
        $this->assertCount(2, $logs);
        $ultimo = json_decode((string) $logs[1]['detalles_json'], true);
        $this->assertSame(['Recepción' => 120, 'Lavandería' => 60], $ultimo['antes_minutos']);
        $this->assertSame(['Recepción' => 90], $ultimo['despues_minutos']);

        // Mismo juego: no escribe nada ni deja otro registro.
        $this->svc->guardar($ana, $this->hoy, [['area_id' => $recepcion, 'minutos' => 90]], $this->sup);
        $this->assertSame(2, (int) Database::fetchColumn("SELECT COUNT(*) FROM audit_log WHERE accion = 'cambio_tarea.guardar'"));

        // Vacío = borra todo.
        $this->assertSame([], $this->svc->guardar($ana, $this->hoy, [], $this->sup));
        $this->assertSame(0, (int) Database::fetchColumn('SELECT COUNT(*) FROM horas_otras_areas'));
    }

    public function testSoloElMismoDia(): void
    {
        [$ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');
        $recepcion = TestDatabase::opcionId('area_apoyo', 'Recepción');
        foreach (['-1 day', '+1 day'] as $desfase) {
            $fecha = date('Y-m-d', strtotime($desfase));
            $e = $this->capturar(fn() => $this->svc->guardar($ana, $fecha, [['area_id' => $recepcion, 'minutos' => 60]], $this->sup));
            $this->assertSame('SOLO_MISMO_DIA', $e->codigo, $desfase);
            $this->assertSame(409, $e->httpStatus);
        }
        $this->assertSame(0, (int) Database::fetchColumn('SELECT COUNT(*) FROM horas_otras_areas'));
    }

    public function testValidaLasHorasLasAreasYLaPersona(): void
    {
        [$ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');
        [$pia] = TestDatabase::crearUsuario('44444444-4', 'Pía', 'Apoyo');
        $recepcion = TestDatabase::opcionId('area_apoyo', 'Recepción');
        $cocina = TestDatabase::opcionId('area_apoyo', 'Cocina');
        $guardar = fn(int $u, array $filas): CambioTareaException
            => $this->capturar(fn() => $this->svc->guardar($u, $this->hoy, $filas, $this->sup));

        $this->assertSame('HORAS_INVALIDAS', $guardar($ana, [['area_id' => $recepcion, 'minutos' => 45]])->codigo);
        $this->assertSame('HORAS_INVALIDAS', $guardar($ana, [['area_id' => $recepcion, 'minutos' => 750]])->codigo);
        $this->assertSame('HORAS_INVALIDAS', $guardar($ana, [
            ['area_id' => $recepcion, 'minutos' => 720], ['area_id' => $cocina, 'minutos' => 270],
        ])->codigo, 'más de 16 h en el día');
        $this->assertSame('AREA_REPETIDA', $guardar($ana, [
            ['area_id' => $recepcion, 'minutos' => 60], ['area_id' => $recepcion, 'minutos' => 30],
        ])->codigo);
        $this->assertSame('AREA_REQUERIDA', $guardar($ana, [['area_id' => '', 'minutos' => 60]])->codigo);
        // Una opción de otra lista no es un área.
        $this->assertSame('AREA_INVALIDA', $guardar($ana, [['area_id' => TestDatabase::opcionId('rechazo', 'Otro'), 'minutos' => 60]])->codigo);
        $this->assertSame('NO_ES_DE_ASEO', $guardar($pia, [['area_id' => $recepcion, 'minutos' => 60]])->codigo);
        $this->assertSame('NO_ES_DE_ASEO', $guardar($this->sup, [['area_id' => $recepcion, 'minutos' => 60]])->codigo);
        $this->assertSame('PERSONA_NO_ENCONTRADA', $guardar(9999, [])->codigo);
        $this->assertSame(0, (int) Database::fetchColumn('SELECT COUNT(*) FROM horas_otras_areas'));
    }

    public function testUnAreaDesactivadaSeConservaSoloDondeYaEstaba(): void
    {
        [$ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');
        [$bea] = TestDatabase::crearUsuario('22222222-2', 'Bea', 'Aseo');
        $cocina = TestDatabase::opcionId('area_apoyo', 'Cocina');
        $this->svc->guardar($ana, $this->hoy, [['area_id' => $cocina, 'minutos' => 60]], $this->sup);
        (new ListasOpcionesService())->actualizar('area_apoyo', $cocina, ['activo' => false], $this->sup);

        // A Ana se le pueden corregir las horas; a Bea ya no se le puede anotar.
        $r = $this->svc->guardar($ana, $this->hoy, [['area_id' => $cocina, 'minutos' => 90]], $this->sup);
        $this->assertSame(90, $r[0]['minutos']);
        $e = $this->capturar(fn() => $this->svc->guardar($bea, $this->hoy, [['area_id' => $cocina, 'minutos' => 60]], $this->sup));
        $this->assertSame('AREA_INVALIDA', $e->codigo);
    }

    public function testQuienYaTieneHorasSaleAunqueLeHayanBorradoElTurno(): void
    {
        [$ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');
        $this->darTurno($ana);
        $this->svc->guardar($ana, $this->hoy, [['area_id' => TestDatabase::opcionId('area_apoyo', 'Bodega'), 'minutos' => 30]], $this->sup);
        Database::execute('DELETE FROM usuarios_turnos WHERE usuario_id = ?', [$ana]);

        $personas = $this->svc->listar($this->hoy);

        $this->assertCount(1, $personas);
        $this->assertNull($personas[0]['turno']);
        $this->assertSame('Bodega', $personas[0]['otras_areas'][0]['nombre']);
    }

    public function testElControladorSoloMuestraHoyYExigeElPermiso(): void
    {
        [$ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');
        $this->darTurno($ana);
        $ctrl = new CambioTareaController();
        $ayer = date('Y-m-d', strtotime('-1 day'));

        $r = $this->json($ctrl->listar($this->req('GET', '/api/cambio-tarea', $this->sup)));
        $this->assertTrue($r['ok']);
        $this->assertSame($this->hoy, $r['data']['fecha']);
        $this->assertTrue($r['data']['editable']);
        $this->assertFalse($r['data']['navegar_dias']);
        $this->assertSame(['Recepción', 'Lavandería', 'Mantención', 'Cocina', 'Bodega'], array_column($r['data']['areas'], 'nombre'));
        $this->assertTrue($r['data']['puede_cargar_turno'], 'la supervisora carga turnos');
        $this->assertSame(['mañana'], array_column($r['data']['turnos'], 'nombre'));

        // Sin el interruptor no se puede ver otro día.
        $r = $this->json($ctrl->listar($this->req('GET', '/api/cambio-tarea', $this->sup, query: ['fecha' => $ayer])));
        $this->assertSame('SOLO_HOY', $r['error']['codigo']);

        // Con el interruptor: días pasados en solo lectura; futuros, nunca.
        Config::sobrescribir('CAMBIO_TAREA_NAVEGAR_DIAS', true);
        $r = $this->json($ctrl->listar($this->req('GET', '/api/cambio-tarea', $this->sup, query: ['fecha' => $ayer])));
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['data']['editable']);
        $r = $this->json($ctrl->listar($this->req('GET', '/api/cambio-tarea', $this->sup, query: ['fecha' => date('Y-m-d', strtotime('+1 day'))])));
        $this->assertSame('SOLO_HOY', $r['error']['codigo']);

        // Guardar por el controlador.
        $put = $this->req('PUT', '/api/cambio-tarea/' . $ana, $this->sup, cuerpo: [
            'fecha' => $this->hoy,
            'areas' => [['area_id' => TestDatabase::opcionId('area_apoyo', 'Recepción'), 'minutos' => 180]],
        ], ruta: ['usuario_id' => (string) $ana]);
        $r = $this->json($ctrl->guardar($put));
        $this->assertTrue($r['ok']);
        $this->assertSame(180, $r['data']['otras_areas'][0]['minutos']);

        // Alguien de Aseo no tiene el permiso.
        $r = $this->json($ctrl->listar($this->req('GET', '/api/cambio-tarea', $ana)));
        $this->assertSame('SIN_PERMISO', $r['error']['codigo']);
    }

    // ───────────────────────────── Ayudas ─────────────────────────────

    private function darTurno(int $usuarioId): void
    {
        Database::execute('INSERT INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)', [$usuarioId, $this->manana, $this->hoy]);
    }

    private function crearPieza(string $numero): void
    {
        if (Database::fetchOne('SELECT id FROM hoteles') === null) {
            Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
            Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
            TestDatabase::sembrarChecklistTemplates();
        }
        Database::execute("INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (1, ?, 1, 'sucia')", [$numero]);
    }

    private function asignar(int $habitacionId, int $usuarioId, bool $terminada = false): void
    {
        Database::execute(
            'INSERT INTO asignaciones (habitacion_id, usuario_id, fecha, orden_cola) VALUES (?, ?, ?, 1)',
            [$habitacionId, $usuarioId, $this->hoy]
        );
        if ($terminada) {
            Database::execute(
                "INSERT INTO ejecuciones_checklist (habitacion_id, usuario_id, asignacion_id, template_id, estado, timestamp_fin)
                 VALUES (?, ?, ?, (SELECT MIN(id) FROM checklists_template), 'completada', strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))",
                [$habitacionId, $usuarioId, Database::lastInsertId()]
            );
        }
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $cuerpo
     * @param array<string, string> $ruta
     */
    private function req(string $metodo, string $path, int $usuarioId, array $query = [], array $cuerpo = [], array $ruta = []): Request
    {
        $req = new Request(metodo: $metodo, path: $path, cuerpo: $cuerpo, ruta: $ruta, query: $query);
        $req->usuario = (new UsuarioService())->buscarPorId($usuarioId);
        return $req;
    }

    /** @return array<string, mixed> */
    private function json(\Atankalama\Limpieza\Core\Response $r): array
    {
        return json_decode($r->cuerpo, true);
    }

    private function capturar(callable $fn): CambioTareaException
    {
        try {
            $fn();
        } catch (CambioTareaException $e) {
            return $e;
        }
        $this->fail('Se esperaba CambioTareaException');
    }

    /**
     * @param list<array<string, mixed>> $personas
     * @return array<string, array<string, mixed>>
     */
    private function porNombre(array $personas): array
    {
        return array_column($personas, null, 'nombre');
    }
}
