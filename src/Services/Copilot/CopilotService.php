<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services\Copilot;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\Usuario;

/**
 * Orquesta conversaciones con el copilot IA:
 * - Gestión de conversaciones (crear, continuar, listar, borrar)
 * - Construye system prompt por rol
 * - Loop de tool use (máx 5 iteraciones)
 * - Persistencia de mensajes con tracking de tokens
 */
final class CopilotService
{
    private const MAX_TOOL_ITERATIONS = 5;
    private const CONVERSATION_TIMEOUT_HOURS = 1;

    public function __construct(
        private readonly CopilotClient $client = new CopilotClient(),
        private readonly CopilotToolExecutor $executor = new CopilotToolExecutor(),
    ) {
    }

    /**
     * Envía un mensaje del usuario y retorna la respuesta del copilot.
     *
     * @return array{conversacion_id:int, respuesta:string, error:?string}
     */
    public function enviarMensaje(string $texto, Usuario $usuario, ?int $conversacionId = null): array
    {
        $conversacionId = $this->resolverConversacion($conversacionId, $usuario, $texto);

        // Guardar mensaje del usuario
        $this->guardarMensaje($conversacionId, 'user', $texto);

        // Construir historial para la API
        $mensajes = $this->cargarHistorial($conversacionId);
        $system = $this->construirSystemPrompt($usuario);
        $tools = CopilotToolRegistry::toolsParaUsuario($usuario);

        // Loop de tool use
        $tokensInTotal = 0;
        $tokensOutTotal = 0;
        $respuestaTexto = '';

        for ($i = 0; $i < self::MAX_TOOL_ITERATIONS; $i++) {
            $resultado = $this->client->enviarMensaje($system, $mensajes, $tools);
            $tokensInTotal += $resultado['tokens_input'];
            $tokensOutTotal += $resultado['tokens_output'];

            if (!$resultado['ok']) {
                $errorMsg = 'Lo siento, hubo un problema al procesar tu mensaje. Intenta de nuevo en un momento.';
                $this->guardarMensaje($conversacionId, 'assistant', $errorMsg, tokensIn: $tokensInTotal, tokensOut: $tokensOutTotal);
                return ['conversacion_id' => $conversacionId, 'respuesta' => $errorMsg, 'error' => $resultado['error']];
            }

            $data = $resultado['respuesta'];
            $stopReason = (string) ($data['stop_reason'] ?? '');
            $content = $data['content'] ?? [];

            // Procesar bloques de contenido
            $textosParciales = [];
            $toolUses = [];

            foreach ($content as $bloque) {
                if (($bloque['type'] ?? '') === 'text') {
                    $textosParciales[] = (string) ($bloque['text'] ?? '');
                } elseif (($bloque['type'] ?? '') === 'tool_use') {
                    $toolUses[] = $bloque;
                }
            }

            if ($textosParciales !== []) {
                $respuestaTexto = implode("\n", $textosParciales);
            }

            // Si no hay tool_use, terminamos
            if ($toolUses === [] || $stopReason !== 'tool_use') {
                break;
            }

            // Agregar respuesta del assistant al historial
            $mensajes[] = ['role' => 'assistant', 'content' => $content];

            // Ejecutar cada tool y agregar resultados
            $toolResults = [];
            foreach ($toolUses as $toolUse) {
                $toolName = (string) ($toolUse['id'] ?? '');
                $toolFnName = (string) ($toolUse['name'] ?? '');
                $toolInput = is_array($toolUse['input'] ?? null) ? $toolUse['input'] : [];

                $execResult = $this->executor->ejecutar($toolFnName, $toolInput, $usuario);

                $resultContent = $execResult['ok']
                    ? json_encode($execResult['resultado'], JSON_UNESCAPED_UNICODE)
                    : json_encode(['error' => $execResult['error']], JSON_UNESCAPED_UNICODE);

                $toolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $toolName,
                    'content' => $resultContent,
                ];

                // Persistir el tool use y result
                $this->guardarMensaje($conversacionId, 'assistant', '', toolName: $toolFnName, toolPayload: $toolInput);
                $this->guardarMensaje($conversacionId, 'tool', $resultContent ?: '', toolName: $toolFnName);
            }

            $mensajes[] = ['role' => 'user', 'content' => $toolResults];
        }

        // Guardar respuesta final del assistant
        if ($respuestaTexto !== '') {
            $this->guardarMensaje($conversacionId, 'assistant', $respuestaTexto, tokensIn: $tokensInTotal, tokensOut: $tokensOutTotal);
        }

        // Actualizar updated_at de la conversación
        Database::execute(
            "UPDATE copilot_conversaciones SET updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
            [$conversacionId]
        );

        return ['conversacion_id' => $conversacionId, 'respuesta' => $respuestaTexto, 'error' => null];
    }

    /**
     * Resuelve o crea la conversación. Si no se pasa ID, busca una reciente (<1h)
     * o crea una nueva.
     */
    private function resolverConversacion(?int $conversacionId, Usuario $usuario, string $primerMensaje): int
    {
        if ($conversacionId !== null) {
            $existe = Database::fetchOne(
                'SELECT id FROM copilot_conversaciones WHERE id = ? AND usuario_id = ?',
                [$conversacionId, $usuario->id]
            );
            if ($existe !== null) {
                return $conversacionId;
            }
        }

        // Buscar conversación reciente
        $reciente = Database::fetchOne(
            "SELECT id FROM copilot_conversaciones
              WHERE usuario_id = ?
                AND updated_at > strftime('%Y-%m-%dT%H:%M:%fZ', 'now', '-' || ? || ' hours')
              ORDER BY updated_at DESC LIMIT 1",
            [$usuario->id, self::CONVERSATION_TIMEOUT_HOURS]
        );
        if ($reciente !== null) {
            return (int) $reciente['id'];
        }

        // Crear nueva
        $titulo = mb_substr(trim($primerMensaje), 0, 60);
        Database::execute(
            'INSERT INTO copilot_conversaciones (usuario_id, titulo) VALUES (?, ?)',
            [$usuario->id, $titulo]
        );
        return Database::lastInsertId();
    }

    private function guardarMensaje(
        int $conversacionId,
        string $rol,
        string $contenido,
        ?string $toolName = null,
        ?array $toolPayload = null,
        int $tokensIn = 0,
        int $tokensOut = 0,
    ): void {
        $payloadJson = $toolPayload !== null ? json_encode($toolPayload, JSON_UNESCAPED_UNICODE) : null;
        Database::execute(
            'INSERT INTO copilot_mensajes (conversacion_id, rol, contenido, tool_name, tool_payload_json, tokens_input, tokens_output) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$conversacionId, $rol, $contenido, $toolName, $payloadJson, $tokensIn > 0 ? $tokensIn : null, $tokensOut > 0 ? $tokensOut : null]
        );
    }

    /**
     * Carga el historial de mensajes en formato compatible con la Messages API.
     * Excluye mensajes internos de tool (que ya se reconstruyen en el loop).
     *
     * @return list<array{role:string,content:string}>
     */
    private function cargarHistorial(int $conversacionId): array
    {
        $filas = Database::fetchAll(
            "SELECT rol, contenido FROM copilot_mensajes
              WHERE conversacion_id = ? AND tool_name IS NULL AND contenido <> ''
              ORDER BY id",
            [$conversacionId]
        );

        $mensajes = [];
        foreach ($filas as $f) {
            $role = (string) $f['rol'];
            if ($role === 'tool') {
                continue;
            }
            $mensajes[] = ['role' => $role, 'content' => (string) $f['contenido']];
        }
        return $mensajes;
    }

    private function construirSystemPrompt(Usuario $usuario): string
    {
        $base = 'Eres un asistente para el equipo de limpieza hotelera de Atankalama (2 hoteles en Calama, Chile). '
            . 'Respondes en español chileno, de forma amable y breve. '
            . 'Tienes acceso a herramientas para consultar y (si corresponde) modificar el sistema. '
            . 'Usa las herramientas disponibles siempre que la pregunta requiera datos reales. Nunca inventes datos.';

        $rolPrompt = $this->promptPorRol($usuario);
        return $base . "\n\n" . $rolPrompt;
    }

    /**
     * Personaliza el system prompt según permisos representativos del usuario.
     *
     * Sigue la regla de oro RBAC del proyecto: NUNCA bifurcar por nombre de rol
     * (los roles son editables desde la UI). Se usan permisos representativos del
     * nivel de capacidad esperado para cada perfil:
     *
     * - `permisos.asignar_a_rol`: solo lo poseen perfiles con control total del
     *   sistema (típicamente Admin). Es el indicador más fuerte de capacidad
     *   administrativa porque permite reescribir la matriz RBAC.
     * - `asignaciones.asignar_manual`: representativo del perfil supervisora,
     *   que reasigna habitaciones y gestiona la carga del equipo.
     * - `auditoria.ver_bandeja`: representativo del perfil recepción, cuyo foco
     *   es auditar habitaciones limpias.
     * - Caso else: trabajador de limpieza (sin permisos de gestión).
     *
     * El orden de los chequeos va de más específico/poderoso a más general, de
     * modo que un usuario con múltiples roles (ej. supervisora que también
     * puede auditar) reciba el prompt del nivel más alto que le aplica.
     */
    private function promptPorRol(Usuario $usuario): string
    {
        if ($usuario->tienePermiso('permisos.asignar_a_rol')) {
            return 'El usuario es administrador. Tiene acceso total — KPIs, salud del sistema, gestión de usuarios.';
        }
        if ($usuario->tienePermiso('asignaciones.asignar_manual')) {
            return 'El usuario es supervisora. Puede reasignar, ver carga de equipo, atender alertas.';
        }
        if ($usuario->tienePermiso('auditoria.ver_bandeja')) {
            return 'El usuario es recepcionista. Se enfoca en auditoría de habitaciones.';
        }
        return 'El usuario es un trabajador de limpieza. Ayúdalo a consultar sus habitaciones, reportar tickets, marcarse disponible.';
    }

    // --- Endpoints de gestión de conversaciones ---

    /** @return list<array<string,mixed>> */
    public function listarConversaciones(int $usuarioId): array
    {
        return Database::fetchAll(
            'SELECT id, titulo, created_at, updated_at FROM copilot_conversaciones WHERE usuario_id = ? ORDER BY updated_at DESC',
            [$usuarioId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function listarTodasConversaciones(): array
    {
        return Database::fetchAll(
            'SELECT c.id, c.usuario_id, u.nombre AS usuario_nombre, c.titulo, c.created_at, c.updated_at
               FROM copilot_conversaciones c
               JOIN usuarios u ON u.id = c.usuario_id
              ORDER BY c.updated_at DESC'
        );
    }

    /**
     * @return array{conversacion:array<string,mixed>, mensajes:list<array<string,mixed>>}|null
     */
    public function obtenerConversacion(int $id, int $usuarioId, bool $esAdmin = false): ?array
    {
        $conv = Database::fetchOne('SELECT * FROM copilot_conversaciones WHERE id = ?', [$id]);
        if ($conv === null) {
            return null;
        }
        if (!$esAdmin && (int) $conv['usuario_id'] !== $usuarioId) {
            return null;
        }
        $mensajes = Database::fetchAll(
            'SELECT id, rol, contenido, tool_name, tool_payload_json, tokens_input, tokens_output, created_at
               FROM copilot_mensajes WHERE conversacion_id = ? ORDER BY id',
            [$id]
        );
        return ['conversacion' => $conv, 'mensajes' => $mensajes];
    }

    public function borrarConversacion(int $id, int $usuarioId): bool
    {
        $conv = Database::fetchOne('SELECT usuario_id FROM copilot_conversaciones WHERE id = ?', [$id]);
        if ($conv === null || (int) $conv['usuario_id'] !== $usuarioId) {
            return false;
        }
        Database::execute('DELETE FROM copilot_conversaciones WHERE id = ?', [$id]);
        return true;
    }
}
