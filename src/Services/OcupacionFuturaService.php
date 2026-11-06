<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Logger;

/**
 * Qué va a pasar en cada pieza un día que todavía no llega, según las reservas de Cloudbeds (v7.15).
 *
 * Lo usa el pool «Sin asignar» de Asignaciones al planificar un día futuro: antes ofrecía todo el
 * inventario, también las piezas que ese día no van a tener a nadie. Ver docs/asignacion.md §3.2.
 *
 * Por pieza (roomID de Cloudbeds), una sola de:
 *  - `cambio`: se va un huésped y llega otro ese mismo día
 *  - `se_va`:  el huésped se va ese día
 *  - `sigue`:  llegó antes y se va después (aseo diario); trae la fecha de llegada, para saber si ese
 *              día le toca cambio de sábanas
 *  - `llega`:  la pieza estaba libre y llega alguien ese día
 * Las piezas sin reserva ese día no aparecen.
 *
 * Usa la misma consulta que el sync para contar huéspedes (CloudbedsClient::obtenerReservasDelDia) y
 * guarda el resultado 10 minutos por hotel y fecha en storage/cache/ocupacion: el tablero se recarga
 * después de cada arrastre, y sin esto cada recarga volvería a preguntarle a Cloudbeds.
 */
final class OcupacionFuturaService
{
    public const TIPO_CAMBIO = 'cambio';
    public const TIPO_SE_VA = 'se_va';
    public const TIPO_SIGUE = 'sigue';
    public const TIPO_LLEGA = 'llega';

    /** Cuánto dura lo leído: las reservas de otro día no cambian a cada rato. */
    public const SEGUNDOS_CACHE = 600;

    /** Los archivos de cache de más de un día se borran al escribir uno nuevo. */
    private const SEGUNDOS_LIMPIEZA_CACHE = 86400;

    /** Reservas o piezas que no cuentan: no hay nadie, o ya se fue. */
    private const ESTADOS_DESCARTADOS = ['cancelled', 'canceled', 'no_show', 'checked_out'];

    private ?CloudbedsClient $clienteCreado = null;

    /**
     * @param CloudbedsClient|null $client   null = el del .env, sin reintentos: la supervisora está
     *                                       esperando la pantalla, y si Cloudbeds no contesta se le
     *                                       muestra todo con un aviso (puede reintentar con «Actualizar»).
     * @param string|null          $dirCache null = storage/cache/ocupacion; '' = sin cache.
     * @param \Closure|null        $reloj    fn(): int con la hora actual (los tests la fijan).
     */
    public function __construct(
        private readonly ?CloudbedsClient $client = null,
        private readonly ?string $dirCache = null,
        private readonly ?\Closure $reloj = null,
    ) {
    }

    /**
     * Las piezas de un hotel que tienen algo ese día, por roomID de Cloudbeds. null si no se pudo
     * saber (hotel sin propertyID, sin credencial, Cloudbeds caído o respuesta rara): el que llama
     * decide qué mostrar en ese caso. Nunca lanza.
     *
     * @return array<string, array{tipo: string, llegada: ?string}>|null
     */
    public function piezasDelDia(string $propertyId, string $fecha): ?array
    {
        if ($propertyId === '' || !self::esFecha($fecha)) {
            return null;
        }
        $enCache = $this->leerCache($propertyId, $fecha);
        if ($enCache !== null) {
            return $enCache;
        }

        try {
            $respuesta = $this->cliente()->obtenerReservasDelDia($propertyId, $fecha);
        } catch (\Throwable $e) {
            Logger::warning('cloudbeds', 'no se pudieron leer las reservas de un día futuro (getReservations)', [
                'propertyID' => $propertyId,
                'fecha' => $fecha,
                'mensaje' => $e->getMessage(),
            ]);
            return null;
        }
        if (($respuesta['success'] ?? null) !== true || !is_array($respuesta['data'] ?? null)) {
            Logger::warning('cloudbeds', 'getReservations de un día futuro sin success=true: se muestra todo el inventario', [
                'propertyID' => $propertyId,
                'fecha' => $fecha,
            ]);
            return null;
        }

        $piezas = self::clasificar(array_values(array_filter($respuesta['data'], 'is_array')), $fecha);
        $this->guardarCache($propertyId, $fecha, $piezas);
        return $piezas;
    }

    /**
     * Clasifica cada pieza en `fecha` a partir de las reservas de getReservations (datesQueryMode=rooms:
     * cada reserva trae rooms[] con roomID, roomCheckIn, roomCheckOut y roomStatus).
     *
     * Se ignoran lo cancelado, los no-show, lo que ya hizo check-out (ya no hay nadie) y las piezas sin
     * roomID (reservas que todavía no tienen pieza en Cloudbeds: no se sabe cuál les va a tocar).
     *
     * @param list<array<string, mixed>> $reservas
     * @return array<string, array{tipo: string, llegada: ?string}>
     */
    public static function clasificar(array $reservas, string $fecha): array
    {
        $seVan = [];
        $llegan = [];
        $siguen = []; // roomID => fecha de llegada del que sigue
        foreach ($reservas as $reserva) {
            if (in_array(strtolower((string) ($reserva['status'] ?? '')), self::ESTADOS_DESCARTADOS, true)) {
                continue;
            }
            if (!is_array($reserva['rooms'] ?? null)) {
                continue;
            }
            foreach ($reserva['rooms'] as $pieza) {
                if (!is_array($pieza)) {
                    continue;
                }
                $roomId = (string) ($pieza['roomID'] ?? '');
                if ($roomId === '' || in_array(strtolower((string) ($pieza['roomStatus'] ?? '')), self::ESTADOS_DESCARTADOS, true)) {
                    continue;
                }
                $entra = substr((string) ($pieza['roomCheckIn'] ?? ''), 0, 10);
                $sale = substr((string) ($pieza['roomCheckOut'] ?? ''), 0, 10);
                if (!self::esFecha($entra) || !self::esFecha($sale)) {
                    continue;
                }
                if ($sale === $fecha) {
                    $seVan[$roomId] = true;
                }
                if ($entra === $fecha) {
                    $llegan[$roomId] = true;
                }
                if ($entra < $fecha && $sale > $fecha) {
                    // Si Cloudbeds trajera dos que siguen en la misma pieza, manda el último que llegó:
                    // con él empezó la cuenta de las sábanas.
                    $siguen[$roomId] = max($siguen[$roomId] ?? $entra, $entra);
                }
            }
        }

        $resultado = [];
        $roomIds = array_unique(array_map('strval', array_merge(array_keys($seVan), array_keys($llegan), array_keys($siguen))));
        foreach ($roomIds as $roomId) {
            if (isset($seVan[$roomId], $llegan[$roomId])) {
                $resultado[$roomId] = ['tipo' => self::TIPO_CAMBIO, 'llegada' => null];
            } elseif (isset($seVan[$roomId])) {
                $resultado[$roomId] = ['tipo' => self::TIPO_SE_VA, 'llegada' => null];
            } elseif (isset($siguen[$roomId])) {
                $resultado[$roomId] = ['tipo' => self::TIPO_SIGUE, 'llegada' => $siguen[$roomId]];
            } else {
                $resultado[$roomId] = ['tipo' => self::TIPO_LLEGA, 'llegada' => null];
            }
        }
        return $resultado;
    }

    private static function esFecha(string $valor): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1;
    }

    private function cliente(): CloudbedsClient
    {
        return $this->client ?? ($this->clienteCreado ??= CloudbedsClient::desdeConfig(backoffs: []));
    }

    private function ahora(): int
    {
        return $this->reloj !== null ? (int) ($this->reloj)() : time();
    }

    private function directorioCache(): ?string
    {
        $dir = $this->dirCache ?? Config::basePath() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'ocupacion';
        return $dir === '' ? null : $dir;
    }

    private function rutaCache(string $propertyId, string $fecha): ?string
    {
        $dir = $this->directorioCache();
        if ($dir === null) {
            return null;
        }
        // La fecha ya viene validada (AAAA-MM-DD); el propertyID se limpia igual, va en un nombre de archivo.
        $propiedad = preg_replace('/[^A-Za-z0-9_-]/', '', $propertyId) ?? '';
        return $dir . DIRECTORY_SEPARATOR . $propiedad . '_' . $fecha . '.json';
    }

    /** @return array<string, array{tipo: string, llegada: ?string}>|null */
    private function leerCache(string $propertyId, string $fecha): ?array
    {
        $ruta = $this->rutaCache($propertyId, $fecha);
        if ($ruta === null || !is_file($ruta)) {
            return null;
        }
        $contenido = @file_get_contents($ruta);
        $datos = is_string($contenido) ? json_decode($contenido, true) : null;
        if (!is_array($datos) || !is_int($datos['guardado'] ?? null) || !is_array($datos['piezas'] ?? null)) {
            return null;
        }
        if ($this->ahora() - $datos['guardado'] >= self::SEGUNDOS_CACHE) {
            return null;
        }
        $piezas = [];
        foreach ($datos['piezas'] as $roomId => $pieza) {
            if (!is_array($pieza) || !is_string($pieza['tipo'] ?? null)) {
                return null;
            }
            $llegada = $pieza['llegada'] ?? null;
            $piezas[(string) $roomId] = ['tipo' => $pieza['tipo'], 'llegada' => is_string($llegada) ? $llegada : null];
        }
        return $piezas;
    }

    /** @param array<string, array{tipo: string, llegada: ?string}> $piezas */
    private function guardarCache(string $propertyId, string $fecha, array $piezas): void
    {
        $ruta = $this->rutaCache($propertyId, $fecha);
        $dir = $this->directorioCache();
        if ($ruta === null || $dir === null) {
            return;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            Logger::warning('cloudbeds', 'no se pudo crear el directorio de cache de reservas', ['dir' => $dir]);
            return;
        }
        $this->borrarCacheVieja($dir);
        $json = json_encode(['guardado' => $this->ahora(), 'piezas' => (object) $piezas]);
        $temporal = $ruta . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if ($json === false || @file_put_contents($temporal, $json) === false || !@rename($temporal, $ruta)) {
            @unlink($temporal);
            Logger::warning('cloudbeds', 'no se pudo guardar la cache de reservas', ['ruta' => $ruta]);
        }
    }

    /** Con la hora real (no la del reloj inyectado): se compara contra la fecha del archivo en disco. */
    private function borrarCacheVieja(string $dir): void
    {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $archivo) {
            $modificado = @filemtime($archivo);
            if ($modificado !== false && time() - $modificado > self::SEGUNDOS_LIMPIEZA_CACHE) {
                @unlink($archivo);
            }
        }
    }
}
