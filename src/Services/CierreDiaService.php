<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Helpers\Fechas;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Models\Habitacion;

/**
 * Cierre de día automático: aprueba como 'aprobado_automatico' (usuario «Sistema») las piezas
 * que quedaron completada_pendiente_auditoria sin que nadie las inspeccionara, y empuja 'clean'
 * a Cloudbeds igual que una aprobación real.
 *
 * En producción corre a las 23:55 y también a las 15:50 (cron duplicado; jefatura confirmó el
 * 01/10/2026 que el de las 15:50 SE QUEDA: cierra la mañana antes de que el barrido de nocheros
 * de las 16:00 los devuelva a sucia para el turno de tarde). Lo llama
 * scripts/aprobar-pendientes-cierre-dia.php; vive acá para que los tests ejerciten el mismo
 * código que corre en producción.
 *
 * En la pasada de la noche, antes de aprobar, termina toda limpieza que siga sin terminar
 * (cerrarSinTerminar, v6.19) y después avisa a las supervisoras (avisarCerradasSinTerminar,
 * avisarAreasRechazadas).
 */
final class CierreDiaService
{
    public const COMENTARIO = 'Auto-aprobada por cierre de día (23:55) — sin auditoría real. Ver docs de la habitación.';

    /** Comentario de la aprobación de una pieza cuya limpieza terminó el sistema (v6.19). */
    public const COMENTARIO_SIN_TERMINAR = 'Sin terminar: nadie apretó «terminar» y la cerró el sistema en el cierre de la noche (23:55). No suma créditos.';

    /** Tipos de notificación (bandeja) del cierre de la noche. */
    public const NOTIF_CERRADAS_SIN_TERMINAR = 'cerradas_sin_terminar';
    public const NOTIF_AREAS_RECHAZADAS = 'areas_rechazadas_sin_resolver';

    /**
     * Permiso de quienes reciben los avisos del cierre de la noche: los que piden la limpieza de
     * las áreas (Supervisora y Admin por defecto).
     */
    public const PERMISO_AVISOS = 'espacios.pedir_limpieza';

    /** Tope de piezas que se nombran en el aviso de las limpiezas sin terminar; el resto se cuenta. */
    private const MAX_PIEZAS_EN_AVISO = 15;

    /**
     * Desde esta hora una corrida del cron es la del cierre de la noche (23:55). La de las 15:50
     * aprueba las pendientes, pero no termina las limpiezas en curso: a esa hora se está trabajando.
     */
    public const HORA_PASADA_NOCTURNA = 20;

    public function __construct(
        private readonly AuditoriaService $auditorias = new AuditoriaService(),
        private readonly PushService $push = new PushService(),
        private readonly HabitacionService $habitaciones = new HabitacionService(),
    ) {
    }

    /**
     * Piezas que el cierre aprobaría (incluye áreas comunes: bandejaPendientes(), no listar()).
     *
     * @return list<array<string, mixed>>
     */
    public function pendientes(): array
    {
        return $this->auditorias->bandejaPendientes();
    }

    /**
     * @param list<array<string, mixed>>|null $pendientes las de pendientes(); null = las busca
     * @param list<int> $sinTerminar piezas cuya limpieza terminó el sistema esta noche: su
     *                               aprobación lleva COMENTARIO_SIN_TERMINAR
     * @return array{aprobadas: int, fallidas: int}
     */
    public function aprobarPendientes(int $sistemaId, ?array $pendientes = null, array $sinTerminar = []): array
    {
        $sinTerminar = array_flip($sinTerminar);
        $aprobadas = 0;
        $fallidas  = 0;
        foreach ($pendientes ?? $this->pendientes() as $fila) {
            try {
                $this->auditorias->emitirVeredicto(
                    (int) $fila['id'],
                    $sistemaId,
                    Auditoria::VEREDICTO_APROBADO_AUTOMATICO,
                    isset($sinTerminar[(int) $fila['id']]) ? self::COMENTARIO_SIN_TERMINAR : self::COMENTARIO,
                );
                $aprobadas++;
            } catch (\Throwable $e) {
                // Cualquier error de UNA pieza (no solo AuditoriaException: un choque con una
                // auditora que la inspeccionaba en ese instante, un fallo de BD) se registra y se
                // sigue con las demás. Antes cortaba el loop y el resto quedaba sin cerrar.
                $fallidas++;
                Logger::error('auditoria', 'cierre de día automático: fallo al auto-aprobar', [
                    'habitacion_id' => $fila['id'],
                    'numero' => $fila['numero'],
                    'codigo' => $e instanceof AuditoriaException ? $e->codigo : get_class($e),
                    'mensaje' => $e->getMessage(),
                ]);
            }
        }
        return ['aprobadas' => $aprobadas, 'fallidas' => $fallidas];
    }

    /** ¿Esta corrida del cron es la de la noche? $hora en 0–23, hora de Santiago (default: ahora). */
    public static function esPasadaNocturna(?int $hora = null): bool
    {
        return ($hora ?? (int) date('G')) >= self::HORA_PASADA_NOCTURNA;
    }

    /**
     * Limpiezas que siguen sin terminar (nadie apretó «terminar»), de cualquier día, con su pieza.
     *
     * @return list<array{ejecucion_id: int, habitacion_id: int, numero: string, hotel: string, es_espacio_comun: bool, estado_habitacion: string, usuario_id: int, usuario: string}>
     */
    public function limpiezasSinTerminar(): array
    {
        $filas = Database::fetchAll(
            "SELECT ec.id AS ejecucion_id, ec.habitacion_id, ec.usuario_id, u.nombre AS usuario,
                    h.numero, h.es_espacio_comun, h.estado AS estado_habitacion, ho.nombre AS hotel
               FROM #__ejecuciones_checklist ec
               JOIN #__habitaciones h ON h.id = ec.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
               JOIN #__usuarios u ON u.id = ec.usuario_id
              WHERE ec.estado = 'en_progreso'
              ORDER BY ho.nombre, h.numero, ec.id"
        );
        return array_map(static fn(array $f): array => [
            'ejecucion_id'      => (int) $f['ejecucion_id'],
            'habitacion_id'     => (int) $f['habitacion_id'],
            'numero'            => (string) $f['numero'],
            'hotel'             => (string) $f['hotel'],
            'es_espacio_comun'  => (int) $f['es_espacio_comun'] === 1,
            'estado_habitacion' => (string) $f['estado_habitacion'],
            'usuario_id'        => (int) $f['usuario_id'],
            'usuario'           => (string) $f['usuario'],
        ], $filas);
    }

    /**
     * Termina toda limpieza que siga sin terminar al cierre de la noche, de habitaciones y de áreas
     * comunes (v6.19, decisión de Nicolás del 08/10/2026). La pieza tiene que quedar libre para el
     * día siguiente, así que pasa a por inspeccionar y el mismo cierre la aprueba; pero quien no
     * apretó «terminar» no recibe créditos de ella: la limpieza queda con cerrada_por_sistema = 1,
     * que la saca de los créditos, las piezas hechas y los tiempos (ReportesService). A las 23:55
     * no hay nadie trabajando: los turnos van de 08:00 a 22:00 y ninguno cruza la medianoche.
     *
     * Antes, una limpieza sin terminar no la aprobaba ningún cierre: el área se arrastraba cada
     * noche a la misma persona (v6.18) y la habitación quedaba en progreso sin nadie.
     *
     * Una limpieza colgada de una pieza que ya siguió su ciclo (la volvió a limpiar otra persona,
     * la marcaron sucia, Cloudbeds la dio por limpia) solo se cierra: la pieza no se toca.
     *
     * @return list<array{habitacion_id: int, numero: string, hotel: string, es_espacio_comun: bool, usuarios: list<string>}>
     *         piezas que pasaron a por inspeccionar
     */
    public function cerrarSinTerminar(): array
    {
        $porPieza = [];
        foreach ($this->limpiezasSinTerminar() as $l) {
            $porPieza[$l['habitacion_id']][] = $l;
        }

        $piezas = [];
        foreach ($porPieza as $habitacionId => $limpiezas) {
            try {
                $pasoAInspeccion = Database::transaction(function () use ($habitacionId, $limpiezas): bool {
                    $ahora = Database::now();
                    foreach ($limpiezas as $l) {
                        Database::execute(
                            "UPDATE #__ejecuciones_checklist
                                SET estado = 'completada', timestamp_fin = ?, cerrada_por_sistema = 1
                              WHERE id = ? AND estado = 'en_progreso'",
                            [$ahora, $l['ejecucion_id']]
                        );
                    }
                    $estado = Database::fetchColumn(
                        'SELECT estado FROM #__habitaciones WHERE id = ?' . Database::forUpdate(),
                        [$habitacionId]
                    );
                    if ($estado !== Habitacion::ESTADO_EN_PROGRESO) {
                        return false;
                    }
                    $this->habitaciones->cambiarEstado($habitacionId, Habitacion::ESTADO_COMPLETADA_PENDIENTE_AUDITORIA, null, 'script');
                    return true;
                });
            } catch (\Throwable $e) {
                // Una pieza que falla no frena a las demás (mismo criterio que aprobarPendientes).
                Logger::error('checklist', 'cierre de la noche: fallo al terminar una limpieza sin terminar', [
                    'habitacion_id' => $habitacionId, 'mensaje' => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($limpiezas as $l) {
                Logger::audit(null, 'checklist.cerrar_sin_terminar', 'ejecucion_checklist', $l['ejecucion_id'], [
                    'habitacion_id' => $habitacionId,
                    'usuario_id' => $l['usuario_id'],
                    'pieza_a_inspeccion' => $pasoAInspeccion,
                    'motivo' => 'nadie apretó «terminar»: la cerró el cierre de la noche, sin créditos',
                ], 'script');
            }
            if ($pasoAInspeccion) {
                $piezas[] = [
                    'habitacion_id'    => $habitacionId,
                    'numero'           => $limpiezas[0]['numero'],
                    'hotel'            => $limpiezas[0]['hotel'],
                    'es_espacio_comun' => $limpiezas[0]['es_espacio_comun'],
                    'usuarios'         => array_values(array_unique(array_column($limpiezas, 'usuario'))),
                ];
            }
        }

        Logger::info('checklist', 'cierre de la noche: limpiezas sin terminar', [
            'limpiezas' => array_sum(array_map('count', $porPieza)), 'piezas_a_inspeccion' => count($piezas),
        ]);
        return $piezas;
    }

    /**
     * Campanita a las supervisoras con las piezas que el sistema terminó esta noche y de quién eran.
     * Una sola por noche, con todas.
     *
     * @param list<array{numero: string, hotel: string, es_espacio_comun: bool, usuarios: list<string>}> $piezas
     */
    public function avisarCerradasSinTerminar(array $piezas, string $hoy): void
    {
        $destinatarios = $this->destinatariosAvisos();
        if ($piezas === [] || $destinatarios === []) {
            return;
        }

        $partes = [];
        foreach (array_slice($piezas, 0, self::MAX_PIEZAS_EN_AVISO) as $p) {
            $partes[] = "{$p['numero']} ({$p['hotel']}), de " . implode(' y ', $p['usuarios']);
        }
        $n = count($piezas);
        if ($n > self::MAX_PIEZAS_EN_AVISO) {
            $partes[] = 'y ' . ($n - self::MAX_PIEZAS_EN_AVISO) . ' más';
        }
        $titulo = $n === 1 ? 'Limpieza sin terminar al cerrar el día' : "{$n} limpiezas sin terminar al cerrar el día";
        $cuerpo = 'Nadie apretó «terminar» el ' . self::fechaCorta($hoy) . ': ' . implode(' · ', $partes) . '. '
            . ($n === 1
                ? 'La aprobó el sistema para dejarla libre mañana, y no suma créditos.'
                : 'Las aprobó el sistema para dejarlas libres mañana, y no suman créditos.');
        $soloAreas = array_filter($piezas, static fn(array $p): bool => !$p['es_espacio_comun']) === [];

        $this->push->notificar($destinatarios, $titulo, $cuerpo, $soloAreas ? '/espacios' : '/habitaciones', [], false, self::NOTIF_CERRADAS_SIN_TERMINAR);
    }

    /**
     * Áreas comunes rechazadas que nadie volvió a pedir: reasignarla la deja en 'sucia', así que
     * toda área que sigue 'rechazada' al cierre no se volvió a limpiar. Con la fecha local del
     * último rechazo.
     *
     * @return list<array{habitacion_id: int, numero: string, hotel: string, rechazada_el: ?string}>
     */
    public function areasRechazadas(): array
    {
        $filas = Database::fetchAll(
            "SELECT h.id AS habitacion_id, h.numero, ho.nombre AS hotel,
                    (SELECT MAX(au.created_at) FROM #__auditorias au
                      WHERE au.habitacion_id = h.id AND au.veredicto = 'rechazado') AS rechazada_at
               FROM #__habitaciones h
               JOIN #__hoteles ho ON ho.id = h.hotel_id
              WHERE h.es_espacio_comun = 1 AND h.activa = 1 AND h.estado = 'rechazada'
              ORDER BY ho.nombre, h.numero"
        );
        return array_map(static fn(array $f): array => [
            'habitacion_id' => (int) $f['habitacion_id'],
            'numero'        => (string) $f['numero'],
            'hotel'         => (string) $f['hotel'],
            'rechazada_el'  => $f['rechazada_at'] === null ? null : Fechas::fechaLocalDeUtc((string) $f['rechazada_at']),
        ], $filas);
    }

    /**
     * Áreas rechazadas sin resolver (pasada de la noche, v6.18): siguen rechazadas (la pantalla
     * mantiene su franja) y se avisa que no se volvieron a limpiar. Una vez por persona y por día;
     * se repite cada noche mientras sigan así.
     *
     * @return int cuántas áreas siguen rechazadas
     */
    public function avisarAreasRechazadas(string $hoy): int
    {
        $areas = $this->areasRechazadas();
        if ($areas === []) {
            return 0;
        }
        // Una vez por persona y por día: una segunda corrida a mano la misma noche no repite.
        [$desde, $hasta] = Fechas::rangoUtcDelDia($hoy);
        $destinatarios = $this->destinatariosAvisos(self::NOTIF_AREAS_RECHAZADAS, $desde, $hasta);
        if ($destinatarios === []) {
            return count($areas);
        }

        $partes = [];
        foreach ($areas as $a) {
            $partes[] = "{$a['numero']} ({$a['hotel']})"
                . ($a['rechazada_el'] !== null ? ', rechazada el ' . self::fechaCorta($a['rechazada_el']) : '');
        }
        $n = count($areas);
        $titulo = $n === 1 ? 'Área rechazada sin resolver' : "{$n} áreas rechazadas sin resolver";
        $cuerpo = implode(' · ', $partes) . '. Al cierre del ' . self::fechaCorta($hoy)
            . ($n === 1 ? ' seguía sin volver a limpiarse' : ' seguían sin volver a limpiarse')
            . '. Pide de nuevo su limpieza en Áreas comunes.';

        $this->push->notificar($destinatarios, $titulo, $cuerpo, '/espacios', [], false, self::NOTIF_AREAS_RECHAZADAS);
        return $n;
    }

    /**
     * Personas activas con PERMISO_AVISOS, sin el usuario «Sistema». Con $tipo, sin las que ya
     * recibieron ese aviso entre $desde y $hasta (UTC).
     *
     * @return list<int>
     */
    private function destinatariosAvisos(?string $tipo = null, ?string $desde = null, ?string $hasta = null): array
    {
        $sql = "SELECT DISTINCT u.id
                  FROM #__usuarios u
                  JOIN #__usuarios_roles ur ON ur.usuario_id = u.id
                  JOIN #__rol_permisos rp ON rp.rol_id = ur.rol_id
                 WHERE rp.permiso_codigo = ? AND u.activo = 1 AND u.rut <> 'SISTEMA-CRON'";
        $params = [self::PERMISO_AVISOS];
        if ($tipo !== null) {
            $sql .= ' AND NOT EXISTS (SELECT 1 FROM #__notificaciones n
                                       WHERE n.usuario_id = u.id AND n.tipo = ?
                                         AND n.created_at >= ? AND n.created_at < ?)';
            array_push($params, $tipo, $desde, $hasta);
        }
        return array_map('intval', array_column(Database::fetchAll($sql, $params), 'id'));
    }

    /** 'YYYY-MM-DD' → 'DD/MM'. */
    private static function fechaCorta(string $fecha): string
    {
        return date('d/m', (int) strtotime($fecha));
    }
}
