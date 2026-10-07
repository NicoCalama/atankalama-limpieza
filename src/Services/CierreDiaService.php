<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Helpers\Fechas;
use Atankalama\Limpieza\Models\Auditoria;

/**
 * Cierre de día automático: aprueba como 'aprobado_automatico' (usuario «Sistema») las piezas
 * que quedaron completada_pendiente_auditoria sin que nadie las inspeccionara, y empuja 'clean'
 * a Cloudbeds igual que una aprobación real. No toca piezas sucias ni en curso.
 *
 * En producción corre a las 23:55 y también a las 15:50 (cron duplicado; jefatura confirmó el
 * 01/10/2026 que el de las 15:50 SE QUEDA: cierra la mañana antes de que el barrido de nocheros
 * de las 16:00 los devuelva a sucia para el turno de tarde). Lo llama
 * scripts/aprobar-pendientes-cierre-dia.php; vive acá para que los tests ejerciten el mismo
 * código que corre en producción.
 *
 * En la pasada de la noche cierra además el día de las áreas comunes (cerrarAreasComunes).
 */
final class CierreDiaService
{
    public const COMENTARIO = 'Auto-aprobada por cierre de día (23:55) — sin auditoría real. Ver docs de la habitación.';

    /** Tipos de notificación (bandeja) del cierre de las áreas comunes. */
    public const NOTIF_AREAS_EN_PROGRESO = 'areas_en_progreso_cierre';
    public const NOTIF_AREAS_RECHAZADAS = 'areas_rechazadas_sin_resolver';

    /** Permiso de quienes reciben los avisos del cierre de áreas: los que piden su limpieza. */
    public const PERMISO_AVISOS_AREAS = 'espacios.pedir_limpieza';

    /**
     * Desde esta hora una corrida del cron es la del cierre de la noche (23:55). La de las 15:50
     * aprueba las pendientes, pero no cierra el día de las áreas.
     */
    public const HORA_PASADA_NOCTURNA = 20;

    public function __construct(
        private readonly AuditoriaService $auditorias = new AuditoriaService(),
        private readonly PushService $push = new PushService(),
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
     * @return array{aprobadas: int, fallidas: int}
     */
    public function aprobarPendientes(int $sistemaId, ?array $pendientes = null): array
    {
        $aprobadas = 0;
        $fallidas  = 0;
        foreach ($pendientes ?? $this->pendientes() as $fila) {
            try {
                $this->auditorias->emitirVeredicto(
                    (int) $fila['id'],
                    $sistemaId,
                    Auditoria::VEREDICTO_APROBADO_AUTOMATICO,
                    self::COMENTARIO,
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
     * Áreas comunes que quedan en progreso al cerrar $hoy: tienen una limpieza en curso que cuelga
     * de su asignación activa de ese día.
     *
     * @return list<array{asignacion_id: int, habitacion_id: int, numero: string, hotel: string, usuario_id: int, usuario: string}>
     */
    public function areasEnProgreso(string $hoy): array
    {
        $filas = Database::fetchAll(
            "SELECT DISTINCT a.id AS asignacion_id, h.id AS habitacion_id, h.numero, ho.nombre AS hotel,
                    a.usuario_id, u.nombre AS usuario
               FROM #__asignaciones a
               JOIN #__habitaciones h ON h.id = a.habitacion_id
               JOIN #__hoteles ho ON ho.id = h.hotel_id
               JOIN #__usuarios u ON u.id = a.usuario_id
               JOIN #__ejecuciones_checklist ec ON ec.asignacion_id = a.id AND ec.estado = 'en_progreso'
              WHERE h.es_espacio_comun = 1 AND h.activa = 1 AND h.estado = 'en_progreso'
                AND a.fecha = ? AND a.activa = 1
              ORDER BY ho.nombre, h.numero",
            [$hoy]
        );
        return array_map(static fn(array $f): array => [
            'asignacion_id' => (int) $f['asignacion_id'],
            'habitacion_id' => (int) $f['habitacion_id'],
            'numero'        => (string) $f['numero'],
            'hotel'         => (string) $f['hotel'],
            'usuario_id'    => (int) $f['usuario_id'],
            'usuario'       => (string) $f['usuario'],
        ], $filas);
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
     * Cierre del día de las áreas comunes (pasada de la noche, decisión de Nicolás del 07/10/2026).
     * Las asignaciones son por día, así que un área aprobada o pendiente queda sin asignar al día
     * siguiente sin que haya que tocarla (la pantalla de Áreas comunes la muestra sin franja).
     * Acá se resuelven los dos casos que no pueden quedar así:
     *
     *  - En progreso: la asignación pasa a $manana con la misma persona y primera en su cola. La
     *    limpieza en curso sigue colgando de ella, así que conserva lo que ya marcó; sin esto
     *    quedaba en progreso en la cola de nadie y, si se volvía a pedir, partía de cero. Si el
     *    área ya estaba preasignada para mañana, esa preasignación se cancela: gana la limpieza
     *    empezada. Se avisa a quienes piden la limpieza de las áreas.
     *  - Rechazada: sigue rechazada (la pantalla mantiene su franja) y se avisa que no se volvió
     *    a limpiar. Una vez por persona y por día; se repite cada noche mientras siga así.
     *
     * Las habitaciones de huésped no se tocan: su ciclo lo manda Cloudbeds.
     *
     * @return array{arrastradas: int, rechazadas: int}
     */
    public function cerrarAreasComunes(string $hoy, string $manana): array
    {
        $arrastradas = [];
        foreach ($this->areasEnProgreso($hoy) as $area) {
            $canceladas = Database::transaction(function () use ($area, $manana): array {
                $preasignadas = Database::fetchAll(
                    'SELECT a.id, u.nombre
                       FROM #__asignaciones a
                       JOIN #__usuarios u ON u.id = a.usuario_id
                      WHERE a.habitacion_id = ? AND a.fecha = ? AND a.activa = 1',
                    [$area['habitacion_id'], $manana]
                );
                foreach ($preasignadas as $p) {
                    Database::execute('UPDATE #__asignaciones SET activa = 0 WHERE id = ?', [(int) $p['id']]);
                }
                // orden_cola 0: las colas empiezan en 1 (siguienteOrdenCola), así que queda primera.
                Database::execute(
                    'UPDATE #__asignaciones SET fecha = ?, orden_cola = 0 WHERE id = ?',
                    [$manana, $area['asignacion_id']]
                );
                return $preasignadas;
            });
            Logger::audit(null, 'asignacion.arrastrada_cierre_dia', 'asignacion', $area['asignacion_id'], [
                'habitacion_id' => $area['habitacion_id'], 'usuario_id' => $area['usuario_id'],
                'desde' => $hoy, 'hasta' => $manana,
                'preasignaciones_canceladas' => array_map(static fn(array $p): int => (int) $p['id'], $canceladas),
                'motivo' => 'área común en progreso al cierre del día: sigue en la cola de la misma persona',
            ], 'script');
            $arrastradas[] = $area + ['canceladas' => array_column($canceladas, 'nombre')];
        }

        $destinatarios = $this->destinatariosAvisosAreas();
        if ($arrastradas !== [] && $destinatarios !== []) {
            $this->avisarAreasEnProgreso($destinatarios, $arrastradas, $hoy, $manana);
        }

        $rechazadas = $this->areasRechazadas();
        if ($rechazadas !== []) {
            $this->avisarAreasRechazadas($rechazadas, $hoy);
        }

        Logger::info('espacios', 'cierre del día de las áreas comunes', [
            'fecha' => $hoy, 'arrastradas' => count($arrastradas), 'rechazadas' => count($rechazadas),
        ]);
        return ['arrastradas' => count($arrastradas), 'rechazadas' => count($rechazadas)];
    }

    /**
     * @param list<int> $destinatarios
     * @param list<array{numero: string, hotel: string, usuario: string, canceladas: list<string>}> $areas
     */
    private function avisarAreasEnProgreso(array $destinatarios, array $areas, string $hoy, string $manana): void
    {
        $partes = [];
        foreach ($areas as $a) {
            $parte = "{$a['numero']} ({$a['hotel']}) con {$a['usuario']}";
            if ($a['canceladas'] !== []) {
                $parte .= '; se canceló la preasignación de mañana a ' . implode(', ', $a['canceladas']);
            }
            $partes[] = $parte;
        }
        $n = count($areas);
        $titulo = $n === 1 ? 'Área en progreso al cerrar el día' : "{$n} áreas en progreso al cerrar el día";
        $cuerpo = ($n === 1 ? 'Quedó en progreso al cierre del ' : 'Quedaron en progreso al cierre del ')
            . self::fechaCorta($hoy) . ': ' . implode(' · ', $partes) . '. '
            . ($n === 1
                ? 'Sigue primera en su cola del ' . self::fechaCorta($manana) . ', con lo que ya había marcado.'
                : 'Siguen primeras en sus colas del ' . self::fechaCorta($manana) . ', con lo que ya habían marcado.');

        $this->push->notificar($destinatarios, $titulo, $cuerpo, '/espacios', [], false, self::NOTIF_AREAS_EN_PROGRESO);
    }

    /** @param list<array{numero: string, hotel: string, rechazada_el: ?string}> $areas */
    private function avisarAreasRechazadas(array $areas, string $hoy): void
    {
        // Una vez por persona y por día: una segunda corrida a mano la misma noche no repite.
        [$desde, $hasta] = Fechas::rangoUtcDelDia($hoy);
        $destinatarios = $this->destinatariosAvisosAreas(self::NOTIF_AREAS_RECHAZADAS, $desde, $hasta);
        if ($destinatarios === []) {
            return;
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
    }

    /**
     * Personas activas con el permiso de pedir la limpieza de las áreas (Supervisora y Admin por
     * defecto). Con $tipo, sin las que ya recibieron ese aviso entre $desde y $hasta (UTC).
     *
     * @return list<int>
     */
    private function destinatariosAvisosAreas(?string $tipo = null, ?string $desde = null, ?string $hasta = null): array
    {
        $sql = "SELECT DISTINCT u.id
                  FROM #__usuarios u
                  JOIN #__usuarios_roles ur ON ur.usuario_id = u.id
                  JOIN #__rol_permisos rp ON rp.rol_id = ur.rol_id
                 WHERE rp.permiso_codigo = ? AND u.activo = 1 AND u.rut <> 'SISTEMA-CRON'";
        $params = [self::PERMISO_AVISOS_AREAS];
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
