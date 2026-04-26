<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Helpers\LogSanitizer;
use Atankalama\Limpieza\Models\AlertaActiva;
use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Models\Hotel;

/**
 * Sincronización Cloudbeds <-> app.
 *
 * Entrante (sincronizar): lee estados de limpieza y marca check-outs como 'sucia'.
 * Saliente (escribirEstadoClean): se llama desde auditoría al aprobar.
 */
final class CloudbedsSyncService
{
    public function __construct(
        private readonly CloudbedsClient $client,
        private readonly HotelService $hoteles = new HotelService(),
        private readonly HabitacionService $habitaciones = new HabitacionService(),
        private readonly AlertasService $alertas = new AlertasService(),
    ) {
    }

    /**
     * Sincroniza los estados de habitaciones desde Cloudbeds.
     *
     * @param string $tipo 'auto_cron' | 'manual'
     * @return int sync_historial.id
     */
    public function sincronizar(?int $hotelIdFiltro, string $tipo = 'manual', ?int $disparadaPor = null): int
    {
        $syncId = $this->crearHistorial($tipo, $hotelIdFiltro, $disparadaPor);

        $hoteles = $this->hoteles->listar(true);
        if ($hotelIdFiltro !== null) {
            $hoteles = array_filter($hoteles, fn(Hotel $h) => $h->id === $hotelIdFiltro);
        }

        $actualizadas = 0;
        $errores = 0;
        $detalle = [];

        foreach ($hoteles as $hotel) {
            if ($hotel->cloudbedsPropertyId === null || $hotel->cloudbedsPropertyId === '') {
                $errores++;
                $detalle[] = ['hotel' => $hotel->codigo, 'error' => 'sin cloudbeds_property_id'];
                continue;
            }

            try {
                $estados = $this->client->obtenerEstadosHabitaciones($hotel->cloudbedsPropertyId);
                $rooms = $estados['data'] ?? $estados['rooms'] ?? $estados;
                if (!is_array($rooms)) {
                    continue;
                }

                foreach ($rooms as $room) {
                    if (!is_array($room)) {
                        continue;
                    }
                    $cloudbedsRoomId = (string) ($room['roomID'] ?? $room['roomId'] ?? $room['id'] ?? '');
                    $cleaningStatus = strtolower((string) ($room['cleaningStatus'] ?? $room['roomCondition'] ?? ''));
                    if ($cloudbedsRoomId === '' || $cleaningStatus === '') {
                        continue;
                    }

                    $hab = $this->habitaciones->buscarPorCloudbedsRoomId($hotel->id, $cloudbedsRoomId);
                    if ($hab === null) {
                        continue;
                    }

                    if ($cleaningStatus === 'dirty' && $hab->estaEnEstadoTerminal()) {
                        $this->habitaciones->cambiarEstado($hab->id, Habitacion::ESTADO_SUCIA, null, 'cron');
                        $actualizadas++;
                    } elseif ($cleaningStatus === 'clean' && $hab->estado === Habitacion::ESTADO_COMPLETADA_PENDIENTE_AUDITORIA) {
                        Logger::warning('cloudbeds', 'inconsistencia: Cloudbeds Clean pero app pendiente auditoría', [
                            'habitacion_id' => $hab->id,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $errores++;
                $detalle[] = ['hotel' => $hotel->codigo, 'error' => $e->getMessage()];
                Logger::error('cloudbeds', 'error sincronizando hotel', ['hotel' => $hotel->codigo, 'mensaje' => $e->getMessage()]);
            }
        }

        $resultado = $errores === 0 ? 'exito' : ($actualizadas > 0 ? 'parcial' : 'error');
        $this->cerrarHistorial($syncId, $resultado, $actualizadas, $errores, $detalle);

        if ($resultado === 'error') {
            $this->crearAlertaP0('cloudbeds_sync_failed', 'Sincronización Cloudbeds falló', 'Revisar credenciales y logs.');
        }

        return $syncId;
    }

    /**
     * Escritura saliente: marca Clean en Cloudbeds al aprobar auditoría.
     * Registra en cloudbeds_sync_historial con tipo='escritura_estado'.
     * En caso de fallo, crea alerta P0 y retorna false.
     */
    public function escribirEstadoClean(Habitacion $habitacion): bool
    {
        $hotel = $this->hoteles->buscarPorId($habitacion->hotelId);
        if ($hotel === null || $hotel->cloudbedsPropertyId === null || $habitacion->cloudbedsRoomId === null) {
            Logger::warning('cloudbeds', 'escritura omitida: sin cloudbeds_property_id o cloudbeds_room_id', [
                'habitacion_id' => $habitacion->id,
            ]);
            return false;
        }

        $payload = [
            'propertyID' => $hotel->cloudbedsPropertyId,
            'roomID' => $habitacion->cloudbedsRoomId,
            'roomCondition' => 'Clean',
        ];

        Database::execute(
            "INSERT INTO cloudbeds_sync_historial (tipo, hotel_id, payload_request) VALUES ('escritura_estado', ?, ?)",
            [$hotel->id, json_encode(LogSanitizer::sanitize($payload), JSON_UNESCAPED_UNICODE)]
        );
        $histId = Database::lastInsertId();

        try {
            $resp = $this->client->actualizarEstadoHabitacion($hotel->cloudbedsPropertyId, $habitacion->cloudbedsRoomId, 'Clean');
            $exito = $resp->esExito();
            Database::execute(
                "UPDATE cloudbeds_sync_historial
                    SET finalizada_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now'),
                        resultado = ?,
                        habitaciones_sincronizadas = ?,
                        errores_count = ?,
                        payload_response = ?,
                        error_mensaje = ?
                  WHERE id = ?",
                [
                    $exito ? 'exito' : 'error',
                    $exito ? 1 : 0,
                    $exito ? 0 : 1,
                    json_encode(['status' => $resp->status, 'cuerpo' => substr($resp->cuerpo, 0, 500)], JSON_UNESCAPED_UNICODE),
                    $exito ? null : ('status=' . $resp->status . ' errorRed=' . ($resp->errorRed ?? '-')),
                    $histId,
                ]
            );

            if (!$exito) {
                $this->crearAlertaP0(
                    'cloudbeds_sync_failed',
                    "Error escribiendo habitación {$habitacion->numero} a Cloudbeds",
                    "Status: {$resp->status}. Revisar logs."
                );
            }
            return $exito;
        } catch (CloudbedsException $e) {
            Database::execute(
                "UPDATE cloudbeds_sync_historial
                    SET finalizada_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now'),
                        resultado = 'error',
                        errores_count = 1,
                        error_mensaje = ?
                  WHERE id = ?",
                [$e->codigo . ': ' . $e->getMessage(), $histId]
            );
            $this->crearAlertaP0(
                'cloudbeds_sync_failed',
                'Credencial Cloudbeds inválida',
                'Revisar CLOUDBEDS_API_KEY en Ajustes / .env.'
            );
            return false;
        }
    }

    /** @return array<string, mixed>|null */
    public function estadoActual(): ?array
    {
        return Database::fetchOne(
            'SELECT * FROM cloudbeds_sync_historial ORDER BY iniciada_at DESC LIMIT 1'
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function historial(int $limite = 50): array
    {
        return Database::fetchAll(
            'SELECT * FROM cloudbeds_sync_historial ORDER BY iniciada_at DESC LIMIT ?',
            [max(1, min(200, $limite))]
        );
    }

    /**
     * Obtiene una fila puntual del historial por id.
     *
     * @return array<string, mixed>|null
     */
    public function obtenerHistorial(int $syncId): ?array
    {
        return Database::fetchOne('SELECT * FROM cloudbeds_sync_historial WHERE id = ?', [$syncId]);
    }

    /**
     * Lista la configuración Cloudbeds (clave, valor, descripción, updated_at).
     *
     * @return list<array<string, mixed>>
     */
    public function listarConfig(): array
    {
        return Database::fetchAll(
            'SELECT clave, valor, descripcion, updated_at FROM cloudbeds_config ORDER BY clave'
        );
    }

    /**
     * Actualiza múltiples claves de cloudbeds_config en una transacción.
     *
     * @param array<string, mixed> $cambios mapa clave => valor
     */
    public function actualizarConfig(array $cambios, ?int $actorId): void
    {
        if ($cambios === []) {
            return;
        }
        Database::transaction(function () use ($cambios, $actorId): void {
            foreach ($cambios as $clave => $valor) {
                Database::execute(
                    "UPDATE cloudbeds_config SET valor = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), updated_by = ? WHERE clave = ?",
                    [(string) $valor, $actorId, (string) $clave]
                );
            }
        });
    }

    private function crearHistorial(string $tipo, ?int $hotelId, ?int $disparadaPor): int
    {
        Database::execute(
            'INSERT INTO cloudbeds_sync_historial (tipo, hotel_id, disparada_por) VALUES (?, ?, ?)',
            [$tipo, $hotelId, $disparadaPor]
        );
        return Database::lastInsertId();
    }

    /** @param array<int, array<string, mixed>> $detalle */
    private function cerrarHistorial(int $id, string $resultado, int $actualizadas, int $errores, array $detalle): void
    {
        Database::execute(
            "UPDATE cloudbeds_sync_historial
                SET finalizada_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now'),
                    resultado = ?,
                    habitaciones_sincronizadas = ?,
                    errores_count = ?,
                    payload_response = ?
              WHERE id = ?",
            [
                $resultado,
                $actualizadas,
                $errores,
                $detalle === [] ? null : json_encode($detalle, JSON_UNESCAPED_UNICODE),
                $id,
            ]
        );
    }

    private function crearAlertaP0(string $tipo, string $titulo, string $descripcion): void
    {
        if ($tipo !== AlertaActiva::TIPO_CLOUDBEDS_SYNC_FAILED) {
            return;
        }
        $this->alertas->levantar(
            AlertaActiva::TIPO_CLOUDBEDS_SYNC_FAILED,
            $titulo,
            $descripcion,
            [],
            null,
            'cloudbeds_sync',
        );
    }
}
