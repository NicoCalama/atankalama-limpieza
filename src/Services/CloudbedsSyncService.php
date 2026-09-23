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

    /** Default de cadencia del sync automático (minutos) si la config no existe. */
    private const SYNC_INTERVALO_DEFAULT = 30;

    /** Throttle de sync MANUAL por usuario (segundos). No aplica al cron (tipo='auto_cron'). */
    private const THROTTLE_MANUAL_SEGUNDOS = 180;

    /**
     * Cadencia configurada del sync automático, en minutos. Lee cloudbeds_config
     * ('sync_intervalo_minutos'); si la clave no existe o no es numérica, usa el default (30).
     * Editable vía PUT /api/cloudbeds/config. Ver docs/cloudbeds.md §4.1.
     */
    public function intervaloSyncMinutos(): int
    {
        $fila = Database::fetchOne(
            "SELECT valor FROM #__cloudbeds_config WHERE clave = 'sync_intervalo_minutos'"
        );
        $valor = $fila !== null && is_numeric($fila['valor']) ? (int) $fila['valor'] : self::SYNC_INTERVALO_DEFAULT;
        return max(1, $valor);
    }

    /**
     * ¿Le toca correr al sync automático? El cron invoca el script seguido (p. ej. cada 10 min) y
     * este throttle decide según la última sync ENTRANTE que sirvió (exito/parcial): si pasaron
     * menos de N minutos desde que se inició, se omite. Las syncs con error NO throttlean (así el
     * siguiente tick del cron reintenta). Permite cambiar la cadencia desde Ajustes sin tocar crontab.
     */
    public function debeCorrerSyncAutomatica(?int $intervaloMinutos = null): bool
    {
        $intervalo = $intervaloMinutos ?? $this->intervaloSyncMinutos();
        $ultima = Database::fetchOne(
            "SELECT iniciada_at FROM #__cloudbeds_sync_historial
              WHERE tipo IN ('auto_cron', 'manual') AND resultado IN ('exito', 'parcial')
              ORDER BY id DESC LIMIT 1"
        );
        if ($ultima === null) {
            return true;
        }
        $ts = strtotime((string) $ultima['iniciada_at']);
        if ($ts === false) {
            return true;
        }
        return (time() - $ts) >= $intervalo * 60;
    }

    /**
     * Throttle de sync manual: máximo 1 disparo cada THROTTLE_MANUAL_SEGUNDOS por usuario.
     * Se basa en el último registro 'manual' de ESE usuario en cloudbeds_sync_historial
     * (no requiere tabla nueva). Lanza CloudbedsException('THROTTLED', ..., 429) si no
     * pasó el intervalo.
     */
    private function verificarThrottleManual(int $usuarioId): void
    {
        $ultima = Database::fetchOne(
            "SELECT iniciada_at FROM #__cloudbeds_sync_historial
              WHERE tipo = 'manual' AND disparada_por = ?
              ORDER BY id DESC LIMIT 1",
            [$usuarioId]
        );
        if ($ultima === null) {
            return;
        }
        $ts = strtotime((string) $ultima['iniciada_at']);
        if ($ts === false) {
            return;
        }
        $segundosRestantes = self::THROTTLE_MANUAL_SEGUNDOS - (time() - $ts);
        if ($segundosRestantes > 0) {
            throw new CloudbedsException(
                'THROTTLED',
                "Espera {$segundosRestantes} segundos antes de sincronizar de nuevo.",
                429
            );
        }
    }

    /**
     * Sincroniza los estados de habitaciones desde Cloudbeds.
     *
     * @param string $tipo 'auto_cron' | 'manual'
     * @return int sync_historial.id
     */
    public function sincronizar(?int $hotelIdFiltro, string $tipo = 'manual', ?int $disparadaPor = null): int
    {
        // Throttle solo a disparos manuales identificados (botón "Sincronizar ahora" /
        // "Actualizar ahora"): evita que un usuario dispare un sync de todo el hotel cada
        // pocos segundos. El cron ('auto_cron') no pasa por acá — ya tiene su propio
        // intervalo vía debeCorrerSyncAutomatica().
        if ($tipo === 'manual' && $disparadaPor !== null) {
            $this->verificarThrottleManual($disparadaPor);
        }

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
                // Sin success=true la lectura no sirvió (endpoint 404, credencial, error de
                // Cloudbeds). Contarlo como error en vez de reportar "éxito / 0 registros"
                // en silencio — que fue exactamente lo que ocultó el endpoint equivocado.
                if (($estados['success'] ?? null) !== true) {
                    $errores++;
                    $detalle[] = ['hotel' => $hotel->codigo, 'error' => 'respuesta de getHousekeepingStatus sin success=true'];
                    continue;
                }
                $rooms = $estados['data'] ?? $estados['rooms'] ?? [];
                if (!is_array($rooms)) {
                    continue;
                }

                // Mapa roomID → nombre del huésped con reserva activa hoy (getReservationAssignments).
                // Dato secundario para la ficha (quién sale/está en la pieza): un fallo acá NO aborta
                // el sync de limpieza (housekeeping), que es lo crítico — solo queda sin huésped.
                $mapaHuespedes = $this->mapaHuespedesPorHabitacion($hotel->cloudbedsPropertyId, $hotel->codigo);

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

                    // Guardar la ocupación (frontdeskStatus + arrival/departure) — contexto para
                    // priorizar y para la regla de sábanas. NO cambia el 'estado' de limpieza.
                    // Ver docs/ocupacion-y-sabanas.md
                    // roomName ya viene en este mismo getHousekeepingStatus: se refresca acá
                    // (cada ~10 min) sin una llamada aparte a getRooms. Fuente = Cloudbeds
                    // siempre; no es editable en la app.
                    $nombreCompleto = trim((string) ($room['roomName'] ?? ''));
                    $frontdesk = self::normalizarFrontdesk($room['frontdeskStatus'] ?? null);
                    $ocupada = array_key_exists('roomOccupied', $room) ? (bool) $room['roomOccupied'] : null;
                    $this->habitaciones->actualizarOcupacionCloudbeds(
                        $hab->id,
                        $frontdesk,
                        $ocupada,
                        self::normalizarFecha($room['arrivalDate'] ?? null),
                        self::normalizarFecha($room['departureDate'] ?? null),
                        $mapaHuespedes[$cloudbedsRoomId] ?? null,
                        $nombreCompleto !== '' ? $nombreCompleto : null,
                    );

                    if ($cleaningStatus === 'dirty' && $hab->estaEnEstadoTerminal()) {
                        if ($this->conservarAprobacionDelDia($hab, $frontdesk, $ocupada)) {
                            Logger::info('cloudbeds', 'aprobación del día conservada: Cloudbeds la marcó sucia con huésped adentro', [
                                'habitacion_id' => $hab->id,
                                'numero' => $hab->numero,
                                'estado' => $hab->estado,
                                'frontdesk' => $frontdesk,
                            ]);
                        } else {
                            $this->habitaciones->cambiarEstado($hab->id, Habitacion::ESTADO_SUCIA, null, 'cron');
                            $this->avisarAprobacionDeshecha($hab, $hotel, $frontdesk);
                            $actualizadas++;
                        }
                    } elseif ($cleaningStatus === 'clean' && !in_array($hab->estado, [Habitacion::ESTADO_APROBADA, Habitacion::ESTADO_APROBADA_CON_OBSERVACION], true)) {
                        // Decisión de negocio (2026-08-21): Cloudbeds es la fuente madre del
                        // estado real. Si ya reporta 'clean' pero acá sigue sucia/en_progreso/
                        // pendiente auditoría/rechazada, se fuerza a 'aprobada' saltando checklist
                        // y auditoría (forzar:true — ver HabitacionService::cambiarEstado()). Puede
                        // cerrar de golpe una limpieza que una trabajadora tenía en curso en la app
                        // si Cloudbeds ya la marcó clean por fuera de este flujo.
                        $this->habitaciones->cambiarEstado($hab->id, Habitacion::ESTADO_APROBADA, null, 'cron', forzar: true);
                        $actualizadas++;
                        Logger::warning('cloudbeds', 'auto-aprobada por Cloudbeds sin checklist/auditoría en la app', [
                            'habitacion_id' => $hab->id,
                            'estado_previo' => $hab->estado,
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
        return $this->escribirEstadoRoomCondition($habitacion, 'clean');
    }

    /**
     * Escritura saliente: marca Dirty en Cloudbeds cuando el cron de las 16:00 revierte una
     * habitación "nochero" a 'sucia' (ver scripts/sync-cloudbeds.php). Evita que el próximo sync
     * entrante, al ver la pieza todavía 'clean' en Cloudbeds, la fuerce de vuelta a 'aprobada'
     * (línea ~141 de sincronizar()) deshaciendo el aviso de aseo. Ver docs/nocheros.md.
     * Registra en cloudbeds_sync_historial con tipo='escritura_estado'.
     * En caso de fallo, crea alerta P0 y retorna false.
     */
    public function escribirEstadoDirty(Habitacion $habitacion): bool
    {
        return $this->escribirEstadoRoomCondition($habitacion, 'dirty');
    }

    /**
     * Escritura saliente compartida por escribirEstadoClean()/escribirEstadoDirty().
     *
     * @param string $condicion 'clean' | 'dirty' (minúscula: Cloudbeds la exige así, igual que
     *                          la devuelve getHousekeepingStatus — 'Clean' es rechazado).
     */
    /**
     * ¿Hay que conservar la aprobación de hoy aunque Cloudbeds diga 'dirty'?
     *
     * Cloudbeds marca una pieza 'dirty' apenas entra un huésped: para ellos es la marca del
     * servicio del día SIGUIENTE, no una limpieza pendiente. Si la app le hace caso sin
     * distinguir, deshace la aprobación del día y manda a limpiar de nuevo una pieza recién
     * hecha y ocupada.
     *
     * Pasó de verdad: el 22/09/2026 la pieza 706 se aprobó a las 11:15, la escritura a
     * Cloudbeds respondió `success: true`, y 25 minutos después el sync la devolvió a sucia
     * porque había entrado un huésped. La limpiaron dos veces. Ese día le pasó a ~8 piezas.
     *
     * La re-limpieza LEGÍTIMA del mismo día (se fue un huésped y entra otro) llega con la
     * pieza DESOCUPADA y frontdesk 'check-out'/'turnover', así que sigue revirtiendo igual
     * que antes. Y los nocheros no dependen de esta rama: los revierte su propio barrido de
     * las 16:00 (ver scripts/sync-cloudbeds.php).
     */
    private function conservarAprobacionDelDia(Habitacion $hab, ?string $frontdesk, ?bool $ocupada): bool
    {
        // Aprobación de otro día: manda el ciclo normal, se revierte como siempre.
        if (!$this->habitaciones->cambioDeEstadoHoy($hab->id)) {
            return false;
        }
        return $ocupada === true || in_array($frontdesk, ['check-in', 'stayover'], true);
    }

    /**
     * La sincronización deshizo una aprobación: queda en el log y le llega a la supervisora.
     *
     * Antes esto pasaba MUDO —la rama de al lado (Cloudbeds la aprueba sola) sí registraba un
     * WARNING—, así que alguien volvía a limpiar sin que nadie supiera por qué. La asimetría
     * costó horas de diagnóstico el 22/09/2026.
     */
    private function avisarAprobacionDeshecha(Habitacion $hab, Hotel $hotel, ?string $frontdesk): void
    {
        Logger::warning('cloudbeds', 'aprobación deshecha: Cloudbeds reporta la pieza sucia', [
            'habitacion_id' => $hab->id,
            'numero' => $hab->numero,
            'estado_previo' => $hab->estado,
            'frontdesk' => $frontdesk,
        ]);

        try {
            $this->alertas->levantar(
                AlertaActiva::TIPO_APROBACION_DESHECHA,
                "Habitación {$hab->numero} volvió a sucia",
                'Estaba aprobada, pero Cloudbeds la reporta sucia y volvió a la cola de limpieza.',
                ['habitacion_id' => $hab->id, 'frontdesk' => $frontdesk],
                $hotel->id,
                // Una alerta por pieza: si el sync la vuelve a ver sucia en el siguiente tick
                // no se apila otra. Ver AlertasService::levantar().
                "habitacion:{$hab->id}",
            );
        } catch (\Throwable $e) {
            // Avisar es importante, pero no puede tumbar la sincronización entera.
            Logger::error('cloudbeds', 'no se pudo levantar la alerta de aprobación deshecha', [
                'habitacion_id' => $hab->id,
                'mensaje' => $e->getMessage(),
            ]);
        }
    }

    private function escribirEstadoRoomCondition(Habitacion $habitacion, string $condicion): bool
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
            'roomCondition' => $condicion,
        ];

        Database::execute(
            "INSERT INTO #__cloudbeds_sync_historial (tipo, hotel_id, payload_request) VALUES ('escritura_estado', ?, ?)",
            [$hotel->id, json_encode(LogSanitizer::sanitize($payload), JSON_UNESCAPED_UNICODE)]
        );
        $histId = Database::lastInsertId();

        try {
            $resp = $this->client->actualizarEstadoHabitacion($hotel->cloudbedsPropertyId, $habitacion->cloudbedsRoomId, $condicion);
            // Cloudbeds responde HTTP 200 incluso cuando rechaza la escritura (p.ej.
            // {"success": false, "message": "..."}). No basta con esExito(): hay que exigir
            // success !== false en el cuerpo, si no una escritura fallida se registraría como éxito.
            $cuerpoResp = $resp->json();
            $mensajeCloudbeds = isset($cuerpoResp['message']) && is_string($cuerpoResp['message'])
                ? $cuerpoResp['message']
                : null;
            $exito = $resp->esExito() && ($cuerpoResp['success'] ?? false) !== false;
            Database::execute(
                "UPDATE #__cloudbeds_sync_historial
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
                    $exito ? null : ('status=' . $resp->status . ' success=false' . ($mensajeCloudbeds !== null ? ' msg=' . $mensajeCloudbeds : '') . ($resp->errorRed !== null ? ' errorRed=' . $resp->errorRed : '')),
                    $histId,
                ]
            );

            if (!$exito) {
                $this->crearAlertaP0(
                    'cloudbeds_sync_failed',
                    "Error escribiendo habitación {$habitacion->numero} a Cloudbeds",
                    'Status: ' . $resp->status . ($mensajeCloudbeds !== null ? ". Cloudbeds: {$mensajeCloudbeds}" : '') . '. Revisar logs.'
                );
            }
            return $exito;
        } catch (CloudbedsException $e) {
            Database::execute(
                "UPDATE #__cloudbeds_sync_historial
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
                'Revisar las credenciales Cloudbeds por propiedad (.env) en Ajustes.'
            );
            return false;
        }
    }

    /** @return array<string, mixed>|null */
    public function estadoActual(): ?array
    {
        return Database::fetchOne(
            'SELECT * FROM #__cloudbeds_sync_historial ORDER BY iniciada_at DESC LIMIT 1'
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function historial(int $limite = 50): array
    {
        // LIMIT inline (entero ya clampeado): los prepares nativos de MySQL rechazan 'LIMIT ?'.
        return Database::fetchAll(
            'SELECT * FROM #__cloudbeds_sync_historial ORDER BY iniciada_at DESC LIMIT ' . max(1, min(200, $limite))
        );
    }

    /**
     * Obtiene una fila puntual del historial por id.
     *
     * @return array<string, mixed>|null
     */
    public function obtenerHistorial(int $syncId): ?array
    {
        return Database::fetchOne('SELECT * FROM #__cloudbeds_sync_historial WHERE id = ?', [$syncId]);
    }

    /**
     * Lista la configuración Cloudbeds (clave, valor, descripción, updated_at).
     *
     * @return list<array<string, mixed>>
     */
    public function listarConfig(): array
    {
        return Database::fetchAll(
            'SELECT clave, valor, descripcion, updated_at FROM #__cloudbeds_config ORDER BY clave'
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
                    "UPDATE #__cloudbeds_config SET valor = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), updated_by = ? WHERE clave = ?",
                    [(string) $valor, $actorId, (string) $clave]
                );
            }
        });
    }

    /** Estados de ocupación válidos de Cloudbeds (frontdeskStatus). */
    private const FRONTDESK_VALIDOS = ['check-in', 'check-out', 'stayover', 'turnover', 'unused'];

    /** Normaliza frontdeskStatus de Cloudbeds a nuestro enum, o null si viene algo desconocido. */
    private static function normalizarFrontdesk(mixed $valor): ?string
    {
        $v = strtolower(trim((string) $valor));
        return in_array($v, self::FRONTDESK_VALIDOS, true) ? $v : null;
    }

    /** Normaliza una fecha de Cloudbeds: '-' o '' → null. */
    private static function normalizarFecha(mixed $valor): ?string
    {
        $v = trim((string) $valor);
        return ($v === '' || $v === '-') ? null : $v;
    }

    /**
     * Mapa roomID → guestName a partir de getReservationAssignments (asignaciones del día
     * actual). El roomID de `assigned` usa el mismo formato que getRooms/getHousekeepingStatus
     * (roomTypeID-índice), así que cruza directo con las claves del housekeeping ya recorrido.
     * companyName viene vacío en la práctica (el hotel anota la empresa a mano dentro del
     * nombre) — por eso se usa solo guestName, sin una llamada adicional a getReservation.
     * Nunca lanza: un fallo acá no debe abortar el sync de limpieza.
     *
     * @return array<string, string>
     */
    private function mapaHuespedesPorHabitacion(string $propertyId, string $hotelCodigo): array
    {
        $mapa = [];
        try {
            $asignaciones = $this->client->obtenerAsignacionesReservas($propertyId);
            if (($asignaciones['success'] ?? null) !== true || !is_array($asignaciones['data'] ?? null)) {
                return $mapa;
            }
            foreach ($asignaciones['data'] as $reserva) {
                if (!is_array($reserva)) {
                    continue;
                }
                $nombre = trim((string) ($reserva['guestName'] ?? ''));
                if ($nombre === '' || !is_array($reserva['assigned'] ?? null)) {
                    continue;
                }
                foreach ($reserva['assigned'] as $asignada) {
                    $roomId = is_array($asignada) ? (string) ($asignada['roomID'] ?? '') : '';
                    if ($roomId !== '') {
                        $mapa[$roomId] = $nombre;
                    }
                }
            }
        } catch (\Throwable $e) {
            Logger::warning('cloudbeds', 'no se pudo obtener huésped (getReservationAssignments)', [
                'hotel' => $hotelCodigo,
                'mensaje' => $e->getMessage(),
            ]);
        }
        return $mapa;
    }

    private function crearHistorial(string $tipo, ?int $hotelId, ?int $disparadaPor): int
    {
        Database::execute(
            'INSERT INTO #__cloudbeds_sync_historial (tipo, hotel_id, disparada_por) VALUES (?, ?, ?)',
            [$tipo, $hotelId, $disparadaPor]
        );
        return Database::lastInsertId();
    }

    /** @param array<int, array<string, mixed>> $detalle */
    private function cerrarHistorial(int $id, string $resultado, int $actualizadas, int $errores, array $detalle): void
    {
        Database::execute(
            "UPDATE #__cloudbeds_sync_historial
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
