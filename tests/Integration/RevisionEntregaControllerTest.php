<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\HabitacionesController;
use Atankalama\Limpieza\Controllers\ReportesController;
use Atankalama\Limpieza\Controllers\RevisionEntregaController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Models\Usuario;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\RevisionEntregaService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Controllers de la inspección pre-entrega, sin middleware (eso lo cubre RevisionEntregaRutasTest):
 * respuestas, códigos de error, el resultado de hoy en la lista de Habitaciones, la última revisión en
 * el detalle de la pieza y la sección de Reportes. La rama con foto real no se testea acá:
 * is_uploaded_file() no se simula.
 */
final class RevisionEntregaControllerTest extends TestCase
{
    private RevisionEntregaController $ctrl;
    private int $carla;
    private int $ana;
    private int $hab101;
    private int $motivo;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur'), ('inn', 'Inn')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        $hotel = (int) Database::fetchColumn("SELECT id FROM hoteles WHERE codigo = '1_sur'");
        $tipo = (int) Database::fetchColumn('SELECT id FROM tipos_habitacion LIMIT 1');
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado, es_espacio_comun) VALUES (?, '101', ?, 'aprobada', 0)",
            [$hotel, $tipo]
        );
        $this->hab101 = Database::lastInsertId();
        [$this->carla] = TestDatabase::crearUsuario('33333333-3', 'Carla', 'Recepción');
        [$this->ana] = TestDatabase::crearUsuario('22222222-2', 'Ana', 'Trabajador');
        Database::execute("INSERT INTO motivos_revision_entrega (nombre, activo) VALUES ('Baño sucio', 1), ('Viejo', 0)");
        $this->motivo = (int) Database::fetchColumn("SELECT id FROM motivos_revision_entrega WHERE nombre = 'Baño sucio'");

        $this->ctrl = new RevisionEntregaController(new RevisionEntregaService());
    }

    /** @param list<string> $permisos */
    private function usuario(array $permisos, ?int $id = null): Usuario
    {
        return new Usuario(
            id: $id ?? $this->carla,
            rut: '33333333-3',
            nombre: 'Carla',
            email: null,
            activo: true,
            requiereCambioPwd: false,
            hotelDefault: null,
            temaPreferido: 'claro',
            permisos: $permisos,
            roles: ['Recepción'],
        );
    }

    /**
     * @param array<string, mixed> $cuerpo
     * @param array<string, string> $query
     * @param array<string, string> $ruta
     * @param list<string>|null $permisos null = sin sesión
     */
    private function req(string $metodo, array $cuerpo = [], array $query = [], array $ruta = [], ?array $permisos = ['revision_entrega.registrar', 'revision_entrega.configurar']): Request
    {
        $r = new Request(metodo: $metodo, path: '/api/revision-entrega', cuerpo: $cuerpo, ruta: $ruta, query: $query, cookies: [], headers: []);
        $r->usuario = $permisos === null ? null : $this->usuario($permisos);
        return $r;
    }

    /** @return array<string, mixed> */
    private function ok(Response $resp, int $status = 200): array
    {
        $this->assertSame($status, $resp->status, $resp->cuerpo);
        return json_decode($resp->cuerpo, true)['data'];
    }

    private function error(Response $resp, int $status, string $codigo): void
    {
        $this->assertSame($status, $resp->status, $resp->cuerpo);
        $this->assertSame($codigo, json_decode($resp->cuerpo, true)['error']['codigo']);
    }

    public function testRegistrarResponde201YLaMismaClaveDevuelveLaRepetida(): void
    {
        $cuerpo = ['habitacion_id' => (string) $this->hab101, 'resultado' => 'si', 'idempotency_key' => 'k-1'];

        $primera = $this->ok($this->ctrl->registrar($this->req('POST', $cuerpo)), 201);
        $this->assertSame('si', $primera['revision']['resultado']);
        $this->assertFalse($primera['repetida']);
        $this->assertNull($primera['foto_fallida']);

        $segunda = $this->ok($this->ctrl->registrar($this->req('POST', $cuerpo)));
        $this->assertTrue($segunda['repetida']);
        $this->assertSame($primera['revision']['id'], $segunda['revision']['id']);
    }

    public function testRegistrarValidaSesionPermisoYDatos(): void
    {
        $base = ['habitacion_id' => (string) $this->hab101, 'resultado' => 'si'];
        $this->error($this->ctrl->registrar($this->req('POST', $base, permisos: null)), 401, 'NO_AUTENTICADO');
        $this->error($this->ctrl->registrar($this->req('POST', $base, permisos: ['habitaciones.ver_todas'])), 403, 'SIN_PERMISO');
        $this->error($this->ctrl->registrar($this->req('POST', ['resultado' => 'si'])), 400, 'PARAMETROS_INVALIDOS');
        $this->error($this->ctrl->registrar($this->req('POST', ['habitacion_id' => (string) $this->hab101, 'resultado' => 'tal vez'])), 400, 'RESULTADO_INVALIDO');
        $this->error($this->ctrl->registrar($this->req('POST', ['habitacion_id' => (string) $this->hab101, 'resultado' => 'no'])), 400, 'MOTIVO_REQUERIDO');
        $this->error($this->ctrl->registrar($this->req('POST', ['habitacion_id' => '99999', 'resultado' => 'si'])), 404, 'HABITACION_NO_ENCONTRADA');

        $no = $this->ok($this->ctrl->registrar($this->req('POST', [
            'habitacion_id' => (string) $this->hab101, 'resultado' => 'no', 'motivo_id' => (string) $this->motivo, 'comentario' => 'pelos',
        ])), 201);
        $this->assertSame('Baño sucio', $no['revision']['motivo_nombre']);
        $this->assertNull($no['foto_fallida'], 'sin foto no hay nada que haya fallado');
    }

    public function testElFormularioTraeLosMotivosActivosYElInterruptor(): void
    {
        $data = $this->ok($this->ctrl->formulario($this->req('GET')));
        $this->assertSame(['Baño sucio'], array_column($data['motivos'], 'nombre'));
        $this->assertFalse($data['no_ensucia']);

        $this->error($this->ctrl->formulario($this->req('GET', permisos: ['revision_entrega.configurar'])), 403, 'SIN_PERMISO');
    }

    /**
     * La lista de Habitaciones trae la inspección vigente de cada pieza (hasta que la pieza cambia de
     * estado): la pinta el botón de la tarjeta.
     */
    public function testLaListaDeHabitacionesTraeLaInspeccionVigente(): void
    {
        $this->ctrl->registrar($this->req('POST', ['habitacion_id' => (string) $this->hab101, 'resultado' => 'si']));

        $r = new Request(metodo: 'GET', path: '/api/habitaciones', cuerpo: [], ruta: [], query: [], cookies: [], headers: []);
        $r->usuario = $this->usuario(['habitaciones.ver_todas']);
        $data = $this->ok((new HabitacionesController())->listar($r));
        $this->assertSame('si', $data['habitaciones'][0]['revision_vigente']['resultado'] ?? null);
        $this->assertArrayNotHasKey('revision_hoy', $data['habitaciones'][0], 'la clave vieja ya no viaja');
    }

    /**
     * Revisión de la v6.18: si la tabla de la inspección no existe (código subido antes que el SQL),
     * la lista y el detalle de Habitaciones, que son pantallas centrales, igual cargan.
     */
    public function testSinLaTablaNuevaHabitacionesIgualCarga(): void
    {
        Database::pdo()->exec('DROP TABLE revisiones_entrega');
        $ctrl = new HabitacionesController();

        $lista = new Request(metodo: 'GET', path: '/api/habitaciones', cuerpo: [], ruta: [], query: [], cookies: [], headers: []);
        $lista->usuario = $this->usuario(['habitaciones.ver_todas']);
        $this->assertNull($this->ok($ctrl->listar($lista))['habitaciones'][0]['revision_vigente']);

        $detalle = new Request(metodo: 'GET', path: '/api/habitaciones/' . $this->hab101, cuerpo: [], ruta: ['id' => (string) $this->hab101], query: [], cookies: [], headers: []);
        $detalle->usuario = $this->usuario(['habitaciones.ver_todas']);
        $this->assertNull($this->ok($ctrl->obtener($detalle))['habitacion']['revision_entrega']);
    }

    public function testMotivosYConfigSonDeQuienConfigura(): void
    {
        $soloRegistrar = ['revision_entrega.registrar'];
        $this->error($this->ctrl->listarMotivos($this->req('GET', permisos: $soloRegistrar)), 403, 'SIN_PERMISO');
        $this->error($this->ctrl->guardarConfig($this->req('PUT', ['no_ensucia' => true], permisos: $soloRegistrar)), 403, 'SIN_PERMISO');

        $id = $this->ok($this->ctrl->crearMotivo($this->req('POST', ['nombre' => 'Mal olor'])), 201)['id'];
        $this->error($this->ctrl->crearMotivo($this->req('POST', ['nombre' => '   '])), 400, 'PARAMETROS_INVALIDOS');
        $this->error($this->ctrl->crearMotivo($this->req('POST', ['nombre' => 'mal olor'])), 409, 'MOTIVO_DUPLICADO');

        $this->ok($this->ctrl->actualizarMotivo($this->req('PUT', ['activo' => false], ruta: ['id' => (string) $id])));
        $this->error($this->ctrl->actualizarMotivo($this->req('PUT', ['activo' => false], ruta: ['id' => '999'])), 404, 'MOTIVO_NO_ENCONTRADO');

        $activos = array_column($this->ok($this->ctrl->listarMotivos($this->req('GET')))['motivos'], 'nombre');
        $todos = array_column($this->ok($this->ctrl->listarMotivos($this->req('GET', query: ['todos' => '1'])))['motivos'], 'nombre');
        $this->assertSame(['Baño sucio'], $activos);
        $this->assertSame(['Baño sucio', 'Mal olor', 'Viejo'], $todos);

        $this->assertFalse($this->ok($this->ctrl->config($this->req('GET')))['no_ensucia']);
        $this->error($this->ctrl->guardarConfig($this->req('PUT', ['no_ensucia' => 'si'])), 400, 'PARAMETROS_INVALIDOS');
        $this->ok($this->ctrl->guardarConfig($this->req('PUT', ['no_ensucia' => true])));
        $this->assertTrue($this->ok($this->ctrl->config($this->req('GET')))['no_ensucia']);
    }

    public function testElDetalleDeLaPiezaTraeLaRevisionSoloAQuienVeTodas(): void
    {
        $this->ctrl->registrar($this->req('POST', [
            'habitacion_id' => (string) $this->hab101, 'resultado' => 'no', 'motivo_id' => (string) $this->motivo,
        ]));
        $habCtrl = new HabitacionesController();

        $r = new Request(metodo: 'GET', path: '/api/habitaciones/' . $this->hab101, cuerpo: [], ruta: ['id' => (string) $this->hab101], query: [], cookies: [], headers: []);
        $r->usuario = $this->usuario(['habitaciones.ver_todas']);
        $hab = $this->ok($habCtrl->obtener($r))['habitacion'];
        $this->assertSame('no', $hab['revision_entrega']['resultado']);
        $this->assertSame('Baño sucio', $hab['revision_entrega']['motivo_nombre']);

        // La trabajadora con la pieza asignada hoy ve el detalle, pero no la revisión.
        (new AsignacionService())->asignarManual($this->hab101, $this->ana, date('Y-m-d'));
        $r->usuario = $this->usuario(['habitaciones.ver_asignadas_propias'], $this->ana);
        $habTrabajadora = $this->ok($habCtrl->obtener($r))['habitacion'];
        $this->assertArrayNotHasKey('revision_entrega', $habTrabajadora);
    }

    public function testReportesDevuelveLaSeccionConLosFiltrosDeLaUrl(): void
    {
        $this->ctrl->registrar($this->req('POST', [
            'habitacion_id' => (string) $this->hab101, 'resultado' => 'no', 'motivo_id' => (string) $this->motivo,
        ]));
        $hoy = date('Y-m-d');

        $r = new Request(metodo: 'GET', path: '/api/reportes/revision-entrega', cuerpo: [], ruta: [], query: ['desde' => $hoy, 'hasta' => $hoy, 'hotel' => '1_sur'], cookies: [], headers: []);
        $r->usuario = $this->usuario(['reportes.ver']);
        $data = $this->ok((new ReportesController())->revisionEntrega($r));
        $this->assertSame(['desde' => $hoy, 'hasta' => $hoy, 'hotel' => '1_sur'], $data['filtros']);
        $this->assertSame(1, $data['resumen']['no']);
        $this->assertSame('Baño sucio', $data['por_motivo'][0]['nombre']);

        $r->usuario = $this->usuario(['revision_entrega.registrar']);
        $this->error((new ReportesController())->revisionEntrega($r), 403, 'SIN_PERMISO');
    }
}
