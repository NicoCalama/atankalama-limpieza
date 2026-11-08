<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Helpers\HorasTurno;

/**
 * «Cambio Tarea» (v7.16, pedido de gerencia del 06/11/2026; plan aprobado por Nicolás el 07/11/2026).
 *
 * En noviembre mucha gente de Aseo fue a apoyar a otras áreas: dejó de hacer piezas, le bajaron las
 * piezas por día y con eso el bono. La supervisora anota acá, para cada persona de Aseo, cuántas horas
 * estuvo HOY en otra área (Recepción, Lavandería…). En la v7.18 esas horas se descuentan de sus horas
 * en Aseo y el bono pasa a medirse por hora. Ver docs/cambio-tarea.md.
 *
 * Reglas (decisiones de Nicolás, 07/11/2026):
 *  - Solo el MISMO día («que no procrastinen»): guardar otra fecha responde 409. Ver otros días queda
 *    programado detrás del interruptor CAMBIO_TAREA_NAVEGAR_DIAS (apagado), y aun prendido es solo lectura.
 *  - Sale el personal de Aseo del día: la misma pestaña «Aseo» de Asignaciones (sin la marca
 *    asignaciones.excluir_auto, con turno o con piezas ese día), sin quien está fuera del bono
 *    (kpis.excluido). También sale quien ya tiene horas anotadas ese día, para que nada quede escondido.
 *  - Las áreas salen de la lista «area_apoyo» (Ajustes → Checklists y listas); varias por persona y día.
 *  - Las horas van de media en media hora (minutos de 30 en 30), hasta 12 h por área y 16 h en el día.
 *    Pueden pasar del turno: si se quedó después de hora apoyando en otra área, esas horas también se
 *    anotan (el Admin las suma como extras del mes en la v7.17 y en la v7.18 se compensan).
 *  - Las horas extras NO se anotan acá: las carga el Admin como total del mes (v7.17).
 */
final class CambioTareaService
{
    public const MINUTOS_PASO = 30;
    public const MINUTOS_MAX_AREA = 720;
    public const MINUTOS_MAX_DIA = 960;
    public const MAX_AREAS = 6;

    public function __construct(
        private readonly PersonalAsignableService $personal = new PersonalAsignableService(),
        private readonly ListasOpcionesService $listas = new ListasOpcionesService(),
        private readonly UsuarioService $usuarios = new UsuarioService(),
    ) {
    }

    /** El día de hoy en hora de Chile (APP_TIMEZONE). */
    public static function hoy(): string
    {
        return date('Y-m-d');
    }

    /**
     * Personal de Aseo del día con su turno, sus piezas y sus horas en otras áreas.
     *
     * @return list<array{
     *     id: int, nombre: string, hotel_default: ?string, jornada: ?string,
     *     turno: ?array{id: int, nombre: string, hora_inicio: string, hora_fin: string, minutos_netos: int},
     *     piezas_asignadas: int, piezas_hechas: int, areas_comunes_hechas: int,
     *     otras_areas: list<array{area_id: int, nombre: string, minutos: int}>
     * }>
     */
    public function listar(string $fecha, ?string $hotel = null): array
    {
        $this->exigirFecha($fecha);
        $excluidos = RbacService::usuariosConPermiso(RbacService::PERMISO_EXCLUIDO_KPIS);

        $personas = [];
        foreach ($this->personal->listar($fecha, $hotel) as $p) {
            if ($p['grupo'] === PersonalAsignableService::GRUPO_ASEO && !isset($excluidos[$p['id']])) {
                $personas[$p['id']] = ['id' => $p['id'], 'nombre' => $p['nombre'], 'hotel_default' => $p['hotel_default']];
            }
        }

        // Quien ya tiene horas anotadas ese día sale siempre (p. ej. le borraron el turno después).
        $params = [$fecha];
        $sql = 'SELECT DISTINCT u.id, u.nombre, u.hotel_default
                  FROM #__horas_otras_areas hoa
                  JOIN #__usuarios u ON u.id = hoa.usuario_id
                 WHERE hoa.fecha = ?';
        if ($hotel !== null && $hotel !== 'ambos') {
            $sql .= " AND (u.hotel_default = ? OR u.hotel_default = 'ambos')";
            $params[] = $hotel;
        }
        foreach (Database::fetchAll($sql, $params) as $f) {
            $id = (int) $f['id'];
            $personas[$id] ??= [
                'id' => $id,
                'nombre' => (string) $f['nombre'],
                'hotel_default' => $f['hotel_default'] !== null ? (string) $f['hotel_default'] : null,
            ];
        }
        if ($personas === []) {
            return [];
        }

        $ids = array_keys($personas);
        $turnos = $this->turnos($ids, $fecha);
        $piezas = $this->piezas($ids, $fecha);
        $otras = $this->otrasAreas($ids, $fecha);

        $salida = [];
        foreach ($personas as $id => $p) {
            $jornada = $turnos['jornadas'][$id] ?? null;
            $salida[] = $p + [
                'jornada' => $jornada,
                'turno' => $turnos['turnos'][$id] ?? null,
                'piezas_asignadas' => $piezas[$id]['asignadas'] ?? 0,
                'piezas_hechas' => $piezas[$id]['hechas'] ?? 0,
                'areas_comunes_hechas' => $piezas[$id]['areas'] ?? 0,
                'otras_areas' => $otras[$id] ?? [],
            ];
        }
        usort($salida, static fn(array $a, array $b): int => strcmp(mb_strtolower($a['nombre']), mb_strtolower($b['nombre'])));
        return $salida;
    }

    /**
     * Deja las horas en otras áreas de una persona para HOY tal como vienen en $filas (el juego completo:
     * lo que no viene se borra). Devuelve cómo quedaron.
     *
     * @param list<array{area_id: mixed, minutos: mixed}> $filas
     * @return list<array{area_id: int, nombre: string, minutos: int}>
     */
    public function guardar(int $usuarioId, string $fecha, array $filas, int $actorId): array
    {
        $this->exigirFecha($fecha);
        if ($fecha !== self::hoy()) {
            throw new CambioTareaException(
                'SOLO_MISMO_DIA',
                'Las horas en otra área solo se anotan el mismo día. Recarga la página para ver el día de hoy.',
                409
            );
        }

        $persona = $this->usuarios->buscarPorId($usuarioId);
        if ($persona === null || !$persona->activo) {
            throw new CambioTareaException('PERSONA_NO_ENCONTRADA', 'No encontramos a esa persona.', 404);
        }
        if ($persona->tienePermiso(RbacService::PERMISO_EXCLUIDO_KPIS)
            || $persona->tienePermiso(RbacService::PERMISO_EXCLUIDO_AUTO_ASIGNAR)) {
            throw new CambioTareaException('NO_ES_DE_ASEO', 'Aquí solo se anotan horas del personal de Aseo.', 400);
        }

        $nuevas = $this->normalizar($filas);

        $actuales = [];
        foreach (Database::fetchAll(
            'SELECT id, area_id, minutos FROM #__horas_otras_areas WHERE usuario_id = ? AND fecha = ?',
            [$usuarioId, $fecha]
        ) as $f) {
            $actuales[(int) $f['area_id']] = ['id' => (int) $f['id'], 'minutos' => (int) $f['minutos']];
        }

        // Cada área tiene que ser de la lista. Una desactivada en Ajustes solo vale si ya estaba anotada:
        // así una opción apagada a mitad del día no le impide a la supervisora corregir las horas.
        $opciones = [];
        foreach ($this->listas->listar(ListasOpcionesService::AREA_APOYO, false) as $o) {
            $opciones[$o['id']] = $o;
        }
        foreach (array_keys($nuevas) as $areaId) {
            $opcion = $opciones[$areaId] ?? null;
            if ($opcion === null || (!$opcion['activo'] && !isset($actuales[$areaId]))) {
                throw new CambioTareaException(
                    'AREA_INVALIDA',
                    'Esa área ya no está disponible. Recarga la página y elige otra.',
                    400
                );
            }
        }

        $antes = array_map(static fn(array $a): int => $a['minutos'], $actuales);
        if ($antes == $nuevas) {
            return $this->otrasAreas([$usuarioId], $fecha)[$usuarioId] ?? [];
        }

        Database::transaction(function () use ($usuarioId, $fecha, $nuevas, $actuales, $actorId): void {
            foreach ($actuales as $areaId => $fila) {
                if (!isset($nuevas[$areaId])) {
                    Database::execute('DELETE FROM #__horas_otras_areas WHERE id = ?', [$fila['id']]);
                } elseif ($nuevas[$areaId] !== $fila['minutos']) {
                    Database::execute(
                        "UPDATE #__horas_otras_areas
                            SET minutos = ?, registrado_por = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
                          WHERE id = ?",
                        [$nuevas[$areaId], $actorId, $fila['id']]
                    );
                }
            }
            foreach ($nuevas as $areaId => $minutos) {
                if (!isset($actuales[$areaId])) {
                    Database::execute(
                        'INSERT INTO #__horas_otras_areas (usuario_id, fecha, area_id, minutos, registrado_por) VALUES (?, ?, ?, ?, ?)',
                        [$usuarioId, $fecha, $areaId, $minutos, $actorId]
                    );
                }
            }
        });

        $nombre = static fn(array $m): array => array_combine(
            array_map(static fn(int $id): string => $opciones[$id]['nombre'] ?? (string) $id, array_keys($m)),
            array_values($m)
        );
        Logger::audit($actorId, 'cambio_tarea.guardar', 'usuario', $usuarioId, [
            'fecha' => $fecha,
            'antes_minutos' => $nombre($antes),
            'despues_minutos' => $nombre($nuevas),
        ]);

        return $this->otrasAreas([$usuarioId], $fecha)[$usuarioId] ?? [];
    }

    // ───────────────────────────── Internos ─────────────────────────────

    private function exigirFecha(string $fecha): void
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        if ($d === false || $d->format('Y-m-d') !== $fecha) {
            throw new CambioTareaException('FECHA_INVALIDA', 'La fecha no es válida.', 400);
        }
    }

    /**
     * Valida y deja las filas como [area_id => minutos].
     *
     * @param list<array{area_id: mixed, minutos: mixed}> $filas
     * @return array<int, int>
     */
    private function normalizar(array $filas): array
    {
        if (count($filas) > self::MAX_AREAS) {
            throw new CambioTareaException('DEMASIADAS_AREAS', 'Se pueden anotar hasta ' . self::MAX_AREAS . ' áreas por persona en el día.', 400);
        }
        $salida = [];
        $total = 0;
        foreach ($filas as $fila) {
            $areaId = is_array($fila) ? filter_var($fila['area_id'] ?? null, FILTER_VALIDATE_INT) : false;
            $minutos = is_array($fila) ? filter_var($fila['minutos'] ?? null, FILTER_VALIDATE_INT) : false;
            if ($areaId === false || $areaId <= 0) {
                throw new CambioTareaException('AREA_REQUERIDA', 'Elige el área de cada fila.', 400);
            }
            if ($minutos === false || $minutos <= 0 || $minutos > self::MINUTOS_MAX_AREA || $minutos % self::MINUTOS_PASO !== 0) {
                throw new CambioTareaException('HORAS_INVALIDAS', 'Las horas van de media en media hora, hasta 12 horas por área.', 400);
            }
            if (isset($salida[$areaId])) {
                throw new CambioTareaException('AREA_REPETIDA', 'Esa área ya está anotada para esta persona. Cambia sus horas en vez de repetirla.', 400);
            }
            $salida[$areaId] = $minutos;
            $total += $minutos;
        }
        if ($total > self::MINUTOS_MAX_DIA) {
            throw new CambioTareaException('HORAS_INVALIDAS', 'En total no se pueden anotar más de 16 horas en el día.', 400);
        }
        ksort($salida);
        return $salida;
    }

    /**
     * Turno de cada persona ese día (con sus minutos de trabajo) y su jornada.
     *
     * @param list<int> $ids
     * @return array{turnos: array<int, array{id: int, nombre: string, hora_inicio: string, hora_fin: string, minutos_netos: int}>, jornadas: array<int, ?string>}
     */
    private function turnos(array $ids, string $fecha): array
    {
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $filas = Database::fetchAll(
            "SELECT u.id AS usuario_id, u.jornada, t.id AS turno_id, t.nombre, t.hora_inicio, t.hora_fin
               FROM #__usuarios u
               LEFT JOIN #__usuarios_turnos ut ON ut.usuario_id = u.id AND ut.fecha = ?
               LEFT JOIN #__turnos t ON t.id = ut.turno_id
              WHERE u.id IN ({$in})",
            array_merge([$fecha], $ids)
        );
        $turnos = [];
        $jornadas = [];
        foreach ($filas as $f) {
            $id = (int) $f['usuario_id'];
            $jornada = $f['jornada'] !== null ? (string) $f['jornada'] : null;
            $jornadas[$id] = $jornada;
            if ($f['turno_id'] !== null) {
                $turnos[$id] = [
                    'id' => (int) $f['turno_id'],
                    'nombre' => (string) $f['nombre'],
                    'hora_inicio' => (string) $f['hora_inicio'],
                    'hora_fin' => (string) $f['hora_fin'],
                    'minutos_netos' => HorasTurno::minutosNetos((string) $f['hora_inicio'], (string) $f['hora_fin'], $jornada),
                ];
            }
        }
        return ['turnos' => $turnos, 'jornadas' => $jornadas];
    }

    /**
     * Piezas de huésped asignadas (vigentes) y hechas ese día, y áreas comunes hechas. Sin la habitación
     * de prueba. «Hechas» = limpiezas suyas terminadas (completadas o ya inspeccionadas).
     *
     * @param list<int> $ids
     * @return array<int, array{asignadas: int, hechas: int, areas: int}>
     */
    private function piezas(array $ids, string $fecha): array
    {
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $sinPrueba = HabitacionesPrueba::sqlSinPrueba('h');
        $salida = [];
        foreach (Database::fetchAll(
            "SELECT a.usuario_id, COUNT(*) AS n
               FROM #__asignaciones a
               JOIN #__habitaciones h ON h.id = a.habitacion_id
              WHERE a.fecha = ? AND a.activa = 1 AND h.es_espacio_comun = 0 AND {$sinPrueba}
                AND a.usuario_id IN ({$in})
              GROUP BY a.usuario_id",
            array_merge([$fecha], $ids)
        ) as $f) {
            $salida[(int) $f['usuario_id']]['asignadas'] = (int) $f['n'];
        }
        foreach (Database::fetchAll(
            "SELECT ec.usuario_id,
                    SUM(CASE WHEN h.es_espacio_comun = 0 THEN 1 ELSE 0 END) AS piezas,
                    SUM(CASE WHEN h.es_espacio_comun = 1 THEN 1 ELSE 0 END) AS areas
               FROM #__ejecuciones_checklist ec
               JOIN #__asignaciones a ON a.id = ec.asignacion_id
               JOIN #__habitaciones h ON h.id = ec.habitacion_id
              WHERE a.fecha = ? AND ec.estado IN ('completada', 'auditada') AND {$sinPrueba}
                AND ec.usuario_id IN ({$in})
              GROUP BY ec.usuario_id",
            array_merge([$fecha], $ids)
        ) as $f) {
            $id = (int) $f['usuario_id'];
            $salida[$id]['hechas'] = (int) $f['piezas'];
            $salida[$id]['areas'] = (int) $f['areas'];
        }
        return $salida;
    }

    /**
     * Horas en otras áreas anotadas ese día, por persona, en el orden de la lista de Ajustes.
     *
     * @param list<int> $ids
     * @return array<int, list<array{area_id: int, nombre: string, minutos: int}>>
     */
    private function otrasAreas(array $ids, string $fecha): array
    {
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $salida = [];
        foreach (Database::fetchAll(
            "SELECT hoa.usuario_id, hoa.area_id, lo.nombre, hoa.minutos
               FROM #__horas_otras_areas hoa
               JOIN #__listas_opciones lo ON lo.id = hoa.area_id
              WHERE hoa.fecha = ? AND hoa.usuario_id IN ({$in})
              ORDER BY lo.orden, lo.id",
            array_merge([$fecha], $ids)
        ) as $f) {
            $salida[(int) $f['usuario_id']][] = [
                'area_id' => (int) $f['area_id'],
                'nombre' => (string) $f['nombre'],
                'minutos' => (int) $f['minutos'],
            ];
        }
        return $salida;
    }
}
