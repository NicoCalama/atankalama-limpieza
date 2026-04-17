<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services\Copilot;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Services\Http\CurlTransport;
use Atankalama\Limpieza\Services\Http\HttpResponse;
use Atankalama\Limpieza\Services\Http\HttpTransport;

/**
 * Cliente para la Messages API de Anthropic con reintentos (1s/2s/4s).
 */
final class CopilotClient
{
    private const BACKOFFS = [1, 2, 4];

    private readonly string $apiKey;
    private readonly string $modelo;
    private readonly int $maxTokens;
    private readonly int $timeout;

    /** @var callable */
    private $dormir;

    public function __construct(
        private readonly HttpTransport $transport = new CurlTransport(),
        ?string $apiKey = null,
        ?string $modelo = null,
        ?int $maxTokens = null,
        ?int $timeout = null,
        ?callable $dormir = null,
    ) {
        $this->apiKey = $apiKey ?? (string) Config::get('CLAUDE_API_KEY', '');
        $this->modelo = $modelo ?? (string) Config::get('CLAUDE_MODEL', 'claude-sonnet-4-6');
        $this->maxTokens = $maxTokens ?? Config::getInt('CLAUDE_MAX_TOKENS', 2048);
        $this->timeout = $timeout ?? 30;
        $this->dormir = $dormir ?? static function (int $s): void {
            if ($s > 0) {
                sleep($s);
            }
        };
    }

    /**
     * Envía un request a la Messages API.
     *
     * @param string $system  System prompt.
     * @param list<array{role:string,content:mixed}> $messages  Historial de mensajes.
     * @param list<array<string,mixed>> $tools  Definición de tools (puede estar vacía).
     * @return array{ok:bool, respuesta:?array, error:?string, tokens_input:int, tokens_output:int}
     */
    public function enviarMensaje(string $system, array $messages, array $tools = []): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'respuesta' => null, 'error' => 'CLAUDE_API_KEY no configurada.', 'tokens_input' => 0, 'tokens_output' => 0];
        }

        $body = [
            'model' => $this->modelo,
            'max_tokens' => $this->maxTokens,
            'system' => $system,
            'messages' => $messages,
        ];
        if ($tools !== []) {
            $body['tools'] = $tools;
        }

        $headers = [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ];

        $resp = $this->conReintentos($headers, $body);

        if (!$resp->esExito()) {
            $errorMsg = $resp->errorRed ?? "HTTP {$resp->status}";
            Logger::error('copilot', "Error Claude API: {$errorMsg}", ['status' => $resp->status]);
            return ['ok' => false, 'respuesta' => null, 'error' => $errorMsg, 'tokens_input' => 0, 'tokens_output' => 0];
        }

        $data = $resp->json();
        $tokensIn = (int) ($data['usage']['input_tokens'] ?? 0);
        $tokensOut = (int) ($data['usage']['output_tokens'] ?? 0);

        return ['ok' => true, 'respuesta' => $data, 'error' => null, 'tokens_input' => $tokensIn, 'tokens_output' => $tokensOut];
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $body
     */
    private function conReintentos(array $headers, array $body): HttpResponse
    {
        $ultimaResp = null;
        foreach ([0, ...self::BACKOFFS] as $espera) {
            ($this->dormir)($espera);
            $ultimaResp = $this->transport->request(
                'POST',
                'https://api.anthropic.com/v1/messages',
                $headers,
                $body,
                $this->timeout,
            );
            if ($ultimaResp->esExito() || !$ultimaResp->esReintentable()) {
                return $ultimaResp;
            }
            Logger::warning('copilot', "Reintento Claude API tras {$espera}s", [
                'status' => $ultimaResp->status,
                'error' => $ultimaResp->errorRed,
            ]);
        }
        return $ultimaResp ?? new HttpResponse(0, '', 'Sin respuesta');
    }
}
