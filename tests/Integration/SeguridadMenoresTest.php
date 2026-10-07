<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\AlertasController;
use Atankalama\Limpieza\Controllers\AuditoriaController;
use Atankalama\Limpieza\Controllers\CopilotController;
use Atankalama\Limpieza\Controllers\NotificacionesController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Kernel;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Helpers\Rut;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\AuthException;
use Atankalama\Limpieza\Services\AuthService;
use Atankalama\Limpieza\Services\ChecklistException;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\Copilot\CopilotToolExecutor;
use Atankalama\Limpieza\Services\HomeService;
use Atankalama\Limpieza\Services\ImagenAdjuntoService;
use Atankalama\Limpieza\Services\ImagenException;
use Atankalama\Limpieza\Services\NotificacionesService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Services\TicketService;
use Atankalama\Limpieza\Services\UsuarioService;
use Atankalama\Limpieza\Support\EspiaContext;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tanda 6 de la auditoría de código (07/10/2026): hallazgos de severidad baja.
 */
final class SeguridadMenoresTest extends TestCase
{
    private int $hotelId;
    private int $tipoId;
    private int $adminId;
    private string $adminPwd;
    private int $supId;
    private int $anaId;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();
        $this->hotelId = (int) Database::fetchColumn("SELECT id FROM hoteles WHERE codigo='1_sur'");
        $this->tipoId = (int) Database::fetchColumn('SELECT id FROM tipos_habitacion LIMIT 1');
        [$this->adminId, $this->adminPwd] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');
        [$this->supId] = TestDatabase::crearUsuario('22222222-2', 'Sofía', 'Supervisora');
        [$this->anaId] = TestDatabase::crearUsuario('33333333-3', 'Ana', 'Trabajador');
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(EspiaContext::class);
        $ref->setStaticPropertyValue('activo', false);
        $ref->setStaticPropertyValue('adminNombre', null);
    }

    // --- RBAC por permiso, no por nombre de rol --------------------------------------------

    public function testElAvisoDeComentarioDeTicketVaPorPermisoNoPorNombreDeRol(): void
    {
        Database::execute("UPDATE roles SET nombre = 'Jefa de turno' WHERE nombre = 'Supervisora'");
        $tickets = new TicketService();
        $ticket = $tickets->crear($this->hotelId, 'Ducha gotea', 'En la 101', 'normal', $this->anaId);

        $tickets->comentar($ticket->id, $this->anaId, 'Sigue goteando', true);

        $this->assertSame(1, (int) Database::fetchColumn('SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ?', [$this->supId]), 'el rol renombrado sigue recibiendo el aviso');
    }

    public function testElCorreoDiarioVaAQuienAdministraAunqueSuRolSeLlameDistinto(): void
    {
        Database::execute("UPDATE usuarios SET email = 'admin@ejemplo.cl' WHERE id = ?", [$this->adminId]);
        Database::execute("UPDATE roles SET nombre = 'Gerencia' WHERE nombre = 'Admin'");

        $ids = array_column((new ReportesService())->destinatariosAdminConEmail(), 'id');
        $this->assertSame([$this->adminId], array_map('intval', $ids));
    }

    // --- Permisos del catálogo que ahora sí se revisan -------------------------------------

    public function testLasNotificacionesExigenNotificacionesVer(): void
    {
        $this->quitarPermiso('Trabajador', 'notificaciones.ver');
        $resp = $this->pedir('GET', '/api/notificaciones/sin-leer', $this->login('33333333-3'));
        $this->assertSame(403, $resp->status);
    }

    public function testExportarMisDatosExigeSuPermiso(): void
    {
        $this->quitarPermiso('Trabajador', 'usuarios.exportar_datos_propios');
        $resp = $this->pedir('GET', "/api/usuarios/{$this->anaId}/datos-personales", $this->login('33333333-3'));
        $this->assertSame(403, $resp->status);
    }

    public function testLeerLaMatrizDeRolesYaNoAlcanzaConAjustesAcceder(): void
    {
        Database::execute("INSERT INTO roles (nombre, descripcion, es_sistema) VALUES ('Solo ajustes', '', 0)");
        $rol = Database::lastInsertId();
        Database::execute("INSERT INTO rol_permisos (rol_id, permiso_codigo) VALUES (?, 'ajustes.acceder')", [$rol]);
        Database::execute('UPDATE usuarios_roles SET rol_id = ? WHERE usuario_id = ?', [$rol, $this->anaId]);

        $this->assertSame(403, $this->pedir('GET', '/api/roles', $this->login('33333333-3'))->status);
        $this->assertSame(200, $this->pedir('GET', '/api/roles', $this->login('11111111-1'))->status);
    }

    // --- Modo espía ------------------------------------------------------------------------

    public function testEnModoEspiaLaCampanitaNoMarcaLeidas(): void
    {
        (new NotificacionesService())->crear($this->anaId, 'asignacion', 'Nueva pieza', 'La 101', '/home');
        $req = new Request(metodo: 'GET', path: '/api/notificaciones');
        $req->usuario = (new UsuarioService())->buscarPorId($this->anaId);
        $req->espiaAdminId = $this->adminId;

        (new NotificacionesController())->listar($req);

        $this->assertSame(1, (int) Database::fetchColumn('SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND leida = 0', [$this->anaId]));
    }

    // --- Login y RUT -----------------------------------------------------------------------

    public function testUnaCuentaInactivaConClaveIncorrectaNoSeDelata(): void
    {
        Database::execute('UPDATE usuarios SET activo = 0 WHERE id = ?', [$this->anaId]);
        try {
            (new AuthService())->login('33333333-3', 'otraClave1');
            $this->fail('Debía lanzar');
        } catch (AuthException $e) {
            $this->assertSame('CREDENCIALES_INVALIDAS', $e->codigo, 'antes respondía USUARIO_INACTIVO con cualquier clave');
        }
    }

    public function testUnRutGiganteDa400YNo500(): void
    {
        try {
            (new AuthService())->login(str_repeat('9', 300), 'x');
            $this->fail('Debía lanzar');
        } catch (AuthException $e) {
            $this->assertSame('RUT_INVALIDO', $e->codigo);
        }
        $this->assertLessThanOrEqual(80, (int) Database::fetchColumn('SELECT MAX(LENGTH(clave)) FROM intentos_login'));
    }

    public function testElRutCeroNoEsValido(): void
    {
        $this->assertFalse(Rut::validar('0-0'));
        $this->assertTrue(Rut::validar('11111111-1'));
    }

    public function testCambiarLaClaveCierraLasOtrasSesiones(): void
    {
        $auth = new AuthService();
        $esta = $auth->login('33333333-3', 'Abc12345')['token'];
        $otra = $auth->login('33333333-3', 'Abc12345')['token'];

        $auth->cambiarContrasena($this->anaId, 'Abc12345', 'NuevaClave1', 'NuevaClave1', $esta);

        $this->assertNotNull($auth->validarSesion($esta));
        $this->assertNull($auth->validarSesion($otra));
    }

    // --- Checklist y auditoría -------------------------------------------------------------

    public function testUnItemHeredadoNoSeReescribeNiPorLaApi(): void
    {
        [$bertaId] = TestDatabase::crearUsuario('44444444-4', 'Berta', 'Trabajador');
        $hoy = date('Y-m-d');
        $h = $this->crearPieza('101');
        $asig = new AsignacionService();
        $chk = new ChecklistService();
        $asig->asignarManual($h, $this->anaId, $hoy);
        $e = $chk->iniciarEjecucion($h, $this->anaId, $hoy);
        $items = array_values(array_filter($chk->itemsDelTemplate($e->templateId), fn($i) => (int) $i['obligatorio'] === 1));
        foreach ($items as $it) {
            $chk->marcarItem($e->id, (int) $it['id'], true, $this->anaId);
        }
        $chk->completar($e->id, $this->anaId);
        (new AuditoriaService())->emitirVeredicto($h, $this->supId, Auditoria::VEREDICTO_RECHAZADO, 'Polvo en el velador', [(int) $items[0]['id']]);
        $asig->reasignar($h, $bertaId, $hoy, 're-limpieza');
        $e2 = $chk->iniciarEjecucion($h, $bertaId, $hoy);

        try {
            $chk->marcarItem($e2->id, (int) $items[1]['id'], false, $bertaId);
            $this->fail('Debía lanzar ITEM_HEREDADO');
        } catch (ChecklistException $e) {
            $this->assertSame('ITEM_HEREDADO', $e->codigo);
        }
        $this->assertSame($this->anaId, (int) Database::fetchColumn(
            'SELECT marcado_por FROM ejecuciones_items WHERE ejecucion_id = ? AND item_id = ?',
            [$e2->id, (int) $items[1]['id']]
        ));
    }

    public function testDesmarcarEnUnaObservacionExigeSuPermisoEnElBackend(): void
    {
        $this->quitarPermiso('Supervisora', 'auditoria.editar_checklist_durante_auditoria');
        $req = new Request(
            metodo: 'POST',
            path: '/api/auditoria/1',
            cuerpo: ['veredicto' => 'aprobado_con_observacion', 'comentario' => 'Faltó el velador', 'items_desmarcados' => [1]],
            ruta: ['id' => '1'],
        );
        $req->usuario = (new UsuarioService())->buscarPorId($this->supId);

        $resp = (new AuditoriaController())->emitirVeredicto($req);
        $this->assertSame(403, $resp->status);
    }

    // --- Copilot ---------------------------------------------------------------------------

    public function testConElCopilotApagadoLaApiNoConversa(): void
    {
        $req = new Request(metodo: 'POST', path: '/api/copilot/mensaje', cuerpo: ['mensaje' => 'hola']);
        $req->usuario = (new UsuarioService())->buscarPorId($this->anaId);

        $this->assertSame(503, (new CopilotController())->mensaje($req)->status);
    }

    public function testElCopilotNoFijaLaPrioridadSinElPermiso(): void
    {
        $usuario = (new UsuarioService())->buscarPorId($this->anaId);
        $r = (new CopilotToolExecutor())->ejecutar('crear_ticket', [
            'hotel_id' => $this->hotelId, 'titulo' => 'Ducha', 'descripcion' => 'Gotea', 'prioridad' => 'urgente',
        ], $usuario);

        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame('normal', $r['resultado']['ticket']['prioridad']);
    }

    // --- Varios ----------------------------------------------------------------------------

    public function testUnaFotoDeDemasiadosMegapixelesSeRechazaAntesDeAbrirla(): void
    {
        if (!function_exists('imagecreatefromjpeg') || !function_exists('imagewebp')) {
            $this->markTestSkipped('Sin GD con WEBP');
        }
        // Un PNG mínimo que dice medir 6000×6000 (36 MP): getimagesize solo lee la cabecera.
        $ihdr = pack('NNCCCCC', 6000, 6000, 8, 2, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
        $tmp = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($tmp, $png);

        try {
            (new ImagenAdjuntoService())->guardarComoWebp($tmp, strlen($png));
            $this->fail('Debía lanzar IMAGEN_MUY_GRANDE');
        } catch (ImagenException $e) {
            $this->assertSame('IMAGEN_MUY_GRANDE', $e->codigo);
        } finally {
            @unlink($tmp);
        }
    }

    public function testUsuariosActivosNoCuentaSesionesVencidas(): void
    {
        // Venció hace una hora (UTC): antes la comparación con la hora local con espacio la contaba.
        Database::execute(
            'INSERT INTO sesiones (token, usuario_id, expires_at) VALUES (?, ?, ?)',
            ['vencida', $this->anaId, gmdate('Y-m-d\TH:i:s.000\Z', time() - 3600)]
        );
        $this->assertSame(0, (new HomeService())->sistemaUsuariosActivos($this->adminId)['ahora']);
    }

    public function testLosUmbralesDeAlertasDebenSerEnterosValidos(): void
    {
        foreach (['-15', '0', 'abc', '2.5'] as $valor) {
            $req = new Request(metodo: 'PUT', path: '/api/alertas/config', cuerpo: ['config' => ['tiempo_fallback_nueva_habitacion' => $valor]]);
            $req->usuario = (new UsuarioService())->buscarPorId($this->adminId);
            $this->assertSame(400, (new AlertasController())->actualizarConfig($req)->status, $valor);
        }
    }

    // --- Helpers ---------------------------------------------------------------------------

    private function crearPieza(string $numero): int
    {
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, ?, ?, 'sucia')",
            [$this->hotelId, $numero, $this->tipoId]
        );
        return Database::lastInsertId();
    }

    private function quitarPermiso(string $rol, string $permiso): void
    {
        Database::execute(
            'DELETE FROM rol_permisos WHERE permiso_codigo = ? AND rol_id = (SELECT id FROM roles WHERE nombre = ?)',
            [$permiso, $rol]
        );
    }

    private function login(string $rut): string
    {
        return (new AuthService())->login($rut, $rut === '11111111-1' ? $this->adminPwd : 'Abc12345')['token'];
    }

    private function pedir(string $metodo, string $path, string $token): Response
    {
        return Kernel::construirRouter()->despachar(new Request(
            metodo: $metodo, path: $path, cookies: [AuthService::SESSION_COOKIE => $token]
        ));
    }
}
