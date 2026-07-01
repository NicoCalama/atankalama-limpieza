<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\Habitacion;
use Atankalama\Limpieza\Models\Hotel;

/**
 * Importa el inventario REAL de habitaciones desde Cloudbeds a la app.
 *
 * Complementa a CloudbedsSyncService: el sync entrante actualiza el ESTADO de limpieza
 * de piezas ya conocidas; este import establece el catálogo de piezas en sí
 * (cloudbeds_room_id + numero + tipo). Es idempotente: correrlo N veces converge al
 * estado de Cloudbeds sin duplicar.
 *
 * Decisiones (ver docs/cloudbeds-import-inventario.md):
 *  - numero = prefijo numérico del roomName ('101-BOT2 M' -> '101'). Verificado único por
 *    hotel en el inventario real (0 colisiones). Si algún día colisionan, se reportan y se
 *    saltan (nunca se viola el UNIQUE(hotel_id, numero)).
 *  - tipo de limpieza mapeado por maxGuests a un set chico (Singular / Doble/Matrimonial /
 *    Suite/Familiar). Cada tipo tiene su checklist template default (seed.php).
 *  - roomBlocked -> se importa con activa=0 (se conserva el mapeo y el histórico).
 *  - Upsert por (hotel_id, cloudbeds_room_id) resuelto en código (portable SQLite/MariaDB).
 *  - Las piezas de la app cuyo cloudbeds_room_id ya no venga de Cloudbeds se desactivan
 *    (activa=0); NUNCA se borran (histórico de ejecuciones/auditorías).
 */
final class InventarioImportService
{
    public function __construct(
        private readonly CloudbedsClient $client,
        private readonly HotelService $hoteles = new HotelService(),
    ) {
    }

    /**
     * Importa el inventario de uno o todos los hoteles.
     *
     * @param string|null $hotelCodigo código de hotel ('1_sur'|'inn') o null = todos los activos
     * @param bool        $dryRun      true = calcula el plan sin escribir en la BD
     * @return array{
     *   dry_run: bool,
     *   hoteles: list<array<string, mixed>>,
     *   totales: array{creadas:int, actualizadas:int, sin_cambio:int, bloqueadas:int, desactivadas:int, colisiones:int}
     * }
     */
    public function importar(?string $hotelCodigo = null, bool $dryRun = false): array
    {
        $tiposPorNombre = $this->tiposPorNombre();

        $hoteles = $this->hoteles->listar(true);
        if ($hotelCodigo !== null && $hotelCodigo !== '') {
            $hoteles = array_values(array_filter($hoteles, static fn(Hotel $h) => $h->codigo === $hotelCodigo));
        }

        $resultadoHoteles = [];
        foreach ($hoteles as $hotel) {
            $resultadoHoteles[] = $this->importarHotel($hotel, $tiposPorNombre, $dryRun);
        }

        $totales = ['creadas' => 0, 'actualizadas' => 0, 'sin_cambio' => 0, 'bloqueadas' => 0, 'desactivadas' => 0, 'colisiones' => 0];
        foreach ($resultadoHoteles as $r) {
            $totales['creadas'] += $r['creadas'];
            $totales['actualizadas'] += $r['actualizadas'];
            $totales['sin_cambio'] += $r['sin_cambio'];
            $totales['bloqueadas'] += $r['bloqueadas'];
            $totales['desactivadas'] += $r['desactivadas'];
            $totales['colisiones'] += count($r['colisiones']);
        }

        return ['dry_run' => $dryRun, 'hoteles' => $resultadoHoteles, 'totales' => $totales];
    }

    /**
     * @param array<string, int> $tiposPorNombre
     * @return array<string, mixed>
     */
    private function importarHotel(Hotel $hotel, array $tiposPorNombre, bool $dryRun): array
    {
        $res = [
            'codigo' => $hotel->codigo,
            'nombre' => $hotel->nombre,
            'property_id' => $hotel->cloudbedsPropertyId,
            'total_cloudbeds' => 0,
            'creadas' => 0,
            'actualizadas' => 0,
            'sin_cambio' => 0,
            'bloqueadas' => 0,
            'desactivadas' => 0,
            'colisiones' => [],
            'cambios' => [],
            'error' => null,
        ];

        if ($hotel->cloudbedsPropertyId === null || $hotel->cloudbedsPropertyId === '') {
            $res['error'] = 'sin cloudbeds_property_id';
            return $res;
        }

        try {
            $json = $this->client->obtenerHabitaciones($hotel->cloudbedsPropertyId);
        } catch (\Throwable $e) {
            $res['error'] = $e->getMessage();
            Logger::error('inventario', 'error obteniendo habitaciones de Cloudbeds', [
                'hotel' => $hotel->codigo,
                'mensaje' => $e->getMessage(),
            ]);
            return $res;
        }

        if (($json['success'] ?? null) !== true) {
            $res['error'] = 'getRooms no devolvió success=true';
            return $res;
        }

        $rooms = $this->extraerRooms($json);
        $res['total_cloudbeds'] = count($rooms);

        // Estado actual de la app para este hotel.
        $actualesPorRoomId = [];
        $actualesPorNumero = [];
        foreach (Database::fetchAll(
            'SELECT id, numero, tipo_habitacion_id, cloudbeds_room_id, activa FROM #__habitaciones WHERE hotel_id = ?',
            [$hotel->id]
        ) as $fila) {
            $roomId = $fila['cloudbeds_room_id'];
            if ($roomId !== null && $roomId !== '') {
                $actualesPorRoomId[(string) $roomId] = $fila;
            }
            $actualesPorNumero[(string) $fila['numero']] = $fila;
        }

        // Prepara numero por room y detecta colisiones de numero DENTRO del feed.
        $numeroPorRoomId = [];
        $roomIdsPorNumero = [];
        foreach ($rooms as $room) {
            $roomId = (string) ($room['roomID'] ?? '');
            if ($roomId === '') {
                continue;
            }
            $numero = $this->parsearNumero((string) ($room['roomName'] ?? ''));
            $numeroPorRoomId[$roomId] = $numero;
            $roomIdsPorNumero[$numero][$roomId] = true;
        }
        $numerosEnColision = [];
        foreach ($roomIdsPorNumero as $numero => $ids) {
            if (count($ids) > 1) {
                $res['colisiones'][] = ['numero' => (string) $numero, 'room_ids' => array_keys($ids)];
                $numerosEnColision[(string) $numero] = true;
            }
        }

        $roomIdsVistos = [];
        $acciones = [];

        foreach ($rooms as $room) {
            $roomId = (string) ($room['roomID'] ?? '');
            if ($roomId === '') {
                continue;
            }
            $roomIdsVistos[$roomId] = true;

            $numero = $numeroPorRoomId[$roomId];
            $tipoNombre = $this->tipoNombrePorHuespedes((int) ($room['maxGuests'] ?? 0));
            $tipoId = $tiposPorNombre[$tipoNombre] ?? null;
            if ($tipoId === null) {
                throw new \RuntimeException(
                    "Falta el tipo_habitacion '{$tipoNombre}' en el catálogo. Corré `php scripts/seed.php` primero."
                );
            }
            $bloqueada = !empty($room['roomBlocked']);
            $activaDeseada = $bloqueada ? 0 : 1;

            $existePorRoomId = isset($actualesPorRoomId[$roomId]);

            // Colisión de numero: solo bloquea crear/renombrar. Si ya existe por room_id, sí
            // puedo actualizar tipo/activa sin tocar el numero.
            if (isset($numerosEnColision[$numero]) && !$existePorRoomId) {
                $res['cambios'][] = ['accion' => 'colision_saltada', 'numero' => $numero, 'room_id' => $roomId];
                continue;
            }

            if ($existePorRoomId) {
                $fila = $actualesPorRoomId[$roomId];
                $cambiaNumero = !isset($numerosEnColision[$numero]) && (string) $fila['numero'] !== $numero;
                $cambia = $cambiaNumero
                    || (int) $fila['tipo_habitacion_id'] !== $tipoId
                    || (int) $fila['activa'] !== $activaDeseada;
                if ($cambia) {
                    $acciones[] = [
                        'tipo' => 'update',
                        'id' => (int) $fila['id'],
                        'numero' => $cambiaNumero ? $numero : (string) $fila['numero'],
                        'tipo_id' => $tipoId,
                        'activa' => $activaDeseada,
                        'set_room_id' => null,
                    ];
                    $res['cambios'][] = ['accion' => 'actualizar', 'numero' => $numero, 'room_id' => $roomId, 'tipo' => $tipoNombre, 'activa' => $activaDeseada];
                    $res['actualizadas']++;
                } else {
                    $res['sin_cambio']++;
                }
                if ($bloqueada) {
                    $res['bloqueadas']++;
                }
                continue;
            }

            // No existe por room_id: ¿hay una pieza legada con ese numero pero sin room_id?
            if (isset($actualesPorNumero[$numero])) {
                $fila = $actualesPorNumero[$numero];
                $acciones[] = [
                    'tipo' => 'update',
                    'id' => (int) $fila['id'],
                    'numero' => $numero,
                    'tipo_id' => $tipoId,
                    'activa' => $activaDeseada,
                    'set_room_id' => $roomId,
                ];
                $res['cambios'][] = ['accion' => 'vincular', 'numero' => $numero, 'room_id' => $roomId, 'tipo' => $tipoNombre, 'activa' => $activaDeseada];
                $res['actualizadas']++;
                if ($bloqueada) {
                    $res['bloqueadas']++;
                }
                continue;
            }

            // Alta nueva.
            $acciones[] = [
                'tipo' => 'insert',
                'numero' => $numero,
                'tipo_id' => $tipoId,
                'activa' => $activaDeseada,
                'room_id' => $roomId,
            ];
            $res['cambios'][] = ['accion' => 'crear', 'numero' => $numero, 'room_id' => $roomId, 'tipo' => $tipoNombre, 'activa' => $activaDeseada];
            $res['creadas']++;
            if ($bloqueada) {
                $res['bloqueadas']++;
            }
        }

        // Desactivar piezas cuyo room_id ya no viene de Cloudbeds (nunca borrar).
        foreach ($actualesPorRoomId as $roomId => $fila) {
            if (!isset($roomIdsVistos[$roomId]) && (int) $fila['activa'] === 1) {
                $acciones[] = ['tipo' => 'desactivar', 'id' => (int) $fila['id']];
                $res['cambios'][] = ['accion' => 'desactivar', 'numero' => (string) $fila['numero'], 'room_id' => (string) $roomId];
                $res['desactivadas']++;
            }
        }

        if (!$dryRun && $acciones !== []) {
            $this->aplicarAcciones($hotel->id, $acciones);
        }

        Logger::info('inventario', 'import de inventario' . ($dryRun ? ' (dry-run)' : ''), [
            'hotel' => $hotel->codigo,
            'total_cloudbeds' => $res['total_cloudbeds'],
            'creadas' => $res['creadas'],
            'actualizadas' => $res['actualizadas'],
            'sin_cambio' => $res['sin_cambio'],
            'bloqueadas' => $res['bloqueadas'],
            'desactivadas' => $res['desactivadas'],
            'colisiones' => count($res['colisiones']),
        ]);

        return $res;
    }

    /**
     * Aplica las acciones calculadas dentro de una transacción (atómico por hotel).
     *
     * @param list<array<string, mixed>> $acciones
     */
    private function aplicarAcciones(int $hotelId, array $acciones): void
    {
        Database::transaction(function () use ($hotelId, $acciones): void {
            foreach ($acciones as $a) {
                switch ($a['tipo']) {
                    case 'insert':
                        Database::execute(
                            'INSERT INTO #__habitaciones (hotel_id, numero, tipo_habitacion_id, cloudbeds_room_id, estado, activa) VALUES (?, ?, ?, ?, ?, ?)',
                            [$hotelId, $a['numero'], $a['tipo_id'], $a['room_id'], Habitacion::ESTADO_SUCIA, $a['activa']]
                        );
                        break;
                    case 'update':
                        if ($a['set_room_id'] !== null) {
                            Database::execute(
                                "UPDATE #__habitaciones
                                    SET numero = ?, tipo_habitacion_id = ?, cloudbeds_room_id = ?, activa = ?,
                                        updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
                                  WHERE id = ?",
                                [$a['numero'], $a['tipo_id'], $a['set_room_id'], $a['activa'], $a['id']]
                            );
                        } else {
                            Database::execute(
                                "UPDATE #__habitaciones
                                    SET numero = ?, tipo_habitacion_id = ?, activa = ?,
                                        updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
                                  WHERE id = ?",
                                [$a['numero'], $a['tipo_id'], $a['activa'], $a['id']]
                            );
                        }
                        break;
                    case 'desactivar':
                        Database::execute(
                            "UPDATE #__habitaciones
                                SET activa = 0, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
                              WHERE id = ?",
                            [$a['id']]
                        );
                        break;
                }
            }
        });
    }

    /**
     * roomName de Cloudbeds -> numero operativo de la app (prefijo numérico).
     * Fallback: si no hay prefijo numérico, usa el roomName completo (no perder la pieza).
     */
    private function parsearNumero(string $roomName): string
    {
        if (preg_match('/^\s*(\d+)/', $roomName, $m) === 1) {
            return $m[1];
        }
        return trim($roomName);
    }

    /**
     * maxGuests -> nombre del tipo de limpieza (set chico). Ver catalogos.php.
     */
    private function tipoNombrePorHuespedes(int $maxGuests): string
    {
        return match (true) {
            $maxGuests <= 1 => 'Singular',
            $maxGuests === 2 => 'Doble/Matrimonial',
            default => 'Suite/Familiar', // 3+
        };
    }

    /**
     * @return array<string, int> nombre => id
     */
    private function tiposPorNombre(): array
    {
        $mapa = [];
        foreach (Database::fetchAll('SELECT id, nombre FROM #__tipos_habitacion') as $fila) {
            $mapa[(string) $fila['nombre']] = (int) $fila['id'];
        }
        return $mapa;
    }

    /**
     * Aplana las habitaciones de todos los grupos de `data` de getRooms.
     *
     * @param array<string, mixed> $json
     * @return list<array<string, mixed>>
     */
    private function extraerRooms(array $json): array
    {
        $rooms = [];
        foreach (($json['data'] ?? []) as $grupo) {
            if (!is_array($grupo)) {
                continue;
            }
            foreach (($grupo['rooms'] ?? []) as $room) {
                if (is_array($room)) {
                    $rooms[] = $room;
                }
            }
        }
        return $rooms;
    }
}
