<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\EspaciosController;
use Atankalama\Limpieza\Controllers\PaginasController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Models\Usuario;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\ChecklistException;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\EspacioException;
use Atankalama\Limpieza\Services\EspacioService;
use Atankalama\Limpieza\Services\ExtrasAreasService;
use Atankalama\Limpieza\Services\HabitacionService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Services\TareasCatalogoService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * v7.27 (propuesta de Nicolás, decisiones del 11/11/2026): áreas comunes beta. Catálogo de tareas con créditos por
 * m², «Pasar a beta» transforma la misma área y los extras viven en su tabla propia. Ver docs/areas-comunes.md §9.
 */
final class AreasBetaV727Test extends TestCase
{
    private EspacioService $svc;
    private TareasCatalogoService $cat;
    private ExtrasAreasService $ext;
    private ChecklistService $chk;
    private string $hoy;
    private int $ana;
    private int $berta;
    private int $sofia;
    private int $barrer;
    private int $vidrios;
    private int $lavado;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('inn', 'Inn')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        Database::execute("INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES ('mañana', '08:00', '16:00')");
        $this->hoy = date('Y-m-d');
        [$this->ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');
        [$this->berta] = TestDatabase::crearUsuario('22222222-2', 'Berta', 'Aseo');
        [$this->sofia] = TestDatabase::crearUsuario('55555555-5', 'Sofía', 'Supervisora');

        $this->svc = new EspacioService();
        $this->cat = new TareasCatalogoService();
        $this->ext = new ExtrasAreasService();
        $this->chk = new ChecklistService();
        $this->barrer = $this->cat->crear('Barrer', 0.1, $this->sofia);
        $this->vidrios = $this->cat->crear('Limpiar vidrios', 0.5, $this->sofia);
        $this->lavado = $this->cat->crear('Lavado especial', 0.2, $this->sofia);
    }

    /** Pasillo con Barrer 30 m² (3 créditos) y Vidrios 12 m² (6 créditos): 9 en total. */
    private function pasillo(): int
    {
        return $this->svc->crearBeta('Pasillo 300', '1_sur', [
            ['tarea_id' => $this->barrer, 'm2' => 30],
            ['tarea_id' => $this->vidrios, 'm2' => 12],
        ], $this->sofia);
    }

    /** @return list<array{descripcion: string, creditos: int}> */
    private function itemsVigentes(int $area): array
    {
        return array_map(
            static fn(array $i): array => ['descripcion' => (string) $i['descripcion'], 'creditos' => (int) $i['creditos']],
            $this->svc->obtenerDetalle($area)['items']
        );
    }

    /** Marca todo el checklist de la limpieza (ítems y extras) a nombre de la persona. */
    private function marcarTodo(int $ejecucion, int $usuario): void
    {
        foreach ($this->chk->estadoEjecucion($ejecucion)['items'] as $item) {
            if ((int) $item['marcado'] !== 1) {
                $this->chk->marcarItem($ejecucion, (int) $item['id'], true, $usuario);
            }
        }
    }

    private function limpiar(int $area, int $usuario): int
    {
        $ejec = $this->chk->iniciarEjecucion($area, $usuario, $this->hoy);
        $this->marcarTodo($ejec->id, $usuario);
        $this->chk->completar($ejec->id, $usuario);
        return $ejec->id;
    }

    /** @return array<string, mixed> el extra de la limpieza con ese nombre */
    private function extraDe(int $ejecucion, string $nombre): array
    {
        foreach ($this->chk->estadoEjecucion($ejecucion)['items'] as $item) {
            if ($item['descripcion'] === $nombre) {
                return $item;
            }
        }
        $this->fail("No está «{$nombre}» en el checklist");
    }

    public function testCreditosRedondeanAlEnteroMasCercanoConMinimoUno(): void
    {
        $this->assertSame(6, TareasCatalogoService::creditosDe(12, 0.5));
        $this->assertSame(3, TareasCatalogoService::creditosDe(2.5, 1.0), 'la mitad sube');
        $this->assertSame(2, TareasCatalogoService::creditosDe(7, 0.35), '2,45 baja');
        $this->assertSame(1, TareasCatalogoService::creditosDe(3, 0.1), '0,3 sería 0: el mínimo es 1');
        $this->assertSame(4, TareasCatalogoService::creditosDe(10, 0.35), '3,5 exacto sube, sin error de coma flotante');
    }

    public function testBuscarPorPalabraSinAcentosNiMayusculas(): void
    {
        $this->cat->crear('Aspirar', 0.1);
        $this->cat->crear('Lavar con aspiradora', 0.2);
        $this->cat->crear('Pasar mopa', 0.1);

        $nombres = array_column($this->cat->buscar('ÁS'), 'nombre');
        sort($nombres);
        $this->assertSame(['Aspirar', 'Lavar con aspiradora'], $nombres, 'cualquier palabra que empiece con lo escrito');
        $this->assertSame([], $this->cat->buscar('%'), 'el comodín de LIKE se busca como texto');
    }

    public function testBuscarDevuelveOchoYPrimeroLasMasUsadas(): void
    {
        for ($i = 1; $i <= 9; $i++) {
            $this->cat->crear("Tarea {$i}", 0.1);
        }
        $usada = $this->cat->crear('Tarea usada', 0.1);
        $this->svc->crearBeta('Patio', '1_sur', [['tarea_id' => $usada, 'm2' => 5]]);

        $resultado = $this->cat->buscar('tarea');
        $this->assertCount(8, $resultado);
        $this->assertSame('Tarea usada', $resultado[0]['nombre']);
    }

    public function testNoSePuedeRepetirUnaTareaAunqueCambienAcentosOMayusculas(): void
    {
        try {
            $this->cat->crear('  BÁRRER ', 0.3);
            $this->fail('Debía rechazar el duplicado');
        } catch (EspacioException $e) {
            $this->assertSame('TAREA_DUPLICADA', $e->codigo);
            $this->assertSame(409, $e->httpStatus);
        }
    }

    public function testCrearBetaCalculaLosCreditosDesdeLaSuperficie(): void
    {
        $area = $this->pasillo();

        $this->assertSame(1, (int) Database::fetchColumn('SELECT area_beta FROM habitaciones WHERE id = ?', [$area]));
        $this->assertSame([
            ['descripcion' => 'Barrer', 'creditos' => 3],
            ['descripcion' => 'Limpiar vidrios', 'creditos' => 6],
        ], $this->itemsVigentes($area));
        $this->assertTrue($this->svc->listar('ambos')[0]['area_beta']);
        $this->assertSame(9, (int) $this->svc->listar('ambos')[0]['creditos_total']);
    }

    public function testUnAreaBetaNecesitaAlMenosUnaTareaFija(): void
    {
        try {
            $this->svc->crearBeta('Patio', '1_sur', [['tarea_id' => $this->barrer, 'm2' => 5, 'tipo' => 'extra']]);
            $this->fail('Debía exigir una tarea fija');
        } catch (EspacioException $e) {
            $this->assertSame('COMPOSICION_VACIA', $e->codigo);
        }
    }

    public function testPasarABetaTransformaLaMismaAreaYConservaElHistorial(): void
    {
        $area = $this->svc->crear('Piscina', '1_sur', [['descripcion' => 'Barrer bordes', 'creditos' => 4]]);
        $this->svc->pedirLimpieza($area, $this->ana, $this->hoy, $this->sofia);
        $vieja = $this->limpiar($area, $this->ana);

        $this->svc->guardarBeta($area, 'Piscina', [['tarea_id' => $this->barrer, 'm2' => 50]], $this->sofia);

        $this->assertCount(1, $this->svc->listar('ambos'), 'no se duplica');
        $this->assertSame([['descripcion' => 'Barrer', 'creditos' => 5]], $this->itemsVigentes($area));
        $marcados = Database::fetchOne(
            'SELECT SUM(ic.creditos) AS c FROM ejecuciones_items ei JOIN items_checklist ic ON ic.id = ei.item_id WHERE ei.ejecucion_id = ?',
            [$vieja]
        );
        $this->assertSame(4, (int) $marcados['c'], 'la limpieza vieja mantiene su valor');
    }

    public function testUnaLimpiezaEmpezadaTerminaConSusTareasAntiguas(): void
    {
        $area = $this->svc->crear('Piscina', '1_sur', [['descripcion' => 'Barrer bordes', 'creditos' => 4]]);
        $this->svc->pedirLimpieza($area, $this->ana, $this->hoy, $this->sofia);
        $ejec = $this->chk->iniciarEjecucion($area, $this->ana, $this->hoy);

        $this->svc->guardarBeta($area, 'Piscina', [['tarea_id' => $this->barrer, 'm2' => 50]], $this->sofia);

        $items = $this->chk->estadoEjecucion($ejec->id)['items'];
        $this->assertSame(['Barrer bordes'], array_column($items, 'descripcion'));
        $this->chk->marcarItem($ejec->id, (int) $items[0]['id'], true, $this->ana);
        $this->chk->completar($ejec->id, $this->ana);
        $this->assertSame('completada_pendiente_auditoria', Database::fetchColumn('SELECT estado FROM habitaciones WHERE id = ?', [$area]));
    }

    public function testSinPermisoNoSeCambianNiSeQuitanLosM2YaGuardados(): void
    {
        $area = $this->pasillo();
        $base = [['tarea_id' => $this->barrer, 'm2' => 30], ['tarea_id' => $this->vidrios, 'm2' => 12]];

        foreach ([
            'cambiar m²' => [['tarea_id' => $this->barrer, 'm2' => 99], $base[1]],
            'quitar' => [$base[0]],
        ] as $caso => $tareas) {
            try {
                $this->svc->guardarBeta($area, 'Pasillo 300', $tareas, $this->sofia);
                $this->fail("Debía bloquear: {$caso}");
            } catch (EspacioException $e) {
                $this->assertSame('M2_BLOQUEADO', $e->codigo);
                $this->assertSame(403, $e->httpStatus);
            }
        }
        $this->assertSame(9, array_sum(array_column($this->itemsVigentes($area), 'creditos')), 'nada cambió');
    }

    public function testSinPermisoSiSePuedeSumarUnaTareaNuevaYHacerFijoUnExtra(): void
    {
        $area = $this->pasillo();
        $base = [['tarea_id' => $this->barrer, 'm2' => 30], ['tarea_id' => $this->vidrios, 'm2' => 12]];
        $this->svc->guardarBeta($area, 'Pasillo 300', [...$base, ['tarea_id' => $this->lavado, 'm2' => 30, 'tipo' => 'extra']], $this->sofia);
        $this->assertCount(2, $this->itemsVigentes($area), 'el extra recordado no entra al checklist');

        $this->svc->guardarBeta($area, 'Pasillo 300', [...$base, ['tarea_id' => $this->lavado, 'm2' => 30, 'tipo' => 'base']], $this->sofia);

        $this->assertCount(3, $this->itemsVigentes($area));
    }

    public function testConPermisoSeCambianLosM2YSeRehaceElChecklist(): void
    {
        $area = $this->pasillo();

        $this->svc->guardarBeta($area, 'Pasillo 300', [['tarea_id' => $this->barrer, 'm2' => 50]], $this->sofia, puedeEditarM2: true);

        $this->assertSame([['descripcion' => 'Barrer', 'creditos' => 5]], $this->itemsVigentes($area));
    }

    /** Permisos dinámicos: decide el permiso que trae la persona, nunca el nombre de su rol. */
    public function testEditarElNombreDeUnaTareaActualizaSusAreasYLaBusqueda(): void
    {
        $area = $this->pasillo();
        $this->svc->pedirLimpieza($area, $this->ana, $this->hoy, $this->sofia);
        $empezada = $this->chk->iniciarEjecucion($area, $this->ana, $this->hoy);

        $this->assertSame(1, $this->cat->editar($this->barrer, 'Barrer piso', 0.1, $this->sofia));

        $this->assertSame('Barrer piso', $this->itemsVigentes($area)[0]['descripcion'], 'las próximas limpiezas usan el nombre nuevo');
        $this->assertSame('Barrer', $this->chk->estadoEjecucion($empezada->id)['items'][0]['descripcion'], 'la empezada sigue con el suyo');
        $this->assertSame(['Barrer piso'], array_column($this->cat->buscar('pis'), 'nombre'));
    }

    public function testEditarNoPermiteUnNombreDeOtraTareaPeroSiElPropio(): void
    {
        $this->cat->editar($this->barrer, 'BÁRRER', 0.1, $this->sofia);
        $this->assertSame('BÁRRER', $this->cat->buscar('barrer')[0]['nombre'], 'corregir mayúsculas de la misma tarea se puede');

        try {
            $this->cat->editar($this->barrer, 'limpiar vidrios', 0.1, $this->sofia);
            $this->fail('Debía rechazar el nombre repetido');
        } catch (EspacioException $e) {
            $this->assertSame('TAREA_DUPLICADA', $e->codigo);
        }
    }

    /** @param list<string> $permisos */
    private function usuarioCon(array $permisos): Usuario
    {
        return new Usuario(
            id: $this->sofia, rut: '55555555-5', nombre: 'Sofía', email: null, activo: true, requiereCambioPwd: false,
            hotelDefault: 'ambos', temaPreferido: 'auto', permisos: $permisos, roles: [],
        );
    }

    public function testLaPestanaTareasSoloSeVeOSeEditaSegunElPermiso(): void
    {
        $pagina = function (array $permisos): array {
            $request = new Request(metodo: 'GET', path: '/ajustes/checklists', cuerpo: [], ruta: [], query: [], cookies: [], headers: []);
            $request->usuario = $this->usuarioCon($permisos);
            $respuesta = (new PaginasController())->ajustesChecklists($request);
            return [$respuesta->status, $respuesta->cuerpo];
        };

        [$status, $html] = $pagina(['tareas.ver']);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('ajustesChecklistsPestanas(false, false, true)', $html);
        $this->assertStringContainsString('tareasCatalogoApp(false)', $html, 'solo lectura');

        [$status, $html] = $pagina(['tareas.editar']);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('tareasCatalogoApp(true)', $html);

        $this->assertNotSame(200, $pagina(['espacios.ver'])[0], 'sin permiso no entra');
    }

    public function testCambiarM2DependeDelPermisoYNoDelRol(): void
    {
        $area = $this->pasillo();
        $guardar = function (array $permisos, array $roles) use ($area): int {
            $request = new Request(
                metodo: 'PUT',
                path: "/api/espacios/{$area}/beta",
                cuerpo: ['nombre' => 'Pasillo 300', 'tareas' => [
                    ['tarea_id' => $this->barrer, 'm2' => 40],
                    ['tarea_id' => $this->vidrios, 'm2' => 12],
                ]],
                ruta: ['id' => (string) $area],
                query: [],
                cookies: [],
                headers: [],
            );
            $request->usuario = new Usuario(
                id: $this->sofia, rut: '55555555-5', nombre: 'Sofía', email: null, activo: true, requiereCambioPwd: false,
                hotelDefault: 'ambos', temaPreferido: 'auto', permisos: $permisos, roles: $roles,
            );
            return (new EspaciosController())->guardarBeta($request)->status;
        };

        $this->assertSame(403, $guardar(['espacios.crear_editar'], ['Admin']), 'un rol llamado Admin sin el permiso no edita');
        $this->assertSame(200, $guardar(['espacios.crear_editar', 'espacios.editar_m2'], ['Supervisora']), 'si se le da el permiso, edita');
    }

    public function testNoSePuedeEditarUnaBetaConLaPantallaDeAlfa(): void
    {
        try {
            $this->svc->editar($this->pasillo(), 'Pasillo 300', ['Algo a mano']);
            $this->fail('Debía rechazar la edición');
        } catch (EspacioException $e) {
            $this->assertSame('ESPACIO_BETA', $e->codigo);
        }
    }

    public function testExtraPideM2UnaSolaVezYNoCambiaLaBase(): void
    {
        $area = $this->pasillo();

        try {
            $this->svc->pedirLimpieza($area, $this->ana, $this->hoy, $this->sofia, extras: [['tarea_id' => $this->lavado]]);
            $this->fail('Debía pedir los m²');
        } catch (EspacioException $e) {
            $this->assertSame('EXTRA_SIN_M2', $e->codigo);
        }
        $this->assertNull((new AsignacionService())->obtenerActivaDeHabitacion($area, $this->hoy), 'un dato malo no deja la limpieza pedida a medias');

        $this->svc->pedirLimpieza($area, $this->ana, $this->hoy, $this->sofia, extras: [['tarea_id' => $this->lavado, 'm2' => 30]]);
        $ejec = $this->chk->iniciarEjecucion($area, $this->ana, $this->hoy);
        $this->marcarTodo($ejec->id, $this->ana);
        $this->chk->completar($ejec->id, $this->ana);

        $this->assertCount(2, $this->itemsVigentes($area), 'el extra no pasa a la base');
        // La próxima vez ya tiene los m² guardados.
        $this->svc->pedirLimpieza($area, $this->ana, $this->hoy, $this->sofia, extras: [['tarea_id' => $this->lavado]]);
        $espera = $this->ext->enEspera($area, $this->hoy)['extras'];
        $this->assertSame(30.0, $espera[0]['m2']);
        $this->assertSame(6, $espera[0]['creditos']);
    }

    public function testUnaTareaDeLaBaseNoSePuedeAgregarComoExtra(): void
    {
        try {
            $this->svc->pedirLimpieza($this->pasillo(), $this->ana, $this->hoy, $this->sofia, extras: [['tarea_id' => $this->barrer]]);
            $this->fail('Debía rechazar el extra');
        } catch (EspacioException $e) {
            $this->assertSame('EXTRA_EN_BASE', $e->codigo);
        }
    }

    public function testLosExtrasSonSoloParaAreasBeta(): void
    {
        $alfa = $this->svc->crear('Piscina', '1_sur', ['Barrer']);
        try {
            $this->svc->pedirLimpieza($alfa, $this->ana, $this->hoy, $this->sofia, extras: []);
            $this->fail('Debía rechazar');
        } catch (EspacioException $e) {
            $this->assertSame('AREA_NO_BETA', $e->codigo);
        }
    }

    public function testElChecklistEsUnaSolaListaYElExtraBloqueaTerminar(): void
    {
        $area = $this->pasillo();
        $this->svc->pedirLimpieza($area, $this->ana, $this->hoy, $this->sofia, extras: [['tarea_id' => $this->lavado, 'm2' => 30]]);
        $ejec = $this->chk->iniciarEjecucion($area, $this->ana, $this->hoy);

        $estado = $this->chk->estadoEjecucion($ejec->id);
        $this->assertSame(['Barrer', 'Limpiar vidrios', 'Lavado especial'], array_column($estado['items'], 'descripcion'));
        $this->assertLessThan(0, $estado['items'][2]['id'], 'el extra viaja con id negativo');
        $this->assertSame(1, $estado['items'][2]['es_extra']);
        $this->assertSame(3, $estado['progreso']['obligatorios_total']);

        $this->chk->marcarItem($ejec->id, (int) $estado['items'][0]['id'], true, $this->ana);
        $this->chk->marcarItem($ejec->id, (int) $estado['items'][1]['id'], true, $this->ana);
        try {
            $this->chk->completar($ejec->id, $this->ana);
            $this->fail('Falta el extra');
        } catch (ChecklistException $e) {
            $this->assertNotSame('', $e->codigo);
        }

        $progreso = $this->chk->marcarItem($ejec->id, (int) $estado['items'][2]['id'], true, $this->ana);
        $this->assertSame(0, $progreso['obligatorios_pendientes']);
        $this->chk->completar($ejec->id, $this->ana);
    }

    public function testExtrasSeCambianSoloHastaQueEmpiezaLaLimpieza(): void
    {
        $area = $this->pasillo();

        try {
            $this->ext->guardar($area, $this->hoy, [['tarea_id' => $this->lavado, 'm2' => 30]], $this->sofia);
            $this->fail('Sin asignación no se puede');
        } catch (EspacioException $e) {
            $this->assertSame('EXTRA_SIN_ASIGNACION', $e->codigo);
        }

        $this->svc->pedirLimpieza($area, $this->ana, $this->hoy, $this->sofia);
        $this->ext->guardar($area, $this->hoy, [['tarea_id' => $this->lavado, 'm2' => 30]], $this->sofia);
        $this->assertCount(1, $this->ext->enEspera($area, $this->hoy)['extras']);
        $this->ext->guardar($area, $this->hoy, [], $this->sofia);
        $this->assertCount(0, $this->ext->enEspera($area, $this->hoy)['extras'], 'se puede quitar');

        $this->ext->guardar($area, $this->hoy, [['tarea_id' => $this->lavado]], $this->sofia);
        $this->chk->iniciarEjecucion($area, $this->ana, $this->hoy);
        $this->assertFalse($this->ext->enEspera($area, $this->hoy)['editable']);
        try {
            $this->ext->guardar($area, $this->hoy, [], $this->sofia);
            $this->fail('Ya empezó');
        } catch (EspacioException $e) {
            $this->assertSame('EXTRA_LIMPIEZA_EMPEZADA', $e->codigo);
        }
    }

    public function testElExtraAcompañaALaLimpiezaSiSeLaPasanAOtraPersona(): void
    {
        $area = $this->pasillo();
        $this->svc->pedirLimpieza($area, $this->ana, $this->hoy, $this->sofia, extras: [['tarea_id' => $this->lavado, 'm2' => 30]]);

        $opcion = TestDatabase::opcionId('reasignar', Database::fetchColumn("SELECT nombre FROM listas_opciones WHERE lista = 'reasignar' LIMIT 1"));
        $this->svc->pedirLimpieza($area, $this->berta, $this->hoy, $this->sofia, opcionId: $opcion);

        $ejec = $this->chk->iniciarEjecucion($area, $this->berta, $this->hoy);
        $this->assertSame('Lavado especial', $this->chk->estadoEjecucion($ejec->id)['items'][2]['descripcion']);
    }

    public function testElValorDelExtraQuedaFijoAlEmpezar(): void
    {
        $area = $this->pasillo();
        $this->svc->pedirLimpieza($area, $this->ana, $this->hoy, $this->sofia, extras: [['tarea_id' => $this->lavado, 'm2' => 30]]);
        $this->cat->editar($this->lavado, "Lavado especial", 0.4, $this->sofia);

        $ejec = $this->chk->iniciarEjecucion($area, $this->ana, $this->hoy);
        $this->assertSame(12, (int) Database::fetchColumn('SELECT creditos FROM extras_areas_comunes WHERE ejecucion_id = ?', [$ejec->id]), 'antes de empezar usa el valor nuevo');

        $this->cat->editar($this->lavado, "Lavado especial", 1.0, $this->sofia);
        $this->assertSame(12, (int) Database::fetchColumn('SELECT creditos FROM extras_areas_comunes WHERE ejecucion_id = ?', [$ejec->id]), 'empezada, ya no cambia');
    }

    public function testCambiarElValorRehaceLasAreasPeroNoLoYaLimpiado(): void
    {
        $area = $this->pasillo();
        $this->svc->pedirLimpieza($area, $this->ana, $this->hoy, $this->sofia);
        $ejec = $this->limpiar($area, $this->ana);
        $antes = (new ReportesService())->resumenAreas($this->hoy, $this->hoy)['totales']['creditos'];

        $this->assertSame(1, $this->cat->editar($this->barrer, "Barrer", 0.2, $this->sofia));

        $this->assertSame(6, $this->itemsVigentes($area)[0]['creditos'], 'la próxima limpieza usa el valor nuevo');
        $this->assertSame($antes, (new ReportesService())->resumenAreas($this->hoy, $this->hoy)['totales']['creditos'], 'lo registrado no cambia');
        $this->assertSame(9, $antes, "ejecución {$ejec}");
    }

    public function testLosCreditosDeBaseYExtrasSumanALaPersonaYAlReportePorArea(): void
    {
        $area = $this->pasillo();
        $this->svc->pedirLimpieza($area, $this->ana, $this->hoy, $this->sofia, extras: [['tarea_id' => $this->lavado, 'm2' => 30]]);
        $this->limpiar($area, $this->ana);

        $rep = new ReportesService();
        $this->assertSame(15, $rep->kpis($this->hoy, $this->hoy, 'ambos')['creditos']['valor'], '9 de la base + 6 del extra');

        $reporte = $rep->resumenAreas($this->hoy, $this->hoy);
        $this->assertSame(['limpiezas' => 1, 'creditos_base' => 9, 'creditos_extras' => 6, 'creditos' => 15], $reporte['totales']);
        $this->assertSame('Pasillo 300', $reporte['areas'][0]['nombre']);
        $this->assertTrue($reporte['areas'][0]['beta']);
        $this->assertSame(['Lavado especial'], $reporte['areas'][0]['detalle'][0]['extras']);
    }

    public function testLaInspeccionPuedeObservarUnExtra(): void
    {
        $area = $this->pasillo();
        $this->svc->pedirLimpieza($area, $this->ana, $this->hoy, $this->sofia, extras: [['tarea_id' => $this->lavado, 'm2' => 30]]);
        $ejec = $this->limpiar($area, $this->ana);
        $extraId = (int) $this->extraDe($ejec, 'Lavado especial')['id'];

        $auditoria = (new AuditoriaService())->emitirVeredicto($area, $this->sofia, 'aprobado_con_observacion', null, [$extraId]);

        $this->assertSame('Observado: Lavado especial', $auditoria->comentario);
        $this->assertSame(9, (new ReportesService())->kpis($this->hoy, $this->hoy, 'ambos')['creditos']['valor'], 'el extra desmarcado no da créditos');
        $this->assertSame(1, $this->extraDe($ejec, 'Lavado especial')['desmarcado_por_auditor']);
    }

    public function testExtraAjenoALaLimpiezaNoSeAceptaEnLaInspeccion(): void
    {
        $area = $this->pasillo();
        $this->svc->pedirLimpieza($area, $this->ana, $this->hoy, $this->sofia);
        $this->limpiar($area, $this->ana);

        $this->expectException(ChecklistException::class);
        (new AuditoriaService())->emitirVeredicto($area, $this->sofia, 'aprobado_con_observacion', null, [-999]);
    }

    public function testEnUnRechazoLosExtrasViajanALaRelimpieza(): void
    {
        $area = $this->pasillo();
        $this->svc->pedirLimpieza($area, $this->ana, $this->hoy, $this->sofia, extras: [
            ['tarea_id' => $this->lavado, 'm2' => 30],
        ]);
        $otra = $this->cat->crear('Encerar', 0.1);
        $this->ext->guardar($area, $this->hoy, [
            ['tarea_id' => $this->lavado],
            ['tarea_id' => $otra, 'm2' => 20],
        ], $this->sofia);
        $primera = $this->limpiar($area, $this->ana);
        $idLavado = (int) $this->extraDe($primera, 'Lavado especial')['id'];
        $motivo = TestDatabase::opcionId('rechazo', Database::fetchColumn("SELECT nombre FROM listas_opciones WHERE lista = 'rechazo' LIMIT 1"));

        (new AuditoriaService())->emitirVeredicto($area, $this->sofia, 'rechazado', null, [$idLavado], $motivo);
        $opcion = TestDatabase::opcionId('reasignar', Database::fetchColumn("SELECT nombre FROM listas_opciones WHERE lista = 'reasignar' LIMIT 1"));
        $this->svc->pedirLimpieza($area, $this->berta, $this->hoy, $this->sofia, opcionId: $opcion);
        $segunda = $this->chk->iniciarEjecucion($area, $this->berta, $this->hoy);

        $lavado = $this->extraDe($segunda->id, 'Lavado especial');
        $encerar = $this->extraDe($segunda->id, 'Encerar');
        $this->assertSame(0, $lavado['marcado'], 'el que desmarcó la inspección hay que rehacerlo');
        $this->assertSame(1, $lavado['rechazado_por_supervisora']);
        $this->assertSame(1, $encerar['marcado']);
        $this->assertSame($this->ana, $encerar['marcado_por'], 'queda a nombre de quien lo hizo');

        try {
            $this->chk->marcarItem($segunda->id, (int) $encerar['id'], false, $this->berta);
            $this->fail('El heredado es de solo lectura');
        } catch (ChecklistException $e) {
            $this->assertSame('ITEM_HEREDADO', $e->codigo);
        }
    }

    public function testSaltarLaLimpiezaDevuelveLosExtrasALaEsperaSinMarcar(): void
    {
        $area = $this->pasillo();
        $this->svc->pedirLimpieza($area, $this->ana, $this->hoy, $this->sofia, extras: [['tarea_id' => $this->lavado, 'm2' => 30]]);
        $ejec = $this->chk->iniciarEjecucion($area, $this->ana, $this->hoy);
        $this->chk->marcarItem($ejec->id, (int) $this->extraDe($ejec->id, 'Lavado especial')['id'], true, $this->ana);

        $this->chk->saltarEjecucion($area, $this->ana, 'No se pudo entrar', $this->hoy);

        $nueva = $this->chk->iniciarEjecucion($area, $this->ana, $this->hoy);
        $this->assertSame(0, $this->extraDe($nueva->id, 'Lavado especial')['marcado'], 'parte sin marcar');
    }

    public function testMoverUnaLimpiezaEnCursoNoDuplicaElExtra(): void
    {
        $area = $this->pasillo();
        $this->svc->pedirLimpieza($area, $this->ana, $this->hoy, $this->sofia, extras: [['tarea_id' => $this->lavado, 'm2' => 30]]);
        $ejec = $this->chk->iniciarEjecucion($area, $this->ana, $this->hoy);
        $this->chk->marcarItem($ejec->id, (int) $this->extraDe($ejec->id, 'Lavado especial')['id'], true, $this->ana);

        $opcion = TestDatabase::opcionId('reasignar', Database::fetchColumn("SELECT nombre FROM listas_opciones WHERE lista = 'reasignar' LIMIT 1"));
        $this->svc->pedirLimpieza(
            $area, $this->berta, $this->hoy, $this->sofia,
            puedeMoverEnProgreso: true, opcionId: $opcion, extras: [['tarea_id' => $this->lavado]],
        );
        $nueva = $this->chk->iniciarEjecucion($area, $this->berta, $this->hoy);

        $nombres = array_column($this->chk->estadoEjecucion($nueva->id)['items'], 'descripcion');
        $this->assertSame(['Barrer', 'Limpiar vidrios', 'Lavado especial'], $nombres);
        $this->assertSame(0, $this->extraDe($nueva->id, 'Lavado especial')['marcado'], 'parte sin marcar');
    }

    public function testLasHabitacionesNoDependenDeLaTablaDeExtras(): void
    {
        TestDatabase::sembrarChecklistTemplates();
        Database::execute('INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (1, ?, 1, ?)', ['101', 'sucia']);
        $pieza = Database::lastInsertId();
        (new AsignacionService())->asignarManual($pieza, $this->ana, $this->hoy, $this->sofia);
        Database::pdo()->exec('DROP TABLE extras_areas_comunes');

        $ejec = $this->chk->iniciarEjecucion($pieza, $this->ana, $this->hoy);
        $this->marcarTodo($ejec->id, $this->ana);
        $this->chk->completar($ejec->id, $this->ana);

        $this->assertSame('completada_pendiente_auditoria', (new HabitacionService())->obtener($pieza)?->estado);
    }

    public function testPedirLimpiezaConExtrasExigeElPermiso(): void
    {
        $area = $this->pasillo();
        $request = new Request(
            metodo: 'POST',
            path: "/api/espacios/{$area}/pedir-limpieza",
            cuerpo: ['usuario_id' => $this->ana, 'extras' => [['tarea_id' => $this->lavado, 'm2' => 30]]],
            ruta: ['id' => (string) $area],
            query: [],
            cookies: [],
            headers: [],
        );
        $request->usuario = new Usuario(
            id: $this->sofia, rut: '55555555-5', nombre: 'Sofía', email: null, activo: true, requiereCambioPwd: false,
            hotelDefault: 'ambos', temaPreferido: 'auto', permisos: ['espacios.pedir_limpieza'], roles: [],
        );

        $respuesta = (new EspaciosController())->pedirLimpieza($request);

        $this->assertSame(403, $respuesta->status);
        $this->assertNull((new AsignacionService())->obtenerActivaDeHabitacion($area, $this->hoy));
    }
}
