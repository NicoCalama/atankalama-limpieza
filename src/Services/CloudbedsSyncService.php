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
                // Instante de la lectura, con el reloj de la BASE (el mismo que fecha el audit_log).
                // Lo que Cloudbeds responde es una foto de ese momento: si una pieza cambia de estado
                // en la app mientras el sync recorre la lista (la reasignan, la aprueban), la foto ya
                // no sirve para decidir sobre ella. Ver piezaCambioTrasLeer().
                $leidoEn = (string) Database::fetchColumn("SELECT strftime('%Y-%m-%dT%H:%M:%fZ', 'now')");
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
                // Cuántos huéspedes hay en cada pieza (getReservations). Mismo criterio: si falla,
                // la pieza queda sin el número y el sync de limpieza sigue.
                $mapaCantidades = $this->mapaCantidadHuespedes($hotel->cloudbedsPropertyId, $hotel->codigo, date('Y-m-d'));

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
                        $mapaCantidades[$cloudbedsRoomId]['actuales'] ?? null,
                        $mapaCantidades[$cloudbedsRoomId]['llegan'] ?? null,
                    );

                    $cambiaria = ($cleaningStatus === 'dirty' && $hab->estaEnEstadoTerminal())
                        || ($cleaningStatus === 'clean' && !in_array($hab->estado, Habitacion::ESTADOS_APROBADOS, true));
                    if ($cambiaria && $this->piezaCambioTrasLeer($hab, $leidoEn, $cleaningStatus)) {
                        continue;
                    }

                    if ($cleaningStatus === 'dirty' && $hab->estaEnEstadoTerminal()) {
                        // Se pregunta ANTES de tocar el estado: revertir es un cambio de estado
                        // nuevo, de hoy, y desde ese momento toda pieza parecería «aprobada hoy».
                        $aprobadaHoy = $this->aprobadaHoy($hab);
                        $motivo = $this->motivoParaConservarAprobacion($hab, $aprobadaHoy, $frontdesk, $ocupada);
                        if ($motivo !== null) {
                            Logger::info('cloudbeds', 'aprobación conservada: Cloudbeds la marcó sucia', [
                                'habitacion_id' => $hab->id,
                                'numero' => $hab->numero,
                                'estado' => $hab->estado,
                                'frontdesk' => $frontdesk,
                                'ocupada' => $ocupada,
                                'motivo' => $motivo,
                            ]);
                        } else {
                            // Sin aviso (v6.22): lo que llega acá es el ciclo normal (aprobada otro
                            // día, así entra cada mañana el aseo del día), una rechazada (no la
                            // aprobó nadie), una que «aprobó» el cierre de la noche porque nadie
                            // terminó la limpieza, o el aseo diario de hoy cuyo huésped ya se fue
                            // (le toca la limpieza de salida).
                            $this->habitaciones->cambiarEstado($hab->id, Habitacion::ESTADO_SUCIA, null, 'cron');
                            $actualizadas++;
                        }
                    } elseif ($cleaningStatus === 'clean' && !in_array($hab->estado, [
                        Habitacion::ESTADO_APROBADA,
                        Habitacion::ESTADO_APROBADA_CON_OBSERVACION,
                        // 'aprobada_automatica' YA es una aprobación cerrada: la pieza está
                        // limpia y la marca dice que NADIE la inspeccionó. Re-aprobarla acá la
                        // convertía en 'aprobada' (49 piezas el 23/09/2026), con dos efectos:
                        // borraba esa marca —los KPIs de cobertura la contaban como
                        // inspeccionada— y le sumaba un cambio de estado de hoy, que es de
                        // donde aprobadaHoy() saca «¿se aprobó hoy?». Se deja como está.
                        Habitacion::ESTADO_APROBADA_AUTOMATICA,
                    ], true)) {
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

        // P0 también con 'parcial': un hotel que falla mientras el otro actualiza piezas dejaba de
        // sincronizar horas sin que nadie se enterara. Un sync completo la resuelve sola
        // (docs/alertas-predictivas.md §3.1).
        if ($errores > 0) {
            $hotelesConError = implode(', ', array_unique(array_column($detalle, 'hotel')));
            $this->crearAlertaP0(
                'cloudbeds_sync_failed',
                'Sincronización Cloudbeds falló',
                ($hotelesConError !== '' ? "Hotel: {$hotelesConError}. " : '') . 'Revisar credenciales y logs.'
            );
        } elseif ($hotelIdFiltro === null) {
            // Solo un sync de todos los hoteles la resuelve: uno filtrado a un hotel no dice nada
            // del otro, que puede ser el que falló.
            $this->alertas->resolverPorDedupe(AlertaActiva::TIPO_CLOUDBEDS_SYNC_FAILED, 'cloudbeds_sync');
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
     * ¿La pieza cambió de estado en la app DESPUÉS de que el sync leyó Cloudbeds? Entonces esa
     * pieza la decide el sync siguiente, con una lectura fresca (R1/R6, v6.17).
     *
     * Sin esto: una supervisora reasigna una pieza aprobada (pasa a sucia y la app le avisa
     * 'dirty' a Cloudbeds), pero el sync que ya venía corriendo traía la foto vieja 'clean' y
     * la re-aprobaba, deshaciendo la reasignación. O al revés: se aprueba una pieza justo
     * mientras el sync trae la foto vieja 'dirty', y el sync la deshace con una alerta falsa.
     */
    private function piezaCambioTrasLeer(Habitacion $hab, string $leidoEn, string $cleaningStatus): bool
    {
        if (!$this->habitaciones->cambioDeEstadoDespuesDe($hab->id, $leidoEn)) {
            return false;
        }
        Logger::info('cloudbeds', 'pieza omitida: cambió de estado después de leer Cloudbeds, la decide el sync siguiente', [
            'habitacion_id' => $hab->id,
            'numero' => $hab->numero,
            'estado' => $hab->estado,
            'cloudbeds' => $cleaningStatus,
        ]);
        return true;
    }

    /**
     * ¿La pieza está aprobada, y esa aprobación es de HOY?
     *
     * Solo cuenta una APROBACIÓN: estaEnEstadoTerminal() también abarca 'rechazada', y a esa
     * no la aprobó nadie, hay que rehacerla. El «cuándo» sale del último cambio de estado en
     * audit_log (HabitacionService::cambioDeEstadoHoy), no de updated_at, que también lo
     * mueven ediciones como la nota de Recepción o marcar nochero.
     */
    private function aprobadaHoy(Habitacion $hab): bool
    {
        return $hab->estaAprobada() && $this->habitaciones->cambioDeEstadoHoy($hab->id);
    }

    /**
     * ¿Hay que conservar la aprobación aunque Cloudbeds diga 'dirty'? Devuelve el motivo, o null
     * si la pieza vuelve a la cola.
     *
     * Regla de la v6.22 (decisión de Nicolás, 10/10/2026): **lo que se aprobó HOY queda limpio
     * hasta mañana**, diga lo que diga Cloudbeds, aunque la marca sucia la haya puesto Recepción
     * a mano. No se avisa a nadie y no se le escribe nada a Cloudbeds. Si de verdad hay que
     * limpiarla otra vez, se usa «Marcar sucia» en la app.
     *
     * **Única excepción:** si cuando se terminó la limpieza había un huésped alojado (la anotación
     * de la v6.20) y ahora Cloudbeds dice que la pieza quedó vacía, el huésped se fue después del
     * aseo diario (salida anticipada, cambio de pieza, o el anterior de un turnover que se limpió
     * antes de su check-out): vuelve a la cola para la limpieza de salida. Sin anotación (antes de
     * la v6.20, o sin lectura de Cloudbeds) no hay excepción.
     *
     * Reemplaza los casos de la v6.10 y la v6.20 (con huésped adentro, turnover limpiado vacío),
     * que dejaban pasar casos: el 10/10/2026 la 409 volvió a la cola porque Recepción hizo y
     * deshizo un check-in (Cloudbeds la dejó 'dirty' y sin nadie hospedado), aunque se había
     * limpiado vacía. Cloudbeds marca 'dirty' apenas entra un huésped (es la marca del servicio
     * del día SIGUIENTE), así que hacerle caso el mismo día manda a limpiar dos veces una pieza
     * limpia.
     *
     * Sigue igual:
     *  - Aprobada un día ANTERIOR: vuelve a la cola (así entra cada madrugada el aseo diario),
     *    salvo que hoy llegue un huésped a la pieza vacía (frontdesk 'check-in', ya ocupada):
     *    queda aprobada hasta el aseo de mañana (v6.20). Si Recepción la marca sucia antes de que
     *    llegue el huésped, se respeta.
     *  - Nunca se conserva una rechazada (no la aprobó nadie), ni una pieza cuya última limpieza
     *    la terminó el cierre de la noche porque nadie apretó «terminar» (v6.19): quedó aprobada
     *    para liberarla, no porque esté limpia.
     *  - Los nocheros no dependen de esta rama: los revierte su propio barrido de las 16:00 (ver
     *    scripts/sync-cloudbeds.php).
     */
    private function motivoParaConservarAprobacion(Habitacion $hab, bool $aprobadaHoy, ?string $frontdesk, ?bool $ocupada): ?string
    {
        if (!$hab->estaAprobada() || $this->habitaciones->ultimaLimpiezaLaCerroElSistema($hab->id)) {
            return null;
        }

        if ($aprobadaHoy) {
            // La única excepción: aseo diario con el huésped alojado, y el huésped ya se fue.
            if ($ocupada === false && $this->habitaciones->ultimaLimpiezaConHuespedAlojado($hab->id)) {
                return null;
            }
            return 'aprobada_hoy';
        }

        return $frontdesk === 'check-in' && $ocupada === true ? 'llegada_sobre_aprobacion_anterior' : null;
    }

    /**
     * Escritura saliente compartida por escribirEstadoClean()/escribirEstadoDirty().
     *
     * @param string $condicion 'clean' | 'dirty' (minúscula: Cloudbeds la exige así, igual que
     *                          la devuelve getHousekeepingStatus — 'Clean' es rechazado).
     */
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

            if ($exito) {
                // Una escritura de la misma pieza que funciona cierra la P0 de su escritura fallida.
                $this->alertas->resolverPorDedupe(AlertaActiva::TIPO_CLOUDBEDS_SYNC_FAILED, self::dedupeEscritura($habitacion));
            } else {
                $this->alertaEscrituraFallida(
                    $habitacion,
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
            $this->alertaEscrituraFallida(
                $habitacion,
                "No se pudo escribir la habitación {$habitacion->numero} en Cloudbeds",
                'Credencial Cloudbeds inválida: revisar las credenciales por propiedad (.env) en Ajustes.'
            );
            return false;
        }
    }

    /**
     * P0 propia de cada pieza cuya escritura a Cloudbeds falló. No comparte la clave de la P0 del
     * sync (`cloudbeds_sync`): esa se cierra sola con el siguiente sync de lectura sin errores, que
     * no reintenta la escritura, y la falla quedaba tapada justo cuando la pieza seguía desfasada
     * (p. ej. el barrido de nocheros de las 16:00 que no llegó a Cloudbeds). Esta se cierra cuando
     * una escritura de esa misma pieza funciona.
     */
    private function alertaEscrituraFallida(Habitacion $habitacion, string $titulo, string $descripcion): void
    {
        $this->alertas->levantar(
            AlertaActiva::TIPO_CLOUDBEDS_SYNC_FAILED,
            $titulo,
            $descripcion,
            ['habitacion_id' => $habitacion->id],
            $habitacion->hotelId,
            self::dedupeEscritura($habitacion),
        );
    }

    private static function dedupeEscritura(Habitacion $habitacion): string
    {
        return "cloudbeds_escritura:{$habitacion->id}";
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

    /**
     * Cuántos huéspedes (adultos + niños) tiene cada pieza hoy, a partir de getReservations
     * (CloudbedsClient::obtenerReservasDelDia). Sigue el criterio de Flexkeeping: la reserva
     * actual de la pieza y la llegada del mismo día.
     *  - `actuales`: los que están en la pieza (in_house). Si ya no queda nadie adentro, los
     *    que salieron hoy: es lo que usó la pieza que se va a limpiar.
     *  - `llegan`: los que llegan hoy y todavía no hacen check-in.
     * Si dos reservas comparten la pieza, se suman. Se ignoran las piezas sin roomID
     * (reservas viejas que Cloudbeds sigue dando «en casa» sin pieza asignada) y lo cancelado.
     * Nunca lanza: un fallo acá no debe abortar el sync de limpieza.
     *
     * @return array<string, array{actuales: int|null, llegan: int|null}>
     */
    private function mapaCantidadHuespedes(string $propertyId, string $hotelCodigo, string $hoy): array
    {
        $enCasa = [];
        $salieronHoy = [];
        $lleganHoy = [];
        try {
            $respuesta = $this->client->obtenerReservasDelDia($propertyId, $hoy);
            if (($respuesta['success'] ?? null) !== true || !is_array($respuesta['data'] ?? null)) {
                Logger::warning('cloudbeds', 'getReservations sin success=true: piezas sin cantidad de huéspedes', [
                    'hotel' => $hotelCodigo,
                ]);
                return [];
            }
            foreach ($respuesta['data'] as $reserva) {
                if (!is_array($reserva) || !is_array($reserva['rooms'] ?? null)) {
                    continue;
                }
                foreach ($reserva['rooms'] as $pieza) {
                    $roomId = is_array($pieza) ? (string) ($pieza['roomID'] ?? '') : '';
                    if ($roomId === '') {
                        continue;
                    }
                    $cantidad = (int) ($pieza['adults'] ?? 0) + (int) ($pieza['children'] ?? 0);
                    $estado = (string) ($pieza['roomStatus'] ?? '');
                    if ($estado === 'in_house') {
                        $enCasa[$roomId] = ($enCasa[$roomId] ?? 0) + $cantidad;
                    } elseif ($estado === 'checked_out' && substr((string) ($pieza['roomCheckOut'] ?? ''), 0, 10) === $hoy) {
                        $salieronHoy[$roomId] = ($salieronHoy[$roomId] ?? 0) + $cantidad;
                    } elseif ($estado === 'not_checked_in' && substr((string) ($pieza['roomCheckIn'] ?? ''), 0, 10) === $hoy) {
                        $lleganHoy[$roomId] = ($lleganHoy[$roomId] ?? 0) + $cantidad;
                    }
                }
            }
        } catch (\Throwable $e) {
            Logger::warning('cloudbeds', 'no se pudo obtener la cantidad de huéspedes (getReservations)', [
                'hotel' => $hotelCodigo,
                'mensaje' => $e->getMessage(),
            ]);
            return [];
        }

        $mapa = [];
        foreach (array_unique(array_merge(array_keys($enCasa), array_keys($salieronHoy), array_keys($lleganHoy))) as $roomId) {
            $actuales = $enCasa[$roomId] ?? $salieronHoy[$roomId] ?? 0;
            $llegan = $lleganHoy[$roomId] ?? 0;
            // 0 (datos incompletos en Cloudbeds) se guarda como «sin dato», no como pieza vacía.
            $mapa[(string) $roomId] = [
                'actuales' => $actuales > 0 ? $actuales : null,
                'llegan' => $llegan > 0 ? $llegan : null,
            ];
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
