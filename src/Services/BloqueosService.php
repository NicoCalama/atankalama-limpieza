<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\AlertaActiva;
use Atankalama\Limpieza\Models\Habitacion;

/**
 * Piezas en mantención o cerradas en Cloudbeds (v7.8, pedido de Nicolás del 15/10/2026). Ver
 * docs/cloudbeds.md §4.5.
 *
 * En cada sync se leen los bloqueos del día (getRoomBlocks) y se anotan en la pieza
 * (habitaciones.cb_bloqueo_*). Decisiones de Nicolás:
 *   - mientras dura, la pieza no se asigna para hoy ni está en ninguna cola: si alguien la tenía
 *     sin empezar, sale de su cola con aviso (si ya la estaba limpiando, la termina);
 *   - al terminar el bloqueo se genera su limpieza: pasa a sucia (y se avisa a Cloudbeds), queda
 *     sin asignar con un aviso P2 a la supervisora, y esa limpieza es completa, con cambio de
 *     sábanas (habitaciones.limpieza_completa_pendiente);
 *   - mantención y cerrada se manejan distinto: cada una tiene su interruptor en Ajustes →
 *     Cloudbeds para generar o no esa limpieza (prendidos si nadie los tocó).
 * Las reservas provisorias (courtesy_hold) no son un bloqueo para la app.
 */
final class BloqueosService
{
    public const TIPO_MANTENCION = 'mantencion';
    public const TIPO_CERRADA = 'cerrada';
    /** Cloudbeds la da bloqueada (roomBlocked) pero no se pudo saber de qué tipo. */
    public const TIPO_BLOQUEADA = 'bloqueada';

    public const CLAVE_LIMPIEZA_MANTENCION = 'bloqueo_limpieza_al_salir_mantencion';
    public const CLAVE_LIMPIEZA_CERRADA = 'bloqueo_limpieza_al_salir_cerrada';

    /** Qué pasó con la pieza al aplicar la lectura de Cloudbeds. */
    public const SIN_CAMBIO = 'sin_cambio';
    public const ENTRO = 'entro';
    public const SALIO = 'salio';
    public const SALIO_CON_LIMPIEZA = 'salio_con_limpieza';

    private const ETIQUETAS = [
        self::TIPO_MANTENCION => 'mantención',
        self::TIPO_CERRADA => 'cerrada',
        self::TIPO_BLOQUEADA => 'bloqueada en Cloudbeds',
    ];

    public function __construct(
        private readonly HabitacionService $habitaciones = new HabitacionService(),
        private readonly AsignacionService $asignaciones = new AsignacionService(),
        private readonly AlertasService $alertas = new AlertasService(),
    ) {
    }

    // ───────────────────────────── Lectura de Cloudbeds ─────────────────────────────

    /** Tipo de la app para un roomBlockType de Cloudbeds; null = no es un bloqueo (reserva provisoria). */
    public static function tipoDesdeCloudbeds(string $roomBlockType): ?string
    {
        return match (strtolower(trim($roomBlockType))) {
            'out_of_service' => self::TIPO_MANTENCION,
            'blocked_dates', 'blocked' => self::TIPO_CERRADA,
            'courtesy_hold' => null,
            default => self::TIPO_BLOQUEADA,
        };
    }

    /**
     * Arma, desde los bloqueos de getRoomBlocks, roomID → bloqueo. Las reservas provisorias van con
     * tipo null: así se sabe que esa pieza figura en Cloudbeds y no es un bloqueo. Si una pieza tiene
     * dos bloqueos el mismo día, gana la mantención.
     *
     * @param list<array<string, mixed>> $bloqueos
     * @return array<string, array{tipo: ?string, motivo: ?string, desde: ?string, hasta: ?string}>
     */
    public static function mapaPorPieza(array $bloqueos): array
    {
        $mapa = [];
        foreach ($bloqueos as $b) {
            $tipo = self::tipoDesdeCloudbeds((string) ($b['roomBlockType'] ?? ''));
            $dato = [
                'tipo' => $tipo,
                'motivo' => self::texto($b['roomBlockReason'] ?? null),
                'desde' => self::fecha($b['startDate'] ?? null),
                'hasta' => self::fecha($b['endDate'] ?? null),
            ];
            foreach (is_array($b['rooms'] ?? null) ? $b['rooms'] : [] as $room) {
                $roomId = is_array($room) ? (string) ($room['roomID'] ?? '') : '';
                if ($roomId === '') {
                    continue;
                }
                $actual = $mapa[$roomId] ?? null;
                if ($actual === null || $actual['tipo'] === null
                    || ($dato['tipo'] === self::TIPO_MANTENCION && $actual['tipo'] !== self::TIPO_MANTENCION)) {
                    $mapa[$roomId] = $dato;
                }
            }
        }
        return $mapa;
    }

    /**
     * El bloqueo de una pieza hoy, o null si no está bloqueada.
     *
     * Con $mapa (getRoomBlocks respondió) manda el mapa; si la pieza no figura pero Cloudbeds la da
     * bloqueada (roomBlocked), queda como «bloqueada» sin tipo. Sin $mapa (getRoomBlocks falló o la
     * API key no tiene el permiso), solo se sabe si está bloqueada.
     *
     * @param array<string, array{tipo: ?string, motivo: ?string, desde: ?string, hasta: ?string}>|null $mapa
     * @return array{tipo: string, motivo: ?string, desde: ?string, hasta: ?string}|null
     */
    public static function bloqueoDeLaPieza(string $roomId, ?array $mapa, bool $roomBlocked): ?array
    {
        if ($mapa !== null && isset($mapa[$roomId])) {
            $b = $mapa[$roomId];
            return $b['tipo'] === null ? null : ['tipo' => $b['tipo'], 'motivo' => $b['motivo'], 'desde' => $b['desde'], 'hasta' => $b['hasta']];
        }
        return $roomBlocked ? ['tipo' => self::TIPO_BLOQUEADA, 'motivo' => null, 'desde' => null, 'hasta' => null] : null;
    }

    /**
     * Aplica la lectura de hoy a una pieza. Devuelve qué pasó (SIN_CAMBIO, ENTRO, SALIO o
     * SALIO_CON_LIMPIEZA); con SALIO_CON_LIMPIEZA el llamador le avisa 'dirty' a Cloudbeds (la red
     * queda afuera de este servicio, como en el resto del sync).
     *
     * @param array{tipo: string, motivo: ?string, desde: ?string, hasta: ?string}|null $bloqueo
     */
    public function aplicar(Habitacion $hab, ?array $bloqueo, string $hoy): string
    {
        $anterior = Database::fetchOne(
            'SELECT cb_bloqueo_tipo, cb_bloqueo_motivo, cb_bloqueo_desde, cb_bloqueo_hasta FROM #__habitaciones WHERE id = ?',
            [$hab->id]
        );
        $tipoAnterior = $anterior === null ? null : self::texto($anterior['cb_bloqueo_tipo'] ?? null);

        if ($bloqueo === null) {
            if ($tipoAnterior === null) {
                return self::SIN_CAMBIO;
            }
            return $this->salir($hab, $tipoAnterior, $anterior);
        }

        $cambio = $tipoAnterior !== $bloqueo['tipo']
            || self::texto($anterior['cb_bloqueo_motivo'] ?? null) !== $bloqueo['motivo']
            || self::texto($anterior['cb_bloqueo_desde'] ?? null) !== $bloqueo['desde']
            || self::texto($anterior['cb_bloqueo_hasta'] ?? null) !== $bloqueo['hasta'];
        if ($cambio) {
            Database::execute(
                "UPDATE #__habitaciones SET cb_bloqueo_tipo = ?, cb_bloqueo_motivo = ?, cb_bloqueo_desde = ?, cb_bloqueo_hasta = ?,
                        updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
                  WHERE id = ?",
                [$bloqueo['tipo'], $bloqueo['motivo'], $bloqueo['desde'], $bloqueo['hasta'], $hab->id]
            );
        }

        // Mientras está bloqueada no está en ninguna cola de hoy: también una preasignación que
        // recién llega a su día, por eso se revisa en cada sync y no solo al entrar.
        $this->asignaciones->retirarPorBloqueo($hab->id, $hoy, self::comoEsta($bloqueo['tipo']));

        if ($tipoAnterior !== null) {
            return self::SIN_CAMBIO;
        }

        // Si salió y volvió a entrar antes de que alguien tomara su limpieza, el aviso ya no sirve.
        $this->resolverAlerta($hab->id);
        Logger::audit(null, 'habitacion.bloqueo_inicio', 'habitacion', $hab->id, [
            'numero' => $hab->numero,
            'tipo' => $bloqueo['tipo'],
            'motivo' => $bloqueo['motivo'],
            'hasta' => $bloqueo['hasta'],
        ]);
        return self::ENTRO;
    }

    /**
     * Terminó el bloqueo. Con el interruptor de su tipo prendido se genera la limpieza: la pieza pasa
     * a sucia (si no lo estaba), su próxima limpieza es completa y, si nadie la tiene hoy, la
     * supervisora recibe el aviso P2.
     *
     * @param array<string, mixed>|null $anterior
     */
    private function salir(Habitacion $hab, string $tipo, ?array $anterior): string
    {
        // Una pieza que ya se limpió y espera inspección no se vuelve a ensuciar (la app no permite
        // esa transición, y tampoco hace falta: la limpieza ya está hecha).
        $generar = $this->limpiezaAlSalir($tipo) && $hab->estado !== Habitacion::ESTADO_COMPLETADA_PENDIENTE_AUDITORIA;
        Database::execute(
            "UPDATE #__habitaciones SET cb_bloqueo_tipo = NULL, cb_bloqueo_motivo = NULL, cb_bloqueo_desde = NULL, cb_bloqueo_hasta = NULL,"
            . ($generar ? ' limpieza_completa_pendiente = 1,' : '')
            . " updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE id = ?",
            [$hab->id]
        );
        Logger::audit(null, 'habitacion.bloqueo_fin', 'habitacion', $hab->id, [
            'numero' => $hab->numero,
            'tipo' => $tipo,
            'motivo' => $anterior['cb_bloqueo_motivo'] ?? null,
            'genera_limpieza' => $generar,
        ]);
        if (!$generar) {
            return self::SALIO;
        }

        if (in_array($hab->estado, [Habitacion::ESTADO_SUCIA, Habitacion::ESTADO_EN_PROGRESO], true) === false) {
            $this->habitaciones->cambiarEstado($hab->id, Habitacion::ESTADO_SUCIA, null, 'cron');
        }

        // Si nadie la está limpiando ahora, queda sin asignar (decisión de Nicolás): una asignación de hoy
        // ya terminada (la limpiaron antes del bloqueo) la devolvería a la cola de esa persona sin que
        // nadie lo decidiera. Los créditos de esa limpieza no cambian: Reportes cuenta las ejecuciones.
        if ($hab->estado !== Habitacion::ESTADO_EN_PROGRESO) {
            $this->asignaciones->soltarParaLimpiezaNueva($hab->id, date('Y-m-d'));
        }
        if ($this->asignaciones->obtenerActivaDeHabitacion($hab->id, date('Y-m-d')) === null) {
            $etiqueta = $tipo === self::TIPO_MANTENCION ? 'salió de mantención' : 'dejó de estar ' . self::etiqueta($tipo);
            $this->alertas->levantar(
                AlertaActiva::TIPO_HABITACION_SALIO_BLOQUEO,
                "Habitación {$hab->numero} {$etiqueta}",
                'Necesita una limpieza completa, con cambio de sábanas. Está sin asignar.',
                ['habitacion_id' => $hab->id, 'numero' => $hab->numero, 'bloqueo' => $tipo],
                $hab->hotelId,
                self::dedupe($hab->id)
            );
        }
        return self::SALIO_CON_LIMPIEZA;
    }

    // ───────────────────────────── Interruptores ─────────────────────────────

    /** ¿Al terminar un bloqueo de este tipo se genera la limpieza? Sin fila = prendido. «Bloqueada» sin tipo sigue al de mantención. */
    public function limpiezaAlSalir(string $tipo): bool
    {
        $clave = $tipo === self::TIPO_CERRADA ? self::CLAVE_LIMPIEZA_CERRADA : self::CLAVE_LIMPIEZA_MANTENCION;
        $valor = Database::fetchColumn('SELECT valor FROM #__alertas_config WHERE clave = ?', [$clave]);
        return $valor === null || $valor === false || (string) $valor !== '0';
    }

    /** @return array{mantencion: bool, cerrada: bool} */
    public function config(): array
    {
        return [
            'mantencion' => $this->limpiezaAlSalir(self::TIPO_MANTENCION),
            'cerrada' => $this->limpiezaAlSalir(self::TIPO_CERRADA),
        ];
    }

    public function configurar(string $tipo, bool $prendido, int $usuarioId): void
    {
        if (!in_array($tipo, [self::TIPO_MANTENCION, self::TIPO_CERRADA], true)) {
            throw new \InvalidArgumentException("Tipo de bloqueo no configurable: {$tipo}");
        }
        $clave = $tipo === self::TIPO_CERRADA ? self::CLAVE_LIMPIEZA_CERRADA : self::CLAVE_LIMPIEZA_MANTENCION;
        Database::execute(
            'INSERT INTO #__alertas_config (clave, valor, descripcion, updated_at, updated_by) VALUES (?, ?, ?, ?, ?) '
            . Database::onConflictUpdate(['clave'], ['valor', 'updated_at', 'updated_by']),
            [
                $clave,
                $prendido ? '1' : '0',
                'Al terminar un bloqueo de tipo ' . self::etiqueta($tipo) . ' en Cloudbeds, generar la limpieza completa (1) o no (0)',
                Database::now(),
                $usuarioId,
            ]
        );
        Logger::audit($usuarioId, 'cloudbeds.bloqueos_config', 'alertas_config', null, ['tipo' => $tipo, 'prendido' => $prendido]);
    }

    // ───────────────────────────── Ayudas ─────────────────────────────

    /**
     * Condición SQL «la pieza de $alias no está bloqueada en Cloudbeds», para ir con AND.
     * $alias es un identificador del código (p. ej. `h`), nunca un dato del usuario.
     */
    public static function sqlSinBloqueo(string $alias = 'h'): string
    {
        if (preg_match('/^[a-z_]+$/', $alias) !== 1) {
            throw new \InvalidArgumentException("Alias no válido: {$alias}");
        }
        return "{$alias}.cb_bloqueo_tipo IS NULL";
    }

    /** @return array{tipo: string, motivo: ?string, hasta: ?string}|null */
    public static function bloqueoActual(int $habitacionId): ?array
    {
        $fila = Database::fetchOne(
            'SELECT cb_bloqueo_tipo, cb_bloqueo_motivo, cb_bloqueo_hasta FROM #__habitaciones WHERE id = ?',
            [$habitacionId]
        );
        $tipo = $fila === null ? null : self::texto($fila['cb_bloqueo_tipo'] ?? null);
        if ($tipo === null) {
            return null;
        }
        return ['tipo' => $tipo, 'motivo' => self::texto($fila['cb_bloqueo_motivo'] ?? null), 'hasta' => self::texto($fila['cb_bloqueo_hasta'] ?? null)];
    }

    /** «mantención», «cerrada» o «bloqueada en Cloudbeds», para los textos. */
    public static function etiqueta(string $tipo): string
    {
        return self::ETIQUETAS[$tipo] ?? self::ETIQUETAS[self::TIPO_BLOQUEADA];
    }

    /** Para «la habitación está …»: «en mantención», «cerrada» o «bloqueada en Cloudbeds». */
    public static function comoEsta(string $tipo): string
    {
        return ($tipo === self::TIPO_MANTENCION ? 'en ' : '') . self::etiqueta($tipo);
    }

    public static function dedupe(int $habitacionId): string
    {
        return "bloqueo:{$habitacionId}";
    }

    public function resolverAlerta(int $habitacionId): void
    {
        $this->alertas->resolverPorDedupe(AlertaActiva::TIPO_HABITACION_SALIO_BLOQUEO, self::dedupe($habitacionId));
    }

    private static function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $t = trim((string) $valor);
        return $t === '' ? null : $t;
    }

    /** YYYY-MM-DD de lo que mande Cloudbeds (puede venir con hora), o null. */
    private static function fecha(mixed $valor): ?string
    {
        $t = self::texto($valor);
        return $t !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $t) === 1 ? substr($t, 0, 10) : null;
    }
}
