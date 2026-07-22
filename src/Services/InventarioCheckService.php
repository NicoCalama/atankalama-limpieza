<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\AlertaActiva;
use Atankalama\Limpieza\Models\BitacoraAlerta;

/**
 * Detección de altas/bajas de habitaciones en Cloudbeds y su alerta a la supervisora.
 *
 * El sync `*\/10` (CloudbedsSyncService) solo actualiza el ESTADO de piezas ya conocidas;
 * NO se entera de piezas nuevas ni dadas de baja. Este servicio corre el import en modo
 * dry-run (solo lee de Cloudbeds, no escribe), y si detecta cambios levanta la alerta
 * `inventario_cambios_pendientes` (P2) con el detalle hotel + número de cada pieza. La
 * supervisora la resuelve con Aceptar (aplica el import) o Rechazar. Ver docs/cloudbeds-import-inventario.md.
 *
 * Reglas clave:
 *  - Throttle de 1 vez por día (persistido en cloudbeds_config): viaja en el mismo cron del
 *    sync sin agregar una entrada de crontab. `revisar(true)` ignora el throttle (manual).
 *  - "No molestar hasta que cambie": al Rechazar se guarda la huella (hash) del set de cambios;
 *    mientras el set sea idéntico no se vuelve a levantar la alerta. Si Cloudbeds cambia de
 *    nuevo, la huella difiere y se avisa otra vez.
 *  - Auto-resuelve: si en un chequeo ya no hay cambios, resuelve cualquier alerta activa y
 *    limpia la huella rechazada (la condición desapareció).
 */
final class InventarioCheckService
{
    private const CFG_ULTIMO_CHEQUEO = 'inventario_ultimo_chequeo_at';
    private const CFG_HUELLA_RECHAZADA = 'inventario_huella_rechazada';
    private const INTERVALO_HORAS = 24;
    private const MAX_DETALLE = 8;

    public function __construct(
        private readonly InventarioImportService $import,
        private readonly AlertasService $alertas = new AlertasService(),
    ) {
    }

    /**
     * Corre el chequeo. Respeta el throttle diario salvo $force.
     *
     * @return array{omitido: bool, motivo?: string, cambios?: int, accion?: string}
     */
    public function revisar(bool $force = false): array
    {
        if (!$force && !$this->debeChequear()) {
            return ['omitido' => true, 'motivo' => 'throttle'];
        }

        $resultado = $this->import->importar(null, true); // dry-run, todos los hoteles activos

        $huboError = $this->algunHotelConError($resultado);

        // El throttle diario solo avanza en un chequeo COMPLETO (sin hoteles caídos): así un
        // fallo transitorio de Cloudbeds se reintenta en el próximo tick del cron en vez de
        // esperar 24 h (misma filosofía que el sync entrante: los errores no throttlean).
        if (!$huboError) {
            $this->guardarConfig(self::CFG_ULTIMO_CHEQUEO, gmdate('Y-m-d\TH:i:s\Z'));
        }

        $cambios = $this->cambiosAccionables($resultado);

        if ($cambios === []) {
            if ($huboError) {
                // Chequeo INCOMPLETO: un hotel no respondió (getRooms falló). No confundir con
                // "estado limpio": un fallo externo transitorio NO debe auto-resolver una alerta
                // pendiente ni olvidar el rechazo. Se deja todo como está hasta un chequeo completo.
                Logger::warning('inventario', 'chequeo de inventario incompleto (hotel con error): no se toca la alerta', []);
                return ['omitido' => false, 'cambios' => 0, 'accion' => 'incompleto'];
            }
            // Estado limpio: la condición desapareció. Resolver alerta activa y olvidar el rechazo.
            $this->resolverActivaSiHay(BitacoraAlerta::RESOLUCION_AUTO);
            $this->guardarConfig(self::CFG_HUELLA_RECHAZADA, '');
            return ['omitido' => false, 'cambios' => 0, 'accion' => 'sin_cambios'];
        }

        $huella = $this->huella($cambios);

        // "No molestar hasta que cambie": la supervisora ya rechazó exactamente este set.
        if ($this->leerConfig(self::CFG_HUELLA_RECHAZADA) === $huella) {
            return ['omitido' => false, 'cambios' => count($cambios), 'accion' => 'rechazado_previamente'];
        }

        // El set difiere de lo rechazado (o nunca se rechazó): resolver una alerta stale (con
        // otra huella) y levantar la nueva. levantar() deduplica si ya hay una con esta huella.
        $this->resolverActivaConOtraHuella($huella);

        [$titulo, $descripcion] = $this->armarMensaje($resultado, $cambios);
        $this->alertas->levantar(
            AlertaActiva::TIPO_INVENTARIO_CAMBIOS,
            $titulo,
            $descripcion,
            ['cambios' => array_slice($cambios, 0, 50)],
            null,
            $huella, // dedupeKey = huella del set de cambios
        );

        Logger::info('inventario', 'alerta de cambios de inventario levantada', [
            'cambios' => count($cambios),
        ]);

        return ['omitido' => false, 'cambios' => count($cambios), 'accion' => 'alerta_levantada'];
    }

    /** Guarda la huella de un set de cambios como "rechazado" (no molestar hasta que cambie). */
    public function registrarRechazo(string $huella): void
    {
        if ($huella === '') {
            return;
        }
        $this->guardarConfig(self::CFG_HUELLA_RECHAZADA, $huella);
    }

    /** Olvida el rechazo (p.ej. tras aplicar los cambios: el estado cambió). */
    public function limpiarRechazo(): void
    {
        $this->guardarConfig(self::CFG_HUELLA_RECHAZADA, '');
    }

    /** Devuelve la huella (dedupe) guardada en una alerta de inventario activa, o null. */
    public function huellaDeAlerta(int $alertaId): ?string
    {
        $fila = Database::fetchOne(
            'SELECT contexto_json FROM #__alertas_activas WHERE id = ? AND tipo = ?',
            [$alertaId, AlertaActiva::TIPO_INVENTARIO_CAMBIOS]
        );
        if ($fila === null) {
            return null;
        }
        return $this->extraerDedupe($fila['contexto_json'] ?? null);
    }

    // -------------------------------------------------------------------------

    private function debeChequear(): bool
    {
        $ultimo = $this->leerConfig(self::CFG_ULTIMO_CHEQUEO);
        if ($ultimo === null || $ultimo === '') {
            return true;
        }
        $ts = strtotime($ultimo);
        if ($ts === false) {
            return true;
        }
        return (time() - $ts) >= self::INTERVALO_HORAS * 3600;
    }

    /**
     * Aplana los cambios accionables (crear/vincular/actualizar/desactivar/colisión) de
     * todos los hoteles que se pudieron consultar. Los hoteles con error (Cloudbeds
     * inalcanzable) se saltan: así un fallo transitorio de la API no fabrica "bajas".
     *
     * @param array{hoteles: list<array<string, mixed>>, totales: array<string, int>} $resultado
     * @return list<array{hotel_codigo: string, hotel_nombre: string, accion: string, numero: string, room_id: string, tipo: string, activa: string}>
     */
    private function cambiosAccionables(array $resultado): array
    {
        $out = [];
        foreach ($resultado['hoteles'] as $h) {
            if (($h['error'] ?? null) !== null) {
                continue;
            }
            foreach (($h['cambios'] ?? []) as $c) {
                $out[] = [
                    'hotel_codigo' => (string) $h['codigo'],
                    'hotel_nombre' => (string) $h['nombre'],
                    'accion' => (string) $c['accion'],
                    'numero' => (string) ($c['numero'] ?? ''),
                    'room_id' => (string) ($c['room_id'] ?? ''),
                    // tipo/activa distinguen cambios sucesivos sobre la MISMA pieza (p.ej.
                    // rechazar Singular→Doble y que luego cambie a Doble→Suite): sin esto la
                    // huella colisionaría por igual accion/numero/room_id y no re-alertaría.
                    'tipo' => (string) ($c['tipo'] ?? ''),
                    'activa' => (string) ($c['activa'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /** @param list<array<string, string>> $cambios */
    private function huella(array $cambios): string
    {
        $keys = array_map(
            static fn(array $c): string => $c['hotel_codigo'] . '|' . $c['accion'] . '|' . $c['numero'] . '|' . $c['room_id'] . '|' . $c['tipo'] . '|' . $c['activa'],
            $cambios
        );
        sort($keys);
        return hash('sha256', implode("\n", $keys));
    }

    /** @param array{hoteles: list<array<string, mixed>>} $resultado */
    private function algunHotelConError(array $resultado): bool
    {
        foreach ($resultado['hoteles'] as $h) {
            if (($h['error'] ?? null) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array{totales: array<string, int>} $resultado
     * @param list<array<string, string>> $cambios
     * @return array{0: string, 1: string}
     */
    private function armarMensaje(array $resultado, array $cambios): array
    {
        $t = $resultado['totales'];
        $partes = [];
        if ($t['creadas'] > 0) {
            $partes[] = $t['creadas'] . ' alta' . ($t['creadas'] === 1 ? '' : 's');
        }
        if ($t['desactivadas'] > 0) {
            $partes[] = $t['desactivadas'] . ' baja' . ($t['desactivadas'] === 1 ? '' : 's');
        }
        if ($t['actualizadas'] > 0) {
            $partes[] = $t['actualizadas'] . ' cambio' . ($t['actualizadas'] === 1 ? '' : 's');
        }
        if ($t['colisiones'] > 0) {
            $partes[] = $t['colisiones'] . ' colisión' . ($t['colisiones'] === 1 ? '' : 'es');
        }
        $titulo = 'Cambios de inventario en Cloudbeds' . ($partes !== [] ? ' — ' . implode(', ', $partes) : '');

        $etiqueta = [
            'crear' => 'Alta',
            'desactivar' => 'Baja',
            'vincular' => 'Vincular',
            'actualizar' => 'Cambio',
            'colision_saltada' => 'Colisión',
        ];
        $lineas = [];
        foreach (array_slice($cambios, 0, self::MAX_DETALLE) as $c) {
            $e = $etiqueta[$c['accion']] ?? 'Cambio';
            $lineas[] = trim($e . ': ' . $c['hotel_nombre'] . ' ' . $c['numero']);
        }
        $extra = count($cambios) - self::MAX_DETALLE;
        if ($extra > 0) {
            $lineas[] = 'y ' . $extra . ' más';
        }

        return [mb_substr($titulo, 0, 200), implode(' · ', $lineas)];
    }

    private function resolverActivaSiHay(string $resolucion): void
    {
        $a = $this->alertaActivaInventario();
        if ($a !== null) {
            $this->alertas->resolver((int) $a['id'], $resolucion);
        }
    }

    private function resolverActivaConOtraHuella(string $huella): void
    {
        $a = $this->alertaActivaInventario();
        if ($a === null) {
            return;
        }
        if ($this->extraerDedupe($a['contexto_json'] ?? null) !== $huella) {
            $this->alertas->resolver((int) $a['id'], BitacoraAlerta::RESOLUCION_AUTO);
        }
    }

    /** @return array<string, mixed>|null */
    private function alertaActivaInventario(): ?array
    {
        return Database::fetchOne(
            'SELECT id, contexto_json FROM #__alertas_activas WHERE tipo = ? ORDER BY id DESC LIMIT 1',
            [AlertaActiva::TIPO_INVENTARIO_CAMBIOS]
        );
    }

    private function extraerDedupe(mixed $contextoJson): ?string
    {
        if (!is_string($contextoJson) || $contextoJson === '') {
            return null;
        }
        $datos = json_decode($contextoJson, true);
        if (is_array($datos) && isset($datos['_dedupe']) && is_string($datos['_dedupe'])) {
            return $datos['_dedupe'];
        }
        return null;
    }

    private function leerConfig(string $clave): ?string
    {
        $fila = Database::fetchOne('SELECT valor FROM #__cloudbeds_config WHERE clave = ?', [$clave]);
        return $fila !== null ? (string) $fila['valor'] : null;
    }

    private function guardarConfig(string $clave, string $valor): void
    {
        $existe = Database::fetchOne('SELECT clave FROM #__cloudbeds_config WHERE clave = ?', [$clave]);
        if ($existe === null) {
            Database::execute(
                'INSERT INTO #__cloudbeds_config (clave, valor) VALUES (?, ?)',
                [$clave, $valor]
            );
        } else {
            Database::execute(
                "UPDATE #__cloudbeds_config SET valor = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE clave = ?",
                [$valor, $clave]
            );
        }
    }
}
