<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Usuario;
use Atankalama\Limpieza\Services\Copilot\CopilotClient;
use Atankalama\Limpieza\Services\Copilot\CopilotService;
use Atankalama\Limpieza\Services\Copilot\CopilotToolRegistry;
use Atankalama\Limpieza\Services\Http\HttpResponse;
use Atankalama\Limpieza\Services\Http\HttpTransport;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class CopilotServiceTest extends TestCase
{
    private int $adminId;
    private int $trabajadorId;
    private Usuario $admin;
    private Usuario $trabajador;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        [$this->adminId] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');
        [$this->trabajadorId] = TestDatabase::crearUsuario('22222222-2', 'Juan', 'Trabajador');

        $svcUsuario = new \Atankalama\Limpieza\Services\UsuarioService();
        $this->admin = $svcUsuario->buscarPorId($this->adminId);
        $this->trabajador = $svcUsuario->buscarPorId($this->trabajadorId);
    }

    // --- CopilotToolRegistry ---

    public function testToolsAdminTieneTodasLasTools(): void
    {
        $tools = CopilotToolRegistry::toolsParaUsuario($this->admin);
        $nombres = array_column($tools, 'name');
        $this->assertContains('listar_habitaciones_hotel', $nombres);
        $this->assertContains('asignar_habitacion', $nombres);
        $this->assertContains('crear_ticket', $nombres);
    }

    public function testToolsTrabajadorNoTieneAcciones(): void
    {
        $tools = CopilotToolRegistry::toolsParaUsuario($this->trabajador);
        $nombres = array_column($tools, 'name');
        // Trabajador no tiene permiso copilot.usar_nivel_2_acciones por defecto
        $this->assertNotContains('asignar_habitacion', $nombres);
        // Pero sí tiene consultas propias
        $this->assertContains('listar_mis_habitaciones', $nombres);
    }

    public function testToolsFiltradasPorPermisos(): void
    {
        $tools = CopilotToolRegistry::toolsParaUsuario($this->trabajador);
        $nombres = array_column($tools, 'name');
        // Trabajador no tiene alertas.recibir_predictivas
        $this->assertNotContains('listar_alertas_activas', $nombres);
    }

    // --- CopilotService: conversaciones ---

    public function testEnviarMensajeCreaConversacion(): void
    {
        $transport = $this->crearTransportMock($this->respuestaTextoSimple('Hola, ¿en qué te ayudo?'));
        $client = new CopilotClient(transport: $transport, apiKey: 'sk-test', dormir: fn() => null);
        $svc = new CopilotService(client: $client);

        $resultado = $svc->enviarMensaje('Hola', $this->admin);

        $this->assertGreaterThan(0, $resultado['conversacion_id']);
        $this->assertSame('Hola, ¿en qué te ayudo?', $resultado['respuesta']);
        $this->assertNull($resultado['error']);

        // Verificar que se persistió
        $conv = Database::fetchOne('SELECT * FROM copilot_conversaciones WHERE id = ?', [$resultado['conversacion_id']]);
        $this->assertNotNull($conv);
        $this->assertSame('Hola', $conv['titulo']);

        $msgs = Database::fetchAll('SELECT * FROM copilot_mensajes WHERE conversacion_id = ?', [$resultado['conversacion_id']]);
        $this->assertGreaterThanOrEqual(2, count($msgs)); // user + assistant
    }

    public function testContinuarConversacionExistente(): void
    {
        $transport = $this->crearTransportMock($this->respuestaTextoSimple('Respuesta'));
        $client = new CopilotClient(transport: $transport, apiKey: 'sk-test', dormir: fn() => null);
        $svc = new CopilotService(client: $client);

        $r1 = $svc->enviarMensaje('Primer mensaje', $this->admin);
        $r2 = $svc->enviarMensaje('Segundo mensaje', $this->admin, $r1['conversacion_id']);

        $this->assertSame($r1['conversacion_id'], $r2['conversacion_id']);
    }

    public function testListarConversaciones(): void
    {
        $transport = $this->crearTransportMock($this->respuestaTextoSimple('Ok'));
        $client = new CopilotClient(transport: $transport, apiKey: 'sk-test', dormir: fn() => null);
        $svc = new CopilotService(client: $client);

        $svc->enviarMensaje('Hola', $this->admin);
        $convs = $svc->listarConversaciones($this->adminId);
        $this->assertCount(1, $convs);
    }

    public function testBorrarConversacionPropiaOk(): void
    {
        $transport = $this->crearTransportMock($this->respuestaTextoSimple('Ok'));
        $client = new CopilotClient(transport: $transport, apiKey: 'sk-test', dormir: fn() => null);
        $svc = new CopilotService(client: $client);

        $r = $svc->enviarMensaje('Hola', $this->admin);
        $ok = $svc->borrarConversacion($r['conversacion_id'], $this->adminId);
        $this->assertTrue($ok);

        $convs = $svc->listarConversaciones($this->adminId);
        $this->assertCount(0, $convs);
    }

    public function testBorrarConversacionAjenaFalla(): void
    {
        $transport = $this->crearTransportMock($this->respuestaTextoSimple('Ok'));
        $client = new CopilotClient(transport: $transport, apiKey: 'sk-test', dormir: fn() => null);
        $svc = new CopilotService(client: $client);

        $r = $svc->enviarMensaje('Hola', $this->admin);
        $ok = $svc->borrarConversacion($r['conversacion_id'], $this->trabajadorId);
        $this->assertFalse($ok);
    }

    public function testApiKeyVaciaRetornaError(): void
    {
        $client = new CopilotClient(apiKey: '', dormir: fn() => null);
        $svc = new CopilotService(client: $client);

        $r = $svc->enviarMensaje('Hola', $this->admin);
        $this->assertNotNull($r['error']);
        $this->assertStringContainsString('no configurada', $r['error']);
    }

    // --- Helpers ---

    private function crearTransportMock(string $responseBody): HttpTransport
    {
        return new class($responseBody) implements HttpTransport {
            public function __construct(private readonly string $body)
            {
            }

            public function request(string $metodo, string $url, array $headers = [], ?array $cuerpoJson = null, int $timeoutSegundos = 10): HttpResponse
            {
                return new HttpResponse(200, $this->body);
            }
        };
    }

    private function respuestaTextoSimple(string $texto): string
    {
        return json_encode([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => $texto]],
            'model' => 'claude-sonnet-4-6',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ], JSON_UNESCAPED_UNICODE);
    }
}
