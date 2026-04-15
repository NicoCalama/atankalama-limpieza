<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\AlertaActiva;

/**
 * Algoritmo predictivo (docs/alertas-predictivas.md §4):
 *   tiempo_estimado_faltante = habitaciones_restantes × tiempo_promedio_personal
 *   EN RIESGO si: tiempo_estimado_faltante > (tiempo_restante_turno - margen_seguridad)
 *
 * Tipos manejados:
 *  - trabajador_en_riesgo (P1)
 *  - fin_turno_pendientes (P1)
 */
final class AlertasPredictivasService
{
    private const MIN_EJECUCIONES_HISTORIA = 5;

    public function __construct(
        private readonly AlertasService $alertas = new AlertasService(),
    ) {
    }

    /**
     * Recalcula alertas predictivas para todos los trabajadores con turno hoy.
     *
     * @return array{en_riesgo:int, fin_turno:int, resueltas:int, evaluados:int}
     */
    public function recalcularTodos(?string $fecha = null, ?string $horaActual = null): array
    {
        $fecha ??= date('Y-m-d');
        $horaActual ??= date('H:i');

        $trabajadores = Database::fetchAll(
            'SELECT ut.usuario_id, ut.turno_id, t.hora_inicio, t.hora_fin
               FROM usuarios_turnos ut
               JOIN turnos t ON t.id = ut.turno_id
              WHERE ut.fecha = ?',
            [$fecha]
        );

        $stats = ['en_riesgo' => 0, 'fin_turno' => 0, 'resueltas' => 0, 'evaluados' => count($trabajadores)];

        foreach ($trabajadores as $tr) {
            $resultado = $this->evaluarTrabajador(
                (int) $tr['usuario_id'],
                $fecha,
                (string) $tr['hora_fin'],
                $horaActual
            );
            $stats['en_riesgo'] += $resultado['en_riesgo_levantada'] ? 1 : 0;
            $stats['fin_turno'] += $resultado['fin_turno_levantada'] ? 1 : 0;
            $stats['resueltas'] += $resultado['resueltas'];
        }

        Logger::info('alertas_predictivas', 'recálculo completado', $stats + ['fecha' => $fecha]);
        return $stats;
    }

    /**
     * Evalúa un trabajador y levanta/resuelve sus alertas predictivas.
     *
     * @return array{en_riesgo_levantada:bool, fin_turno_levantada:bool, resueltas:int}
     */
    public function evaluarTrabajador(int $usuarioId, string $fecha, string $horaFinTurno, string $horaActual): array
    {
        $habitacionesRestantes = $this->contarHabitacionesRestantes($usuarioId, $fecha);
        $tiempoRestanteMin = $this->minutosEntre($horaActual, $horaFinTurno);
        $tiempoPromedio = $this->tiempoPromedioPersonal($usuarioId);
        $esEstimacionConservadora = false;
        if ($tiempoPromedio === null) {
            $tiempoPromedio = $this->alertas->obtenerConfigInt('tiempo_fallback_nueva_habitacion');
            $esEstimacionConservadora = true;
        }
        $margen = $this->alertas->obtenerConfigInt('margen_seguridad_minutos');
        $anticipoFinTurno = $this->alertas->obtenerConfigInt('fin_turno_anticipo_minutos');

        $tiempoEstimado = $habitacionesRestantes * $tiempoPromedio;
        $umbral = $tiempoRestanteMin - $margen;
        $enRiesgo = $habitacionesRestantes > 0 && $tiempoEstimado > $umbral;

        $nombre = $this->obtenerNombre($usuarioId);
        $hotelId = $this->hotelDelTrabajador($usuarioId);

        $stats = ['en_riesgo_levantada' => false, 'fin_turno_levantada' => false, 'resueltas' => 0];

        // trabajador_en_riesgo
        $dedupeRiesgo = "trabajador:{$usuarioId}:fecha:{$fecha}";
        if ($enRiesgo) {
            $this->alertas->levantar(
                AlertaActiva::TIPO_TRABAJADOR_EN_RIESGO,
                "{$nombre} podría no alcanzar a terminar",
                "Le quedan {$habitacionesRestantes} habitaciones (~{$tiempoEstimado} min) y su turno termina en {$tiempoRestanteMin} min.",
                [
                    'usuario_id' => $usuarioId,
                    'habitaciones_pendientes' => $habitacionesRestantes,
                    'tiempo_estimado_faltante' => $tiempoEstimado,
                    'tiempo_disponible' => $tiempoRestanteMin,
                    'margen_deficit' => $tiempoEstimado - $umbral,
                    'es_estimacion_conservadora' => $esEstimacionConservadora,
                ],
                $hotelId,
                $dedupeRiesgo,
            );
            $stats['en_riesgo_levantada'] = true;
        } else {
            $previa = $this->buscarActiva(AlertaActiva::TIPO_TRABAJADOR_EN_RIESGO, $dedupeRiesgo);
            if ($previa !== null) {
                $this->alertas->resolver($previa->id, 'auto');
                $stats['resueltas']++;
            }
        }

        // fin_turno_pendientes
        $dedupeFin = "trabajador:{$usuarioId}:fecha:{$fecha}";
        $finTurnoActivo = $habitacionesRestantes > 0
            && $tiempoRestanteMin > 0
            && $tiempoRestanteMin <= $anticipoFinTurno;
        if ($finTurnoActivo) {
            $this->alertas->levantar(
                AlertaActiva::TIPO_FIN_TURNO_PENDIENTES,
                'Fin de turno con pendientes',
                "{$nombre} termina turno en {$tiempoRestanteMin} min y aún tiene {$habitacionesRestantes} habitaciones.",
                [
                    'usuario_id' => $usuarioId,
                    'habitaciones_pendientes' => $habitacionesRestantes,
                    'minutos_restantes' => $tiempoRestanteMin,
                ],
                $hotelId,
                $dedupeFin,
            );
            $stats['fin_turno_levantada'] = true;
        } else {
            $previa = $this->buscarActiva(AlertaActiva::TIPO_FIN_TURNO_PENDIENTES, $dedupeFin);
            if ($previa !== null) {
                $this->alertas->resolver($previa->id, 'auto');
                $stats['resueltas']++;
            }
        }

        return $stats;
    }

    private function contarHabitacionesRestantes(int $usuarioId, string $fecha): int
    {
        $fila = Database::fetchOne(
            "SELECT COUNT(*) AS n
               FROM asignaciones a
               JOIN habitaciones h ON h.id = a.habitacion_id
              WHERE a.usuario_id = ? AND a.fecha = ? AND a.activa = 1
                AND h.estado IN ('sucia', 'en_progreso', 'rechazada')",
            [$usuarioId, $fecha]
        );
        return (int) ($fila['n'] ?? 0);
    }

    /**
     * Promedio de duración (minutos) de las últimas 20 ejecuciones del trabajador,
     * descartando outliers (> 2σ). null si tiene < 5.
     */
    public function tiempoPromedioPersonal(int $usuarioId): ?int
    {
        $filas = Database::fetchAll(
            "SELECT timestamp_inicio, timestamp_fin
               FROM ejecuciones_checklist
              WHERE usuario_id = ?
                AND estado IN ('completada', 'auditada')
                AND timestamp_fin IS NOT NULL
              ORDER BY id DESC LIMIT 20",
            [$usuarioId]
        );
        if (count($filas) < self::MIN_EJECUCIONES_HISTORIA) {
            return null;
        }
        $duraciones = [];
        foreach ($filas as $f) {
            $ini = strtotime((string) $f['timestamp_inicio']);
            $fin = strtotime((string) $f['timestamp_fin']);
            if ($ini > 0 && $fin > $ini) {
                $duraciones[] = ($fin - $ini) / 60.0;
            }
        }
        if (count($duraciones) < self::MIN_EJECUCIONES_HISTORIA) {
            return null;
        }
        $media = array_sum($duraciones) / count($duraciones);
        $varianza = 0.0;
        foreach ($duraciones as $d) {
            $varianza += ($d - $media) ** 2;
        }
        $varianza /= count($duraciones);
        $sigma = sqrt($varianza);
        $filtradas = array_filter($duraciones, static fn(float $d) => abs($d - $media) <= 2 * $sigma);
        if ($filtradas === []) {
            $filtradas = $duraciones;
        }
        $promedio = array_sum($filtradas) / count($filtradas);
        return (int) round($promedio);
    }

    private function minutosEntre(string $horaA, string $horaB): int
    {
        $a = $this->horaAMinutos($horaA);
        $b = $this->horaAMinutos($horaB);
        return $b - $a;
    }

    private function horaAMinutos(string $hora): int
    {
        [$h, $m] = array_pad(explode(':', $hora), 2, '0');
        return ((int) $h) * 60 + (int) $m;
    }

    private function obtenerNombre(int $usuarioId): string
    {
        $fila = Database::fetchOne('SELECT nombre FROM usuarios WHERE id = ?', [$usuarioId]);
        return (string) ($fila['nombre'] ?? "Usuario {$usuarioId}");
    }

    private function hotelDelTrabajador(int $usuarioId): ?int
    {
        $fila = Database::fetchOne('SELECT hotel_default FROM usuarios WHERE id = ?', [$usuarioId]);
        if ($fila === null) {
            return null;
        }
        $codigo = $fila['hotel_default'] ?? null;
        if ($codigo === null || $codigo === 'ambos') {
            return null;
        }
        $h = Database::fetchOne('SELECT id FROM hoteles WHERE codigo = ?', [$codigo]);
        return $h === null ? null : (int) $h['id'];
    }

    private function buscarActiva(string $tipo, string $dedupeKey): ?AlertaActiva
    {
        $needle = '"_dedupe":"' . $dedupeKey . '"';
        $fila = Database::fetchOne(
            'SELECT * FROM alertas_activas WHERE tipo = ? AND contexto_json LIKE ? LIMIT 1',
            [$tipo, '%' . $needle . '%']
        );
        return $fila === null ? null : AlertaActiva::desdeFila($fila);
    }
}
