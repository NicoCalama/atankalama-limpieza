<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\Ticket;

/**
 * Copia cada ticket recién creado a la app `novedades` (mismo servidor, app
 * distinta) para que recepción lo vea junto a sus propios reportes. Ver
 * conversación de diseño — resumen:
 *
 * - Integración best-effort: si falla, se registra en logs_eventos y NO
 *   afecta la creación/cierre del ticket (los controllers de Ticket siguen igual).
 * - `novedades/store` es un formulario clásico (responde con redirect 302),
 *   no una API JSON. Se agregó ahí una rama aditiva: si la petición trae
 *   `origen=limpieza` + el header X-Limpieza-Token correcto, responde JSON
 *   en vez de redirigir — el formulario humano no se ve afectado.
 * - Fotos: `novedades/store` acepta $_FILES['archivos'][] en el mismo POST que
 *   crea la novedad (sin rama especial para la integración — ver
 *   NovedadController::store() del lado de novedades) y ya sabe dejar un .webp
 *   de entrada tal cual (ImagenService::convertirSiCorresponde). Se mandan como
 *   multipart real (CURLFile), no como link — Recepción no tiene cuenta en
 *   Limpieza para abrir un link protegido, y así queda igual que un adjunto
 *   humano normal (botón "Evidencia" de la tarjeta).
 * - novedades NO soporta editar una novedad ya creada (confirmado con el
 *   cliente) — el cierre del ticket se notifica como una novedad NUEVA de
 *   seguimiento, no como una edición de la original.
 * - No reusa Services\Http\CurlTransport: ese cliente no expone headers de
 *   respuesta ni sigue el contrato JSON de Cloudbeds/Claude; mezclarlo acá
 *   con un caso de uso distinto (multipart + token propio) no aporta.
 *
 * Mapeo de campos (decisión del cliente, no inventado):
 * - área = "Otros", departamento = "Aseo" (fijos para todo ticket de limpieza)
 * - nivel_importancia = 7 (fijo, no depende de la prioridad del ticket)
 * - recepcionista_id = usuario "Sistema Limpieza" (id fijo desde .env)
 */
final class NovedadesSyncService
{
    private const AREA = 'Otros';
    private const DEPARTAMENTO = 'Aseo';
    private const NIVEL_IMPORTANCIA = 7;

    /** hoteles.codigo => nombre que espera el selector de novedades. */
    private const HOTEL_A_NOMBRE = [
        '1_sur' => 'Atankalama',
        'inn' => 'Atankalama Inn',
    ];

    public function __construct(
        private readonly HotelService $hoteles = new HotelService(),
        private readonly HabitacionService $habitaciones = new HabitacionService(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $adjuntos filas de TicketService::adjuntosDe()
     *        (llamar DESPUÉS de procesar las fotos de creación, si no llegan vacías)
     */
    public function sincronizar(Ticket $ticket, array $adjuntos = []): void
    {
        $url = (string) Config::get('NOVEDADES_SYNC_URL', '');
        $token = (string) Config::get('NOVEDADES_SYNC_TOKEN', '');
        $recepcionistaId = Config::getInt('NOVEDADES_RECEPCIONISTA_ID', 0);

        if ($url === '' || $token === '' || $recepcionistaId <= 0) {
            Logger::warning('novedades_sync', 'Integración no configurada (falta URL/token/recepcionista) — se omite.', [
                'ticket_id' => $ticket->id,
            ]);
            return;
        }

        try {
            $payload = $this->armarPayload($ticket, $recepcionistaId);
            $resultado = $this->enviar($url, $token, $payload, $adjuntos);

            if (!$resultado['ok']) {
                Logger::error('novedades_sync', 'No se pudo copiar el ticket a novedades.', [
                    'ticket_id' => $ticket->id,
                    'motivo' => $resultado['motivo'] ?? 'desconocido',
                ]);
                return;
            }

            Logger::info('novedades_sync', 'Ticket copiado a novedades.', [
                'ticket_id' => $ticket->id,
                'novedad_id' => $resultado['novedad_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // Defensa final: un fallo acá nunca debe tumbar la creación del ticket.
            Logger::error('novedades_sync', 'Excepción al copiar ticket a novedades.', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notifica el cierre del ticket como una novedad NUEVA (no se puede editar la
     * novedad original creada por sincronizar()). Incluye todas las fotos del ticket
     * (creación + cierre) como adjuntos reales.
     *
     * @param list<array<string, mixed>> $adjuntos filas de TicketService::adjuntosDe()
     */
    public function sincronizarCierre(Ticket $ticket, array $adjuntos): void
    {
        $url = (string) Config::get('NOVEDADES_SYNC_URL', '');
        $token = (string) Config::get('NOVEDADES_SYNC_TOKEN', '');
        $recepcionistaId = Config::getInt('NOVEDADES_RECEPCIONISTA_ID', 0);

        if ($url === '' || $token === '' || $recepcionistaId <= 0) {
            Logger::warning('novedades_sync', 'Integración no configurada (falta URL/token/recepcionista) — se omite cierre.', [
                'ticket_id' => $ticket->id,
            ]);
            return;
        }

        try {
            $payload = $this->armarPayloadCierre($ticket, $recepcionistaId);
            $resultado = $this->enviar($url, $token, $payload, $adjuntos);

            if (!$resultado['ok']) {
                Logger::error('novedades_sync', 'No se pudo notificar el cierre a novedades.', [
                    'ticket_id' => $ticket->id,
                    'motivo' => $resultado['motivo'] ?? 'desconocido',
                ]);
                return;
            }

            Logger::info('novedades_sync', 'Cierre de ticket notificado a novedades.', [
                'ticket_id' => $ticket->id,
                'novedad_id' => $resultado['novedad_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // Defensa final: un fallo acá nunca debe tumbar el cierre del ticket.
            Logger::error('novedades_sync', 'Excepción al notificar cierre a novedades.', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<string, string> */
    private function armarPayloadCierre(Ticket $ticket, int $recepcionistaId): array
    {
        $hotel = $this->hoteles->buscarPorId($ticket->hotelId);
        $hotelNombre = $hotel !== null
            ? (self::HOTEL_A_NOMBRE[$hotel->codigo] ?? $hotel->nombre)
            : 'Atankalama';

        return [
            'origen' => 'limpieza',
            'recepcionista_id' => (string) $recepcionistaId,
            'area' => self::AREA,
            'tipo_novedad' => self::DEPARTAMENTO,
            'hotel' => $hotelNombre,
            'detalle' => "[Ticket Limpieza #{$ticket->id} — CERRADO] {$ticket->titulo}",
            'nivel_importancia' => (string) self::NIVEL_IMPORTANCIA,
            'requiere_seguimiento' => '0',
        ];
    }

    /** @return array<string, string> */
    private function armarPayload(Ticket $ticket, int $recepcionistaId): array
    {
        $hotel = $this->hoteles->buscarPorId($ticket->hotelId);
        $hotelNombre = $hotel !== null
            ? (self::HOTEL_A_NOMBRE[$hotel->codigo] ?? $hotel->nombre)
            : 'Atankalama';

        $detalle = "[Ticket Limpieza #{$ticket->id}] {$ticket->titulo}\n\n{$ticket->descripcion}";
        if ($ticket->habitacionId !== null) {
            $habitacion = $this->habitaciones->obtener($ticket->habitacionId);
            if ($habitacion !== null) {
                $detalle .= "\n\nHabitación: {$habitacion->numero}";
            }
        }

        return [
            'origen' => 'limpieza',
            'recepcionista_id' => (string) $recepcionistaId,
            'area' => self::AREA,
            'tipo_novedad' => self::DEPARTAMENTO,
            'hotel' => $hotelNombre,
            'detalle' => $detalle,
            'nivel_importancia' => (string) self::NIVEL_IMPORTANCIA,
            'requiere_seguimiento' => '0',
        ];
    }

    /**
     * Convierte adjuntos de ticket (fila con 'ruta' relativa a public/uploads/) en
     * CURLFile listos para el campo archivos[] — se saltea en silencio (con log) el
     * adjunto cuyo archivo físico ya no está, para no tumbar el resto del envío.
     *
     * @param list<array<string, mixed>> $adjuntos
     * @return array<string, \CURLFile>
     */
    private function archivosParaEnviar(array $adjuntos): array
    {
        $archivos = [];
        foreach ($adjuntos as $i => $adjunto) {
            $ruta = (string) ($adjunto['ruta'] ?? '');
            if ($ruta === '') {
                continue;
            }
            $absoluta = Config::basePath() . '/public/uploads/' . $ruta;
            if (!is_file($absoluta)) {
                Logger::warning('novedades_sync', 'Adjunto no encontrado en disco, se omite del envío.', ['ruta' => $ruta]);
                continue;
            }
            $nombreOriginal = (string) ($adjunto['nombre_original'] ?? '');
            $nombre = $nombreOriginal !== '' ? $nombreOriginal : basename($ruta);
            $archivos["archivos[{$i}]"] = new \CURLFile($absoluta, 'image/webp', $nombre);
        }
        return $archivos;
    }

    /**
     * @param array<string, string> $payload
     * @param list<array<string, mixed>> $adjuntos
     * @return array{ok: bool, novedad_id?: int, motivo?: string}
     */
    private function enviar(string $url, string $token, array $payload, array $adjuntos = []): array
    {
        // Array asociativo → curl arma multipart/form-data solo (boundary incluido);
        // no fijar Content-Type a mano, curl lo hace bien con CURLFile de por medio.
        $campos = $payload + $this->archivosParaEnviar($adjuntos);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $campos,
            CURLOPT_HTTPHEADER => [
                'X-Limpieza-Token: ' . $token,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $cuerpo = curl_exec($ch);
        $errno = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $cuerpo === false) {
            return ['ok' => false, 'motivo' => $errMsg !== '' ? $errMsg : "curl errno {$errno}"];
        }

        $data = json_decode((string) $cuerpo, true);
        if (!is_array($data)) {
            return ['ok' => false, 'motivo' => "respuesta no-JSON (HTTP {$status})"];
        }
        if (($data['ok'] ?? false) !== true) {
            return ['ok' => false, 'motivo' => (string) ($data['mensaje'] ?? "HTTP {$status}")];
        }

        return ['ok' => true, 'novedad_id' => (int) ($data['id'] ?? 0)];
    }
}
