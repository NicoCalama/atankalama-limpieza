<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\HorasExtrasController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Services\HorasExtrasException;
use Atankalama\Limpieza\Services\HorasExtrasService;
use Atankalama\Limpieza\Services\UsuarioService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * v7.17 «Horas extras» (gerencia, 06/11/2026; decisiones de Nicolás, 08/11/2026): el Admin carga el total de
 * horas extras del mes de cada persona de Aseo en una cuadrícula del año. Cada mes se escribe desde su día 1
 * hasta el 6 del mes siguiente; desde noviembre de 2026; de media en media hora, hasta 100 h.
 */
final class HorasExtrasTest extends TestCase
{
    private int $admin;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        [$this->admin] = TestDatabase::crearUsuario('99999999-9', 'Admin', 'Admin');
    }

    public function testElPlazoVaHastaElSeisDelMesSiguiente(): void
    {
        $this->assertSame(['2026-11'], HorasExtrasService::mesesEditables('2026-11-08'));
        $this->assertSame(['2026-11'], HorasExtrasService::mesesEditables('2026-11-03'), 'octubre es antes del inicio');
        $this->assertSame(['2026-11', '2026-12'], HorasExtrasService::mesesEditables('2026-12-01'));
        $this->assertSame(['2026-11', '2026-12'], HorasExtrasService::mesesEditables('2026-12-06'), 'el 6 todavía se puede');
        $this->assertSame(['2026-12'], HorasExtrasService::mesesEditables('2026-12-07'));
        $this->assertSame(['2026-12', '2027-01'], HorasExtrasService::mesesEditables('2027-01-02'), 'cruza el año');
        $this->assertSame([], HorasExtrasService::mesesEditables('2026-10-20'));
    }

    public function testLaCuadriculaTraeAlPersonalDeAseo(): void
    {
        [$ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');
        TestDatabase::crearUsuario('22222222-2', 'Berta', 'Aseo', activo: false);
        [$carla] = TestDatabase::crearUsuario('33333333-3', 'Carla', 'Aseo', activo: false);
        [$dora] = TestDatabase::crearUsuario('44444444-4', 'Dora', 'Aseo', activo: false);
        TestDatabase::crearUsuario('55555555-5', 'Sofía', 'Supervisora');
        TestDatabase::crearUsuario('66666666-6', 'Pía', 'Apoyo');
        [$rosa] = TestDatabase::crearUsuario('77777777-7', 'Rosa', 'Supervisora');

        $this->crearPieza('101');
        $this->asignar($carla, '2026-11-28');   // se fue a fin de mes: noviembre sigue abierto el 03/12
        $this->asignar($dora, '2026-10-15');    // octubre ya no está abierto
        Database::execute("INSERT INTO horas_extras (usuario_id, mes, minutos) VALUES (?, '2026-11', 720)", [$ana]);
        // Rosa tenía extras de cuando era de Aseo: sale igual, para que nada quede escondido.
        Database::execute("INSERT INTO horas_extras (usuario_id, mes, minutos) VALUES (?, '2026-11', 90)", [$rosa]);

        $c = $this->svc('2026-12-03')->cuadricula(2026);
        $this->assertSame(['2026-11', '2026-12'], $c['meses_editables']);
        $this->assertSame('2026-11', $c['mes_inicio']);
        $this->assertSame(6, $c['dia_plazo']);
        $this->assertSame(['Ana', 'Carla', 'Rosa'], array_column($c['personas'], 'nombre'));
        $porNombre = array_column($c['personas'], null, 'nombre');
        $this->assertSame(['2026-11' => 12.0], $porNombre['Ana']['meses']);
        $this->assertSame(['2026-11' => 1.5], $porNombre['Rosa']['meses']);
        $this->assertSame([], $porNombre['Carla']['meses']);
        $this->assertFalse($porNombre['Carla']['activo']);

        // Pasado el plazo de noviembre, Carla ya no sale (no tiene extras ni está activa).
        $c = $this->svc('2026-12-07')->cuadricula(2026);
        $this->assertSame(['Ana', 'Rosa'], array_column($c['personas'], 'nombre'));

        // Un año antes del inicio o futuro no se pide.
        $e = $this->capturar(fn() => $this->svc('2026-12-03')->cuadricula(2025));
        $this->assertSame('ANIO_INVALIDO', $e->codigo);
        $e = $this->capturar(fn() => $this->svc('2026-12-03')->cuadricula(2027));
        $this->assertSame('ANIO_INVALIDO', $e->codigo);
    }

    public function testGuardaCambiaYBorraElTotalDelMes(): void
    {
        [$ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');
        $svc = $this->svc('2026-11-20');

        $this->assertSame(12.0, $svc->guardar($ana, '2026-11', 12, $this->admin));
        $this->assertSame(7.5, $svc->guardar($ana, '2026-11', '7,5', $this->admin), 'acepta la coma');
        $this->assertSame(450, (int) Database::fetchColumn('SELECT minutos FROM horas_extras WHERE usuario_id = ?', [$ana]));
        $this->assertSame(7.5, $svc->guardar($ana, '2026-11', 7.5, $this->admin), 'lo mismo no cambia nada');
        $this->assertNull($svc->guardar($ana, '2026-11', 0, $this->admin), '0 lo borra');
        $this->assertSame(0, (int) Database::fetchColumn('SELECT COUNT(*) FROM horas_extras'));
        $this->assertNull($svc->guardar($ana, '2026-11', '', $this->admin), 'vacío sin fila: nada');

        $acciones = Database::fetchAll("SELECT detalles_json FROM audit_log WHERE accion = 'horas_extras.guardar' ORDER BY id");
        $this->assertCount(3, $acciones, 'crear, cambiar y borrar; lo repetido no se registra');
        $ultimo = json_decode((string) $acciones[2]['detalles_json'], true);
        $this->assertSame(['mes' => '2026-11', 'antes_minutos' => 450, 'despues_minutos' => null], $ultimo);
    }

    public function testValidaLasHorasElMesYLaPersona(): void
    {
        [$ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');
        [$sofia] = TestDatabase::crearUsuario('55555555-5', 'Sofía', 'Supervisora');
        $svc = $this->svc('2026-11-20');

        foreach ([7.3, 100.5, -1, 'abc'] as $malo) {
            $this->assertSame('HORAS_INVALIDAS', $this->capturar(fn() => $svc->guardar($ana, '2026-11', $malo, $this->admin))->codigo, (string) $malo);
        }
        $this->assertSame(100.0, $svc->guardar($ana, '2026-11', 100, $this->admin), '100 h es el tope');
        $this->assertSame('MES_INVALIDO', $this->capturar(fn() => $svc->guardar($ana, '2026-13', 1, $this->admin))->codigo);
        $this->assertSame('PERSONA_NO_ENCONTRADA', $this->capturar(fn() => $svc->guardar(9999, '2026-11', 1, $this->admin))->codigo);
        $e = $this->capturar(fn() => $svc->guardar($sofia, '2026-11', 2, $this->admin));
        $this->assertSame('NO_ES_DE_ASEO', $e->codigo);
        $this->assertSame(400, $e->httpStatus);

        // Si le cambian el rol después de cargarle extras, se pueden corregir y borrar igual.
        Database::execute("UPDATE usuarios_roles SET rol_id = (SELECT id FROM roles WHERE nombre = 'Supervisora') WHERE usuario_id = ?", [$ana]);
        $this->assertSame(4.0, $svc->guardar($ana, '2026-11', 4, $this->admin));
        $this->assertNull($svc->guardar($ana, '2026-11', null, $this->admin));
    }

    public function testFueraDePlazoNoSeEscribe(): void
    {
        [$ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');

        $e = $this->capturar(fn() => $this->svc('2026-12-07')->guardar($ana, '2026-11', 3, $this->admin));
        $this->assertSame('FUERA_DE_PLAZO', $e->codigo);
        $this->assertSame(409, $e->httpStatus);
        $this->assertSame('Las horas extras de noviembre de 2026 se podían cargar hasta el 6 de diciembre de 2026.', $e->getMessage());

        $e = $this->capturar(fn() => $this->svc('2026-11-20')->guardar($ana, '2026-12', 3, $this->admin));
        $this->assertSame('FUERA_DE_PLAZO', $e->codigo);
        $this->assertSame('Ese mes todavía no empieza.', $e->getMessage());

        $e = $this->capturar(fn() => $this->svc('2026-11-03')->guardar($ana, '2026-10', 3, $this->admin));
        $this->assertSame('ANTES_DE_INICIO', $e->codigo);

        // El 6 de diciembre todavía se corrige noviembre.
        $this->assertSame(3.0, $this->svc('2026-12-06')->guardar($ana, '2026-11', 3, $this->admin));
    }

    public function testElControladorExigeElPermiso(): void
    {
        [$ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');
        [$sofia] = TestDatabase::crearUsuario('55555555-5', 'Sofía', 'Supervisora');
        $ctrl = new HorasExtrasController($this->svc('2026-11-20'));

        $r = $this->json($ctrl->listar($this->req('GET', '/api/reportes/horas-extras', $this->admin)));
        $this->assertTrue($r['ok']);
        $this->assertSame(2026, $r['data']['anio'], 'por defecto, el año en curso');
        $this->assertSame(['2026-11'], $r['data']['meses_editables']);
        $this->assertSame(['Ana'], array_column($r['data']['personas'], 'nombre'));

        $r = $this->json($ctrl->listar($this->req('GET', '/api/reportes/horas-extras', $this->admin, query: ['anio' => 'x'])));
        $this->assertSame('ANIO_INVALIDO', $r['error']['codigo']);

        $put = $this->req('PUT', '/api/reportes/horas-extras/' . $ana, $this->admin, cuerpo: ['mes' => '2026-11', 'horas' => 6.5], ruta: ['usuario_id' => (string) $ana]);
        $r = $this->json($ctrl->guardar($put));
        $this->assertTrue($r['ok']);
        $this->assertSame(6.5, $r['data']['horas']);

        $put = $this->req('PUT', '/api/reportes/horas-extras/' . $ana, $this->admin, cuerpo: ['mes' => '2026-11', 'horas' => [1]], ruta: ['usuario_id' => (string) $ana]);
        $this->assertSame('PARAMETROS_INVALIDOS', $this->json($ctrl->guardar($put))['error']['codigo']);

        // La supervisora no tiene el permiso (las extras las carga administración).
        $r = $this->json($ctrl->listar($this->req('GET', '/api/reportes/horas-extras', $sofia)));
        $this->assertSame('SIN_PERMISO', $r['error']['codigo']);
        $put = $this->req('PUT', '/api/reportes/horas-extras/' . $ana, $sofia, cuerpo: ['mes' => '2026-11', 'horas' => 1], ruta: ['usuario_id' => (string) $ana]);
        $this->assertSame('SIN_PERMISO', $this->json($ctrl->guardar($put))['error']['codigo']);
    }

    // ───────────────────────────── Ayudas ─────────────────────────────

    private function svc(string $hoy): HorasExtrasService
    {
        return new HorasExtrasService(hoyFijo: $hoy);
    }

    private function crearPieza(string $numero): void
    {
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        Database::execute("INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (1, ?, 1, 'sucia')", [$numero]);
    }

    private function asignar(int $usuarioId, string $fecha): void
    {
        Database::execute('INSERT INTO asignaciones (habitacion_id, usuario_id, fecha, orden_cola) VALUES (1, ?, ?, 1)', [$usuarioId, $fecha]);
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

    private function capturar(callable $fn): HorasExtrasException
    {
        try {
            $fn();
        } catch (HorasExtrasException $e) {
            return $e;
        }
        $this->fail('Se esperaba HorasExtrasException');
    }
}
