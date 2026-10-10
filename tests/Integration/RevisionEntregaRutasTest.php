<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Kernel;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Core\Router;
use Atankalama\Limpieza\Services\AuthService;
use Atankalama\Limpieza\Services\ModoEspiaService;
use Atankalama\Limpieza\Services\UsuarioService;
use Atankalama\Limpieza\Support\EspiaContext;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Inspección pre-entrega y la pantalla de Recepción (v7), por el router REAL (con sus middlewares):
 * ninguna API queda sin sesión ni sin PermissionCheck, el modo espía no escribe, el Inicio de Recepción
 * es Habitaciones (sin «Inicio» en el menú), Edificios y Mapeo exige su permiso propio y quitar un
 * permiso en caliente corta el acceso.
 */
final class RevisionEntregaRutasTest extends TestCase
{
    private const APIS = [
        ['GET', '/api/revision-entrega/formulario'],
        ['POST', '/api/revision-entrega'],
        ['GET', '/api/revision-entrega/motivos'],
        ['POST', '/api/revision-entrega/motivos'],
        ['PUT', '/api/revision-entrega/motivos/1'],
        ['GET', '/api/revision-entrega/config'],
        ['PUT', '/api/revision-entrega/config'],
        ['POST', '/api/revision-entrega/1/relimpiar'],
        ['GET', '/api/reportes/revision-entrega'],
    ];

    /** Marca del ítem «Inicio» del menú de abajo (views/componentes/bottom-nav.php). */
    private const ITEM_INICIO = '>Inicio</span>';

    private Router $router;
    /** @var array<string, array{id: int, token: string}> */
    private array $sesiones = [];

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado, es_espacio_comun) VALUES (1, '101', 1, 'aprobada', 0)"
        );
        Database::execute("INSERT INTO motivos_revision_entrega (nombre) VALUES ('Baño sucio')");
        foreach ([
            'trabajador' => ['11111111-1', 'Ana', 'Trabajador'],
            'recepcion' => ['22222222-2', 'Carla', 'Recepción'],
            'supervisora' => ['33333333-3', 'Sofía', 'Supervisora'],
            'admin' => ['44444444-4', 'Admin', 'Admin'],
        ] as $clave => [$rut, $nombre, $rol]) {
            [$id, $pwd] = TestDatabase::crearUsuario($rut, $nombre, $rol);
            $this->sesiones[$clave] = ['id' => $id, 'token' => (new AuthService())->login($rut, $pwd)['token']];
        }
        $this->router = Kernel::construirRouter();
    }

    protected function tearDown(): void
    {
        // EspiaContext es estático: que el banner de espía no se cuele en los tests siguientes.
        $ref = new \ReflectionClass(EspiaContext::class);
        $ref->setStaticPropertyValue('activo', false);
        $ref->setStaticPropertyValue('adminNombre', null);
        unset($_SERVER['REQUEST_URI']);
    }

    /** @param array<string, mixed> $cuerpo */
    private function pedir(string $metodo, string $path, ?string $quien = null, array $cuerpo = []): Response
    {
        $cookies = $quien === null ? [] : [AuthService::SESSION_COOKIE => $this->sesiones[$quien]['token']];
        $_SERVER['REQUEST_URI'] = $path; // el menú marca la pestaña activa con Url::rutaActual()
        return $this->router->despachar(new Request(
            metodo: $metodo, path: $path, cuerpo: $cuerpo, ruta: [], query: [], cookies: $cookies, headers: []
        ));
    }

    private function codigo(Response $resp): ?string
    {
        return json_decode($resp->cuerpo, true)['error']['codigo'] ?? null;
    }

    public function testTodasLasApisPidenSesionYPermiso(): void
    {
        foreach (self::APIS as [$metodo, $path]) {
            $sinSesion = $this->pedir($metodo, $path);
            $this->assertSame(401, $sinSesion->status, "{$metodo} {$path} sin sesión");

            $trabajador = $this->pedir($metodo, $path, 'trabajador');
            $this->assertSame(403, $trabajador->status, "{$metodo} {$path} con Trabajador");
            $this->assertSame('PERMISO_INSUFICIENTE', $this->codigo($trabajador), "{$metodo} {$path}: lo frena PermissionCheck");
        }
    }

    public function testRecepcionInspeccionaPeroNoConfigura(): void
    {
        $this->assertSame(200, $this->pedir('GET', '/api/revision-entrega/formulario', 'recepcion')->status);
        $this->assertSame(403, $this->pedir('GET', '/api/revision-entrega/motivos', 'recepcion')->status);
        $this->assertSame(403, $this->pedir('PUT', '/api/revision-entrega/config', 'recepcion', ['no_ensucia' => true])->status);

        $this->assertSame(200, $this->pedir('GET', '/api/revision-entrega/motivos', 'supervisora')->status);
        $this->assertSame(200, $this->pedir('PUT', '/api/revision-entrega/config', 'supervisora', ['no_ensucia' => true])->status);
        $this->assertSame(403, $this->pedir('GET', '/api/revision-entrega/formulario', 'supervisora')->status, 'la supervisora no inspecciona');

        // «Re-limpiar» es una asignación: Recepción no la tiene; la supervisora pasa el middleware (y la
        // revisión 99999 no existe).
        $recepcion = $this->pedir('POST', '/api/revision-entrega/99999/relimpiar', 'recepcion', ['trabajador_id' => 1]);
        $this->assertSame('PERMISO_INSUFICIENTE', $this->codigo($recepcion));
        $supervisora = $this->pedir('POST', '/api/revision-entrega/99999/relimpiar', 'supervisora', ['trabajador_id' => 1]);
        $this->assertSame(404, $supervisora->status);
        $this->assertSame('REVISION_NO_ENCONTRADA', $this->codigo($supervisora));
    }

    public function testElModoEspiaNoEscribe(): void
    {
        $admin = (new UsuarioService())->buscarPorId($this->sesiones['admin']['id']);
        $this->assertNotNull($admin);
        (new ModoEspiaService())->activar($admin, $this->sesiones['admin']['token'], $this->sesiones['recepcion']['id']);

        $this->assertSame(200, $this->pedir('GET', '/api/revision-entrega/formulario', 'admin')->status, 've como Recepción');
        $post = $this->pedir('POST', '/api/revision-entrega', 'admin', ['habitacion_id' => '1', 'resultado' => 'si']);
        $this->assertSame(403, $post->status);
        $this->assertSame('MODO_ESPIA_SOLO_LECTURA', $this->codigo($post));
        $this->assertSame(0, (int) Database::fetchColumn('SELECT COUNT(*) FROM revisiones_entrega'));
    }

    /** Pedido de Nicolás (05/10/2026): sin «Inicio»; Habitaciones es su pantalla principal y ahí inspecciona. */
    public function testElInicioDeRecepcionEsHabitacionesConLaInspeccion(): void
    {
        $inicio = $this->pedir('GET', '/home', 'recepcion');
        $this->assertSame(302, $inicio->status, 'redirige en el servidor, sin pintar el layout');
        $this->assertSame('/habitaciones', $inicio->headers()['Location'] ?? null);

        $habitaciones = $this->pedir('GET', '/habitaciones', 'recepcion');
        $this->assertSame(200, $habitaciones->status);
        $this->assertStringNotContainsString(self::ITEM_INICIO, $habitaciones->cuerpo, 'Recepción no tiene «Inicio» en el menú');
        $this->assertStringContainsString('modalInspeccionPreEntrega()', $habitaciones->cuerpo, 'la ventana de la inspección viene en la página');
        $this->assertStringNotContainsString('/revision-entrega"', $habitaciones->cuerpo, 'ya no hay pestaña aparte');

        // La supervisora conserva su Inicio y no carga la ventana (no inspecciona).
        $sup = $this->pedir('GET', '/habitaciones', 'supervisora');
        $this->assertStringContainsString(self::ITEM_INICIO, $sup->cuerpo);
        $this->assertStringNotContainsString('modalInspeccionPreEntrega()', $sup->cuerpo);
        $this->assertSame(200, $this->pedir('GET', '/home', 'supervisora')->status);

        $this->assertSame(404, $this->pedir('GET', '/revision-entrega', 'recepcion')->status, 'la pantalla aparte ya no existe');
    }

    public function testAjustesDeLaInspeccionSoloParaQuienConfigura(): void
    {
        $recepcion = $this->pedir('GET', '/ajustes/revision-entrega', 'recepcion');
        $this->assertSame(302, $recepcion->status);
        $this->assertSame('/ajustes', $recepcion->headers()['Location'] ?? null);

        $supervisora = $this->pedir('GET', '/ajustes/revision-entrega', 'supervisora');
        $this->assertSame(200, $supervisora->status);
        $this->assertStringContainsString('Cuando Recepción marca NO', $supervisora->cuerpo);
    }

    /** Pedido de Nicolás (05/10/2026): Recepción sin Edificios y Mapeo. Antes alcanzaba con ver_todas. */
    public function testEdificiosYMapeoExigeSuPermisoPropio(): void
    {
        $this->assertStringNotContainsString('/edificios"', $this->pedir('GET', '/ajustes', 'recepcion')->cuerpo, 'sin tarjeta en Ajustes');
        $this->assertStringContainsString('/edificios"', $this->pedir('GET', '/ajustes', 'supervisora')->cuerpo);

        $sinPermiso = $this->pedir('GET', '/edificios', 'recepcion');
        $this->assertSame(302, $sinPermiso->status, 'antes respondía 500: la vista «error» no existe');
        $this->assertSame('/ajustes', $sinPermiso->headers()['Location'] ?? null);
        $this->assertStringContainsString('Edificios y Mapeo', $this->pedir('GET', '/edificios', 'supervisora')->cuerpo);

        foreach ([['POST', '/api/edificios'], ['PUT', '/api/edificios/1'], ['DELETE', '/api/edificios/1'], ['PUT', '/api/habitaciones/1/estructura']] as [$metodo, $path]) {
            $resp = $this->pedir($metodo, $path, 'recepcion', ['nombre' => 'Torre B']);
            $this->assertSame(403, $resp->status, "{$metodo} {$path} con Recepción");
            $this->assertSame('PERMISO_INSUFICIENTE', $this->codigo($resp));
        }
        // Leer edificios (filtros de Habitaciones) sigue con ver_todas.
        $this->assertSame(200, $this->pedir('GET', '/api/edificios', 'recepcion')->status);
        $this->assertNotSame(403, $this->pedir('POST', '/api/edificios', 'supervisora', ['nombre' => 'Torre B', 'hotel_id' => 1])->status);
    }

    public function testQuitarElPermisoEnCalienteCortaElAcceso(): void
    {
        $this->assertSame(200, $this->pedir('GET', '/api/revision-entrega/formulario', 'recepcion')->status);

        Database::execute("DELETE FROM rol_permisos WHERE permiso_codigo = 'revision_entrega.registrar'");

        $this->assertSame(403, $this->pedir('GET', '/api/revision-entrega/formulario', 'recepcion')->status);
        $this->assertStringNotContainsString('modalInspeccionPreEntrega()', $this->pedir('GET', '/habitaciones', 'recepcion')->cuerpo);
    }
}
