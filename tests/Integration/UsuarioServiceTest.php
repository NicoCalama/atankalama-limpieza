<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\PasswordService;
use Atankalama\Limpieza\Services\UsuarioException;
use Atankalama\Limpieza\Services\UsuarioService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class UsuarioServiceTest extends TestCase
{
    private UsuarioService $svc;
    private PasswordService $pwd;
    private int $adminId;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        [$this->adminId] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');
        $this->svc = new UsuarioService();
        $this->pwd = new PasswordService();
    }

    public function testCrearUsuarioGeneraPasswordTemporal(): void
    {
        $rolId = (int) Database::fetchOne("SELECT id FROM roles WHERE nombre='Trabajador'")['id'];
        $r = $this->svc->crear(
            ['rut' => '22222222-2', 'nombre' => 'Juan', 'email' => 'juan@ex.com', 'hotel_default' => '1_sur', 'roles' => [$rolId]],
            $this->adminId,
            $this->pwd
        );
        $this->assertSame('Juan', $r['usuario']->nombre);
        $this->assertNotSame('', $r['password_temporal']);
        $this->assertContains('Trabajador', $r['usuario']->roles);
    }

    public function testRutDuplicadoLanza(): void
    {
        $this->svc->crear(['rut' => '22222222-2', 'nombre' => 'Juan'], $this->adminId, $this->pwd);
        try {
            $this->svc->crear(['rut' => '22222222-2', 'nombre' => 'Otro'], $this->adminId, $this->pwd);
            $this->fail('Debía lanzar');
        } catch (UsuarioException $e) {
            $this->assertSame('RUT_DUPLICADO', $e->codigo);
        }
    }

    public function testRutInvalidoLanza(): void
    {
        try {
            $this->svc->crear(['rut' => '12345678-0', 'nombre' => 'X'], $this->adminId, $this->pwd);
            $this->fail('Debía lanzar');
        } catch (UsuarioException $e) {
            $this->assertSame('RUT_INVALIDO', $e->codigo);
        }
    }

    public function testNombreVacioLanza(): void
    {
        try {
            $this->svc->crear(['rut' => '22222222-2', 'nombre' => '  '], $this->adminId, $this->pwd);
            $this->fail('Debía lanzar');
        } catch (UsuarioException $e) {
            $this->assertSame('NOMBRE_INVALIDO', $e->codigo);
        }
    }

    public function testEmailInvalidoLanza(): void
    {
        try {
            $this->svc->crear(['rut' => '22222222-2', 'nombre' => 'Juan', 'email' => 'no-es-email'], $this->adminId, $this->pwd);
            $this->fail('Debía lanzar');
        } catch (UsuarioException $e) {
            $this->assertSame('EMAIL_INVALIDO', $e->codigo);
        }
    }

    public function testActualizarCambiaNombreYTema(): void
    {
        $r = $this->svc->crear(['rut' => '22222222-2', 'nombre' => 'Juan'], $this->adminId, $this->pwd);
        $u = $this->svc->actualizar($r['usuario']->id, ['nombre' => 'Juan Perez', 'tema_preferido' => 'oscuro'], $this->adminId);
        $this->assertSame('Juan Perez', $u->nombre);
        $this->assertSame('oscuro', $u->temaPreferido);
    }

    public function testDesactivarEliminaSesiones(): void
    {
        $r = $this->svc->crear(['rut' => '22222222-2', 'nombre' => 'Juan'], $this->adminId, $this->pwd);
        Database::execute(
            "INSERT INTO sesiones (usuario_id, token, expires_at) VALUES (?, 'tok', datetime('now', '+1 day'))",
            [$r['usuario']->id]
        );
        $u = $this->svc->activar($r['usuario']->id, false, $this->adminId);
        $this->assertFalse($u->activo);
        $count = (int) Database::fetchOne('SELECT COUNT(*) AS n FROM sesiones WHERE usuario_id = ?', [$r['usuario']->id])['n'];
        $this->assertSame(0, $count);
    }

    public function testEliminarAnonimizaYBorraDatosPersonales(): void
    {
        $r = $this->svc->crear(
            ['rut' => '22222222-2', 'nombre' => 'Juan', 'email' => 'juan@ex.com'],
            $this->adminId,
            $this->pwd
        );
        $usuarioId = $r['usuario']->id;

        // Datos colaterales que deben desaparecer
        Database::execute(
            "INSERT INTO sesiones (usuario_id, token, expires_at) VALUES (?, 'tok-eliminar', datetime('now', '+1 day'))",
            [$usuarioId]
        );
        Database::execute(
            "INSERT INTO push_subscriptions (usuario_id, endpoint, p256dh, auth) VALUES (?, 'https://push.example/abc', 'k', 'a')",
            [$usuarioId]
        );
        Database::execute(
            "INSERT INTO intentos_login (clave) VALUES (?)",
            ['22222222-2|127.0.0.1']
        );
        Database::execute(
            "INSERT INTO copilot_conversaciones (usuario_id, titulo) VALUES (?, 'Test')",
            [$usuarioId]
        );
        $convId = Database::lastInsertId();
        Database::execute(
            "INSERT INTO copilot_mensajes (conversacion_id, rol, contenido) VALUES (?, 'user', 'hola')",
            [$convId]
        );

        $this->svc->eliminar($usuarioId, $this->adminId);

        // Fila sigue existiendo (FK de audit_log/ejecuciones se preservan)
        $u = $this->svc->buscarPorId($usuarioId);
        $this->assertNotNull($u);
        $this->assertSame("Usuario eliminado #{$usuarioId}", $u->nombre);
        $this->assertStringStartsWith("eliminado-{$usuarioId}-", $u->rut);
        $this->assertNull($u->email);
        $this->assertFalse($u->activo);
        $this->assertFalse($u->requiereCambioPwd);
        $this->assertSame([], $u->roles);

        // Datos personales colaterales borrados
        $this->assertSame(
            0,
            (int) Database::fetchOne('SELECT COUNT(*) AS n FROM sesiones WHERE usuario_id = ?', [$usuarioId])['n']
        );
        $this->assertSame(
            0,
            (int) Database::fetchOne('SELECT COUNT(*) AS n FROM push_subscriptions WHERE usuario_id = ?', [$usuarioId])['n']
        );
        $this->assertSame(
            0,
            (int) Database::fetchOne("SELECT COUNT(*) AS n FROM intentos_login WHERE clave LIKE '22222222-2|%'")['n']
        );
        $this->assertSame(
            0,
            (int) Database::fetchOne('SELECT COUNT(*) AS n FROM copilot_conversaciones WHERE usuario_id = ?', [$usuarioId])['n']
        );
        $this->assertSame(
            0,
            (int) Database::fetchOne('SELECT COUNT(*) AS n FROM copilot_mensajes WHERE conversacion_id = ?', [$convId])['n']
        );
    }

    public function testEliminarNoPermiteAutoEliminacion(): void
    {
        try {
            $this->svc->eliminar($this->adminId, $this->adminId);
            $this->fail('Debía lanzar');
        } catch (UsuarioException $e) {
            $this->assertSame('AUTO_ELIMINACION_NO_PERMITIDA', $e->codigo);
            $this->assertSame(400, $e->httpStatus);
        }
    }

    public function testEliminarUsuarioInexistenteLanza404(): void
    {
        try {
            $this->svc->eliminar(999999, $this->adminId);
            $this->fail('Debía lanzar');
        } catch (UsuarioException $e) {
            $this->assertSame('USUARIO_NO_ENCONTRADO', $e->codigo);
            $this->assertSame(404, $e->httpStatus);
        }
    }

    public function testEliminarRegistraAuditLog(): void
    {
        $r = $this->svc->crear(['rut' => '22222222-2', 'nombre' => 'Juan'], $this->adminId, $this->pwd);
        $usuarioId = $r['usuario']->id;

        $this->svc->eliminar($usuarioId, $this->adminId, 'pidio_baja_definitiva');

        $log = Database::fetchOne(
            "SELECT accion, entidad, entidad_id, detalles_json FROM audit_log
              WHERE accion = 'usuario.eliminar' AND entidad_id = ?
           ORDER BY id DESC LIMIT 1",
            [$usuarioId]
        );
        $this->assertNotNull($log);
        $this->assertSame('usuario', $log['entidad']);
        $this->assertSame($usuarioId, (int) $log['entidad_id']);
        $detalles = json_decode((string) $log['detalles_json'], true);
        $this->assertSame('pidio_baja_definitiva', $detalles['motivo']);
    }

    public function testExportarDatosPersonalesContieneSecciones(): void
    {
        $r = $this->svc->crear(
            ['rut' => '22222222-2', 'nombre' => 'Juan', 'email' => 'juan@ex.com', 'hotel_default' => '1_sur'],
            $this->adminId,
            $this->pwd
        );
        $usuarioId = $r['usuario']->id;

        // Datos colaterales para verificar que aparecen en el export
        // (expires_at en formato ISO 8601 con T y Z para que el filtro del service lo encuentre vivo)
        Database::execute(
            "INSERT INTO sesiones (usuario_id, token, ip, user_agent, expires_at)
             VALUES (?, 'tok-export', '127.0.0.1', 'Mozilla/5.0', strftime('%Y-%m-%dT%H:%M:%fZ', 'now', '+1 day'))",
            [$usuarioId]
        );
        Database::execute(
            "INSERT INTO push_subscriptions (usuario_id, endpoint, p256dh, auth)
             VALUES (?, 'https://push.example/endpoint-largo-de-prueba', 'k', 'a')",
            [$usuarioId]
        );
        Database::execute(
            "INSERT INTO copilot_conversaciones (usuario_id, titulo) VALUES (?, 'Conversa de prueba')",
            [$usuarioId]
        );
        Database::execute(
            "INSERT INTO notificaciones (usuario_id, tipo, titulo, cuerpo) VALUES (?, 'general', 'T', 'C')",
            [$usuarioId]
        );

        $export = $this->svc->exportarDatosPersonales($usuarioId, ocultaTimestampsKpi: false);

        $this->assertArrayHasKey('usuario', $export);
        $this->assertArrayHasKey('roles', $export);
        $this->assertArrayHasKey('sesiones', $export);
        $this->assertArrayHasKey('asignaciones', $export);
        $this->assertArrayHasKey('ejecuciones', $export);
        $this->assertArrayHasKey('tickets', $export);
        $this->assertArrayHasKey('notificaciones', $export);
        $this->assertArrayHasKey('copilot', $export);
        $this->assertArrayHasKey('push', $export);

        $this->assertSame($usuarioId, $export['usuario']['id']);
        $this->assertSame('Juan', $export['usuario']['nombre']);
        $this->assertSame('juan@ex.com', $export['usuario']['email']);
        $this->assertArrayNotHasKey('password_hash', $export['usuario']);

        $this->assertCount(1, $export['sesiones']);
        $this->assertArrayNotHasKey('token', $export['sesiones'][0]);
        $this->assertSame('127.0.0.1', $export['sesiones'][0]['ip']);

        $this->assertCount(1, $export['push']);
        $this->assertArrayNotHasKey('p256dh', $export['push'][0]);
        $this->assertArrayNotHasKey('auth', $export['push'][0]);

        $this->assertCount(1, $export['copilot']);
        $this->assertSame('Conversa de prueba', $export['copilot'][0]['titulo']);

        $this->assertCount(1, $export['notificaciones']);
    }

    public function testExportarDatosOcultaKpisAlPropioUsuario(): void
    {
        // Crear usuario, hotel/tipo/habitación, asignación y ejecución de checklist
        $r = $this->svc->crear(['rut' => '22222222-2', 'nombre' => 'Juan'], $this->adminId, $this->pwd);
        $usuarioId = $r['usuario']->id;

        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', 'Atankalama 1 Sur')");
        $hotelId = Database::lastInsertId();
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('doble')");
        $tipoId = Database::lastInsertId();
        Database::execute(
            'INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id) VALUES (?, ?, ?)',
            [$hotelId, '101', $tipoId]
        );
        $habId = Database::lastInsertId();
        Database::execute(
            "INSERT INTO checklists_template (tipo_habitacion_id, nombre) VALUES (?, 'Checklist doble')",
            [$tipoId]
        );
        $tplId = Database::lastInsertId();
        Database::execute(
            "INSERT INTO asignaciones (habitacion_id, usuario_id, fecha) VALUES (?, ?, '2026-04-26')",
            [$habId, $usuarioId]
        );
        $asigId = Database::lastInsertId();
        Database::execute(
            "INSERT INTO ejecuciones_checklist
                (habitacion_id, asignacion_id, usuario_id, template_id, estado, timestamp_inicio, timestamp_fin)
             VALUES (?, ?, ?, ?, 'completada', '2026-04-26T10:00:00.000Z', '2026-04-26T10:30:00.000Z')",
            [$habId, $asigId, $usuarioId, $tplId]
        );

        // Caso 1: el propio trabajador exporta sus datos → SIN timestamps de KPI
        $exportPropio = $this->svc->exportarDatosPersonales($usuarioId, ocultaTimestampsKpi: true);
        $this->assertCount(1, $exportPropio['ejecuciones']);
        $this->assertArrayNotHasKey('timestamp_inicio', $exportPropio['ejecuciones'][0]);
        $this->assertArrayNotHasKey('timestamp_fin', $exportPropio['ejecuciones'][0]);

        // Caso 2: un admin exporta → SÍ ve los timestamps
        $exportAdmin = $this->svc->exportarDatosPersonales($usuarioId, ocultaTimestampsKpi: false);
        $this->assertCount(1, $exportAdmin['ejecuciones']);
        $this->assertArrayHasKey('timestamp_inicio', $exportAdmin['ejecuciones'][0]);
        $this->assertArrayHasKey('timestamp_fin', $exportAdmin['ejecuciones'][0]);
        $this->assertSame('2026-04-26T10:00:00.000Z', $exportAdmin['ejecuciones'][0]['timestamp_inicio']);
    }

    public function testExportarDatosUsuarioInexistenteLanzaError(): void
    {
        try {
            $this->svc->exportarDatosPersonales(999999, ocultaTimestampsKpi: false);
            $this->fail('Debía lanzar');
        } catch (UsuarioException $e) {
            $this->assertSame('USUARIO_NO_ENCONTRADO', $e->codigo);
            $this->assertSame(404, $e->httpStatus);
        }
    }

    public function testListarFiltraPorBusquedaYRol(): void
    {
        $rolTrab = (int) Database::fetchOne("SELECT id FROM roles WHERE nombre='Trabajador'")['id'];
        $this->svc->crear(['rut' => '22222222-2', 'nombre' => 'Juan', 'roles' => [$rolTrab]], $this->adminId, $this->pwd);
        $this->svc->crear(['rut' => '33333333-3', 'nombre' => 'Maria', 'roles' => [$rolTrab]], $this->adminId, $this->pwd);

        $busqueda = $this->svc->listar(['busqueda' => 'Juan']);
        $this->assertCount(1, $busqueda);
        $this->assertSame('Juan', $busqueda[0]['nombre']);

        $trabajadores = $this->svc->listar(['rol' => 'Trabajador']);
        $nombres = array_column($trabajadores, 'nombre');
        $this->assertContains('Juan', $nombres);
        $this->assertContains('Maria', $nombres);
        $this->assertNotContains('Admin', $nombres);
    }
}
