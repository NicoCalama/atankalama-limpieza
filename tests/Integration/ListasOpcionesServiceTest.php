<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\ListasOpcionesController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Models\Usuario;
use Atankalama\Limpieza\Services\ListasOpcionesException;
use Atankalama\Limpieza\Services\ListasOpcionesService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Listas de selección (v7.3, gerencia 10/10/2026: las acciones no llevan cuadro de texto). Servicio y
 * controlador: crear, renombrar, ordenar, activar/desactivar, la última opción activa, duplicados sin
 * distinguir mayúsculas ni tildes, la lista del NO de Recepción (su propia tabla) y el permiso.
 */
final class ListasOpcionesServiceTest extends TestCase
{
    private ListasOpcionesService $svc;
    private int $admin;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        $this->svc = new ListasOpcionesService();
        [$this->admin] = TestDatabase::crearUsuario('11111111-1', 'Admin Prueba', 'Admin');
        foreach (['Baño sucio', 'Mal olor', 'Otro'] as $i => $nombre) {
            Database::execute('INSERT INTO motivos_revision_entrega (nombre, orden) VALUES (?, ?)', [$nombre, $i + 1]);
        }
    }

    /** @return list<string> */
    private function nombres(string $lista, bool $soloActivas = true): array
    {
        return array_map(static fn(array $o): string => $o['nombre'], $this->svc->listar($lista, $soloActivas));
    }

    private function esperarError(string $codigo, callable $fn): void
    {
        try {
            $fn();
            $this->fail("se esperaba {$codigo}");
        } catch (ListasOpcionesException $e) {
            $this->assertSame($codigo, $e->codigo);
        }
    }

    public function testLasListasNacenConLasOpcionesSembradasEnSuOrden(): void
    {
        $this->assertSame(['Huésped no ha salido', 'Falta un insumo', 'Requiere mantención', 'Otro'], $this->nombres('saltar'));
        $this->assertSame('Baño sucio', $this->nombres('rechazo')[0]);
        $this->assertSame(['Sobrecarga de trabajo', 'Ausencia o atraso', 'Cambio de prioridad', 'Otro'], $this->nombres('reasignar'));
        $this->assertSame(['Baño sucio', 'Mal olor', 'Otro'], $this->nombres('no_recepcion'));
    }

    public function testCrearAgregaAlFinalYValidaElNombre(): void
    {
        $id = $this->svc->crear('saltar', '  Pieza   bloqueada ', $this->admin);
        $opciones = $this->svc->listar('saltar');
        $this->assertSame('Pieza bloqueada', end($opciones)['nombre'], 'al final y con espacios normalizados');
        $this->assertGreaterThan(0, $id);

        $this->esperarError('NOMBRE_INVALIDO', fn() => $this->svc->crear('saltar', 'x', $this->admin));
        $this->esperarError('NOMBRE_INVALIDO', fn() => $this->svc->crear('saltar', str_repeat('a', 61), $this->admin));
        $this->esperarError('OPCION_DUPLICADA', fn() => $this->svc->crear('saltar', 'HUÉSPED NO HA SALIDO', $this->admin));
        $this->esperarError('LISTA_INVALIDA', fn() => $this->svc->crear('inventada', 'Algo', $this->admin));

        // El mismo nombre sí puede estar en otra lista.
        $this->svc->crear('reasignar', 'Pieza bloqueada', $this->admin);
        $this->assertContains('Pieza bloqueada', $this->nombres('reasignar'));
    }

    public function testRenombrarYDesactivarConservanLaOpcion(): void
    {
        $id = $this->svc->listar('reasignar')[0]['id'];
        $this->svc->actualizar('reasignar', $id, ['nombre' => 'Mucha carga'], $this->admin);
        $this->svc->actualizar('reasignar', $id, ['activo' => false], $this->admin);

        $this->assertNotContains('Mucha carga', $this->nombres('reasignar'), 'desactivada no se ofrece');
        $this->assertContains('Mucha carga', $this->nombres('reasignar', false), 'pero sigue existiendo');
        $this->esperarError('OPCION_INVALIDA', fn() => $this->svc->opcionActiva('reasignar', $id));
        $this->esperarError('OPCION_DUPLICADA', fn() => $this->svc->actualizar('reasignar', $id, ['nombre' => 'otro'], $this->admin));
        $this->esperarError('OPCION_NO_ENCONTRADA', fn() => $this->svc->actualizar('reasignar', 99999, ['activo' => true], $this->admin));
        // Una opción de otra lista no se puede tocar por esta.
        $deSaltar = $this->svc->listar('saltar')[0]['id'];
        $this->esperarError('OPCION_NO_ENCONTRADA', fn() => $this->svc->actualizar('reasignar', $deSaltar, ['activo' => false], $this->admin));
    }

    public function testUnaListaNuncaQuedaSinOpcionesActivas(): void
    {
        $ops = $this->svc->listar('saltar');
        foreach (array_slice($ops, 1) as $o) {
            $this->svc->actualizar('saltar', $o['id'], ['activo' => false], $this->admin);
        }
        $this->esperarError('ULTIMA_OPCION_ACTIVA', fn() => $this->svc->actualizar('saltar', $ops[0]['id'], ['activo' => false], $this->admin));
        $this->assertSame(['Huésped no ha salido'], $this->nombres('saltar'));
    }

    public function testReordenarExigeTodasLasOpcionesDeLaLista(): void
    {
        $ids = array_map(static fn(array $o): int => $o['id'], $this->svc->listar('saltar', false));
        $this->svc->reordenar('saltar', array_reverse($ids), $this->admin);
        $this->assertSame(['Otro', 'Requiere mantención', 'Falta un insumo', 'Huésped no ha salido'], $this->nombres('saltar'));

        $this->esperarError('ORDEN_DESACTUALIZADO', fn() => $this->svc->reordenar('saltar', array_slice($ids, 1), $this->admin));
        $deOtra = $this->svc->listar('rechazo')[0]['id'];
        $this->esperarError('ORDEN_DESACTUALIZADO', fn() => $this->svc->reordenar('saltar', [...array_slice($ids, 1), $deOtra], $this->admin));
    }

    public function testLaListaDelNoDeRecepcionUsaSuPropiaTabla(): void
    {
        $id = $this->svc->crear('no_recepcion', 'Toallas manchadas', $this->admin);
        $this->assertSame(
            'Toallas manchadas',
            Database::fetchColumn('SELECT nombre FROM motivos_revision_entrega WHERE id = ?', [$id])
        );
        $this->assertSame(['Baño sucio', 'Mal olor', 'Otro', 'Toallas manchadas'], $this->nombres('no_recepcion'));
        $this->assertSame(0, (int) Database::fetchColumn("SELECT COUNT(*) FROM listas_opciones WHERE nombre = 'Toallas manchadas'"));
        $this->assertSame(['id' => $id, 'nombre' => 'Toallas manchadas'], $this->svc->opcionActiva('no_recepcion', $id));
    }

    public function testElResumenCuentaActivasYTotal(): void
    {
        $id = $this->svc->listar('rechazo')[0]['id'];
        $this->svc->actualizar('rechazo', $id, ['activo' => false], $this->admin);
        $resumen = array_column($this->svc->resumen(), null, 'lista');
        $this->assertSame(['lista' => 'rechazo', 'activas' => 7, 'total' => 8], $resumen['rechazo']);
        $this->assertSame(['rechazo', 'reasignar', 'saltar', 'no_recepcion', 'nota_recepcion', 'ticket_tipo', 'ticket_respuesta'], array_keys($resumen));
    }

    /** v7.4: el mensaje a la mucama admite varias opciones; se devuelven en el orden de la lista y sin repetir. */
    public function testNombresActivosDeVariasOpciones(): void
    {
        $cuna = TestDatabase::opcionId('nota_recepcion', 'Cuna');
        $extra = TestDatabase::opcionId('nota_recepcion', 'Cama extra');
        $this->assertSame(['Cama extra', 'Cuna'], $this->svc->nombresActivos('nota_recepcion', [$cuna, $extra, $cuna]));
        $this->esperarError('OPCION_REQUERIDA', fn() => $this->svc->nombresActivos('nota_recepcion', []));
        $this->esperarError('OPCION_INVALIDA', fn() => $this->svc->nombresActivos('nota_recepcion', [$cuna, TestDatabase::opcionId('saltar', 'Otro')]));
    }

    public function testElControladorPideListasEditarParaEditarYSesionParaLeer(): void
    {
        $ctrl = new ListasOpcionesController();
        $sinPermiso = $this->req('GET', ruta: ['lista' => 'saltar'], permisos: []);
        $this->assertSame(200, $ctrl->listar($sinPermiso)->status, 'leer las activas solo pide sesión');
        $this->assertSame(403, $ctrl->listar($this->req('GET', query: ['todos' => '1'], ruta: ['lista' => 'saltar'], permisos: []))->status);
        $this->assertSame(403, $ctrl->crear($this->req('POST', ['nombre' => 'Algo'], ruta: ['lista' => 'saltar'], permisos: []))->status);
        $this->assertSame(401, $ctrl->listar($this->req('GET', ruta: ['lista' => 'saltar'], permisos: null))->status);
        $this->assertSame(404, $ctrl->listar($this->req('GET', ruta: ['lista' => 'inventada'], permisos: []))->status);

        $creada = $ctrl->crear($this->req('POST', ['nombre' => 'Llave perdida'], ruta: ['lista' => 'saltar']));
        $this->assertSame(201, $creada->status);
        $this->assertSame(400, $ctrl->actualizar($this->req('PUT', ['activo' => 'no'], ruta: ['lista' => 'saltar', 'id' => '1']))->status);
        $this->assertSame(400, $ctrl->reordenar($this->req('PUT', ['ids' => ['1']], ruta: ['lista' => 'saltar']))->status);
        $this->assertSame(200, $ctrl->resumen($this->req('GET'))->status);
    }

    /**
     * @param array<string, mixed> $cuerpo
     * @param array<string, string> $query
     * @param array<string, string> $ruta
     * @param list<string>|null $permisos null = sin sesión
     */
    private function req(string $metodo, array $cuerpo = [], array $query = [], array $ruta = [], ?array $permisos = ['listas.editar']): Request
    {
        $r = new Request(metodo: $metodo, path: '/api/listas', cuerpo: $cuerpo, ruta: $ruta, query: $query, cookies: [], headers: []);
        $r->usuario = $permisos === null ? null : new Usuario(
            id: $this->admin,
            rut: '11111111-1',
            nombre: 'Admin',
            email: null,
            activo: true,
            requiereCambioPwd: false,
            hotelDefault: null,
            temaPreferido: 'claro',
            permisos: $permisos,
            roles: ['Admin'],
        );
        return $r;
    }
}
