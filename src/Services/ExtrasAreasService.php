<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\Habitacion;

/**
 * Extras de las áreas comunes beta (v7.27): tareas que se agregan a una limpieza puntual, en su tabla propia. Las
 * habitaciones no leen esta tabla (decisión de Nicolás, 11/11/2026). Ver docs/areas-comunes.md §9.
 *
 * Un extra espera por área y fecha hasta que alguien empieza esa limpieza: así sigue a la limpieza aunque se la
 * pasen a otra persona. En el checklist y la inspección viaja con el id en NEGATIVO, para convivir con los ítems
 * (ids positivos) en la misma lista sin chocar.
 */
final class ExtrasAreasService
{
    /**
     * Valida los extras pedidos sin escribir nada: así un dato malo no deja la limpieza asignada a medias.
     *
     * @param list<mixed> $extras [{tarea_id, m2?}]
     * @return list<array{tarea_id: int, m2: float, nombre: string, valor: float, m2_nuevo: bool}>
     */
    public function preparar(int $areaId, array $extras): array
    {
        $this->exigirBeta($areaId);
        $composicion = [];
        foreach (Database::fetchAll('SELECT tarea_id, m2, tipo FROM #__espacio_tareas WHERE habitacion_id = ?', [$areaId]) as $f) {
            $composicion[(int) $f['tarea_id']] = $f;
        }

        $listos = [];
        foreach ($extras as $extra) {
            $tareaId = is_array($extra) && is_numeric($extra['tarea_id'] ?? null) ? (int) $extra['tarea_id'] : 0;
            $tarea = TareasCatalogoService::obtener($tareaId);
            if (isset($listos[$tareaId])) {
                continue;
            }
            $guardada = $composicion[$tareaId] ?? null;
            if ($guardada !== null && $guardada['tipo'] === 'base') {
                throw new EspacioException('EXTRA_EN_BASE', "«{$tarea['nombre']}» ya es parte de la base de esta área.", 409);
            }
            if ($guardada !== null) {
                $m2 = (float) $guardada['m2'];
            } elseif (is_numeric($extra['m2'] ?? null)) {
                $m2 = TareasCatalogoService::validarM2((float) $extra['m2']);
            } else {
                throw new EspacioException('EXTRA_SIN_M2', "Indica los m² de «{$tarea['nombre']}» en esta área.", 400);
            }
            $listos[$tareaId] = [
                'tarea_id' => $tareaId,
                'm2' => $m2,
                'nombre' => (string) $tarea['nombre'],
                'valor' => (float) $tarea['creditos_m2'],
                'm2_nuevo' => $guardada === null,
            ];
        }
        return array_values($listos);
    }

    /**
     * Deja los extras en espera del área y fecha iguales a la lista recibida. Los m² nuevos quedan recordados.
     *
     * @param list<array{tarea_id: int, m2: float, nombre: string, valor: float, m2_nuevo: bool}> $listos de preparar()
     */
    public function sincronizar(int $areaId, string $fecha, array $listos, ?int $actorId = null): void
    {
        Database::transaction(function () use ($areaId, $fecha, $listos, $actorId): void {
            $enEspera = [];
            foreach (Database::fetchAll(
                'SELECT id, tarea_id FROM #__extras_areas_comunes WHERE habitacion_id = ? AND fecha = ? AND ejecucion_id IS NULL',
                [$areaId, $fecha]
            ) as $f) {
                $enEspera[(int) $f['tarea_id']] = (int) $f['id'];
            }

            foreach ($listos as $extra) {
                if ($extra['m2_nuevo']) {
                    Database::execute(
                        "INSERT INTO #__espacio_tareas (habitacion_id, tarea_id, m2, tipo) VALUES (?, ?, ?, 'extra')",
                        [$areaId, $extra['tarea_id'], $extra['m2']]
                    );
                }
                if (isset($enEspera[$extra['tarea_id']])) {
                    unset($enEspera[$extra['tarea_id']]);
                    continue;
                }
                Database::execute(
                    'INSERT INTO #__extras_areas_comunes (habitacion_id, fecha, tarea_id, m2, creditos, agregado_por) VALUES (?, ?, ?, ?, ?, ?)',
                    [$areaId, $fecha, $extra['tarea_id'], $extra['m2'], TareasCatalogoService::creditosDe($extra['m2'], $extra['valor']), $actorId]
                );
            }

            foreach ($enEspera as $id) {
                Database::execute('DELETE FROM #__extras_areas_comunes WHERE id = ?', [$id]);
            }
        });

        Logger::audit($actorId, 'espacio.extras', 'habitacion', $areaId, [
            'fecha' => $fecha, 'tareas' => array_column($listos, 'tarea_id'),
        ]);
    }

    /**
     * Cambia los extras de una limpieza ya asignada. Solo hasta que la persona la empieza: después, la lista no
     * cambia bajo sus manos (decisión de Nicolás, 11/11/2026).
     *
     * @param list<mixed> $extras
     */
    public function guardar(int $areaId, string $fecha, array $extras, ?int $actorId = null): void
    {
        $this->exigirEditable($areaId, $fecha);
        $this->sincronizar($areaId, $fecha, $this->preparar($areaId, $extras), $actorId);
    }

    /**
     * Extras en espera de esa fecha y si todavía se pueden cambiar (pantalla de Áreas comunes).
     *
     * @return array{extras: list<array<string, mixed>>, editable: bool}
     */
    public function enEspera(int $areaId, string $fecha): array
    {
        $this->exigirBeta($areaId);
        $editable = true;
        try {
            $this->exigirEditable($areaId, $fecha);
        } catch (EspacioException) {
            $editable = false;
        }
        $filas = Database::fetchAll(
            'SELECT ex.id, ex.tarea_id, t.nombre, ex.m2, ex.creditos
               FROM #__extras_areas_comunes ex
               JOIN #__tareas_catalogo t ON t.id = ex.tarea_id
              WHERE ex.habitacion_id = ? AND ex.fecha = ? AND ex.ejecucion_id IS NULL
              ORDER BY ex.id',
            [$areaId, $fecha]
        );
        return [
            'extras' => array_map(static fn(array $f): array => [
                'id' => (int) $f['id'], 'tarea_id' => (int) $f['tarea_id'], 'nombre' => (string) $f['nombre'],
                'm2' => (float) $f['m2'], 'creditos' => (int) $f['creditos'],
            ], $filas),
            'editable' => $editable,
        ];
    }

    /**
     * Al empezar la limpieza, los extras de ese día pasan a ella, parten sin marcar y su valor queda fijo. Recoge
     * también los de una limpieza en curso que se abandonó (se la pasaron a otra persona): esa parte de cero.
     */
    public function vincularAEjecucion(int $areaId, string $fecha, int $ejecucionId): void
    {
        $filas = Database::fetchAll(
            "SELECT ex.id, ex.tarea_id, ex.m2, t.creditos_m2
               FROM #__extras_areas_comunes ex
               JOIN #__tareas_catalogo t ON t.id = ex.tarea_id
              WHERE ex.habitacion_id = ? AND ex.fecha = ?
                AND (ex.ejecucion_id IS NULL OR ex.ejecucion_id IN (
                        SELECT ec.id FROM #__ejecuciones_checklist ec
                         WHERE ec.habitacion_id = ? AND ec.estado = 'en_progreso' AND ec.id <> ?))
              ORDER BY ex.id DESC",
            [$areaId, $fecha, $areaId, $ejecucionId]
        );
        $vistas = [];
        foreach ($filas as $f) {
            // La misma tarea en espera y en la limpieza abandonada: queda una sola, la más reciente.
            if (isset($vistas[(int) $f['tarea_id']])) {
                Database::execute('DELETE FROM #__extras_areas_comunes WHERE id = ?', [(int) $f['id']]);
                continue;
            }
            $vistas[(int) $f['tarea_id']] = true;
            Database::execute(
                "UPDATE #__extras_areas_comunes
                    SET ejecucion_id = ?, creditos = ?, marcado = 0, marcado_por = NULL, desmarcado_por_auditor = 0,
                        updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
                  WHERE id = ?",
                [$ejecucionId, TareasCatalogoService::creditosDe((float) $f['m2'], (float) $f['creditos_m2']), (int) $f['id']]
            );
        }
    }

    /**
     * Re-limpieza de un rechazo del mismo ciclo: los extras viajan a la limpieza nueva. Los que quedaron bien
     * siguen marcados a nombre de quien los hizo; los que desmarcó la inspección hay que rehacerlos.
     */
    public function heredarDeRechazo(int $areaId, int $ejecucionId, string $fecha, ?string $franja): void
    {
        $anterior = $this->rechazoDelCiclo($areaId, $ejecucionId, $fecha, $franja);
        if ($anterior === null) {
            return;
        }
        $yaEstan = array_flip(array_map(
            'intval',
            array_column(Database::fetchAll('SELECT tarea_id FROM #__extras_areas_comunes WHERE ejecucion_id = ?', [$ejecucionId]), 'tarea_id')
        ));
        foreach (Database::fetchAll('SELECT * FROM #__extras_areas_comunes WHERE ejecucion_id = ?', [$anterior]) as $f) {
            if (isset($yaEstan[(int) $f['tarea_id']])) {
                continue;
            }
            $quedoBien = (int) $f['marcado'] === 1 && (int) $f['desmarcado_por_auditor'] === 0;
            Database::execute(
                'INSERT INTO #__extras_areas_comunes (habitacion_id, fecha, ejecucion_id, tarea_id, m2, creditos, marcado, marcado_por, agregado_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$areaId, $fecha, $ejecucionId, (int) $f['tarea_id'], $f['m2'], (int) $f['creditos'], $quedoBien ? 1 : 0,
                 $quedoBien ? $f['marcado_por'] : null, $f['agregado_por']]
            );
        }
    }

    /**
     * Los extras de una limpieza, con la forma de un ítem del checklist y el id en negativo.
     *
     * @return list<array<string, mixed>>
     */
    public function deEjecucion(int $ejecucionId): array
    {
        $filas = Database::fetchAll(
            'SELECT ex.id, t.nombre, ex.marcado, ex.desmarcado_por_auditor, ex.marcado_por
               FROM #__extras_areas_comunes ex
               JOIN #__tareas_catalogo t ON t.id = ex.tarea_id
              WHERE ex.ejecucion_id = ?
              ORDER BY ex.id',
            [$ejecucionId]
        );
        return array_map(static fn(array $f): array => [
            'id' => -((int) $f['id']),
            'orden' => 0,
            'descripcion' => (string) $f['nombre'],
            'obligatorio' => 1,
            'es_cambio_sabanas' => 0,
            'es_extra' => 1,
            'marcado' => (int) $f['marcado'],
            'desmarcado_por_auditor' => (int) $f['desmarcado_por_auditor'],
            'marcado_por' => $f['marcado_por'] === null ? null : (int) $f['marcado_por'],
        ], $filas);
    }

    /** @return array{total: int, marcados: int} */
    public function progreso(int $ejecucionId): array
    {
        $fila = Database::fetchOne(
            'SELECT COUNT(*) AS total, COALESCE(SUM(marcado), 0) AS marcados FROM #__extras_areas_comunes WHERE ejecucion_id = ?',
            [$ejecucionId]
        );
        return ['total' => (int) ($fila['total'] ?? 0), 'marcados' => (int) ($fila['marcados'] ?? 0)];
    }

    /** Marca o desmarca un extra, con la misma regla de los ítems heredados de una re-limpieza. */
    public function marcar(int $ejecucionId, int $extraId, bool $marcado, int $usuarioId): void
    {
        $extra = Database::fetchOne(
            'SELECT id, marcado, marcado_por FROM #__extras_areas_comunes WHERE id = ? AND ejecucion_id = ?',
            [$extraId, $ejecucionId]
        );
        if ($extra === null) {
            throw new ChecklistException('ITEM_INVALIDO', 'Este extra no pertenece a esta limpieza.', 400);
        }
        if ((int) $extra['marcado'] === 1 && $extra['marcado_por'] !== null && (int) $extra['marcado_por'] !== $usuarioId) {
            if ($marcado) {
                return;
            }
            throw new ChecklistException('ITEM_HEREDADO', 'Este ítem ya lo dejó listo otra persona.', 403);
        }
        Database::execute(
            "UPDATE #__extras_areas_comunes SET marcado = ?, marcado_por = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
            [$marcado ? 1 : 0, $marcado ? $usuarioId : null, $extraId]
        );
        Logger::audit($usuarioId, $marcado ? 'checklist.marcar_extra' : 'checklist.desmarcar_extra', 'ejecucion_checklist', $ejecucionId, ['extra_id' => $extraId]);
    }

    /**
     * Inspección: desmarca extras (conserva marcado_por, igual que los ítems).
     *
     * @param list<int> $extraIds ids positivos
     */
    public function desmarcarPorAuditor(int $ejecucionId, array $extraIds): void
    {
        if ($extraIds === []) {
            return;
        }
        $marcas = implode(',', array_fill(0, count($extraIds), '?'));
        $validos = (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM #__extras_areas_comunes WHERE ejecucion_id = ? AND id IN ({$marcas})",
            array_merge([$ejecucionId], $extraIds)
        );
        if ($validos !== count($extraIds)) {
            throw new ChecklistException('ITEMS_DESMARCADOS_INVALIDO', 'Algunos extras no pertenecen a esta limpieza.', 400);
        }
        Database::execute(
            "UPDATE #__extras_areas_comunes
                SET marcado = 0, desmarcado_por_auditor = 1, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE ejecucion_id = ? AND id IN ({$marcas})",
            array_merge([$ejecucionId], $extraIds)
        );
    }

    /**
     * Extras de esta limpieza que la inspección había desmarcado en el intento rechazado (id en negativo).
     *
     * @return list<int>
     */
    public function rechazados(int $ejecucionAnteriorId, int $ejecucionId): array
    {
        $filas = Database::fetchAll(
            'SELECT nuevo.id
               FROM #__extras_areas_comunes nuevo
               JOIN #__extras_areas_comunes viejo ON viejo.tarea_id = nuevo.tarea_id AND viejo.ejecucion_id = ?
              WHERE nuevo.ejecucion_id = ? AND viejo.desmarcado_por_auditor = 1',
            [$ejecucionAnteriorId, $ejecucionId]
        );
        return array_map(static fn(array $f): int => -((int) $f['id']), $filas);
    }

    /**
     * Nombres de unos extras (para el texto de una observación).
     *
     * @param list<int> $extraIds ids positivos
     * @return list<string>
     */
    public function nombres(array $extraIds): array
    {
        if ($extraIds === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($extraIds), '?'));
        return array_map('strval', array_column(Database::fetchAll(
            "SELECT t.nombre FROM #__extras_areas_comunes ex JOIN #__tareas_catalogo t ON t.id = ex.tarea_id WHERE ex.id IN ({$marcas}) ORDER BY ex.id",
            $extraIds
        ), 'nombre'));
    }

    private function rechazoDelCiclo(int $areaId, int $ejecucionId, string $fecha, ?string $franja): ?int
    {
        $anterior = Database::fetchOne(
            "SELECT ec.id, a.veredicto, asg.fecha, asg.franja
               FROM #__ejecuciones_checklist ec
               JOIN #__auditorias a ON a.ejecucion_id = ec.id
               JOIN #__asignaciones asg ON asg.id = ec.asignacion_id
              WHERE ec.habitacion_id = ? AND ec.estado = 'auditada' AND ec.id < ?
              ORDER BY ec.id DESC LIMIT 1",
            [$areaId, $ejecucionId]
        );
        if ($anterior === null || $anterior['veredicto'] !== 'rechazado'
            || (string) $anterior['fecha'] !== $fecha || ($anterior['franja'] ?? null) !== $franja) {
            return null;
        }
        return (int) $anterior['id'];
    }

    private function exigirBeta(int $areaId): void
    {
        $fila = Database::fetchOne('SELECT area_beta FROM #__habitaciones WHERE id = ? AND es_espacio_comun = 1 AND activa = 1', [$areaId]);
        if ($fila === null) {
            throw new EspacioException('ESPACIO_NO_ENCONTRADO', 'Área común no encontrada.', 404);
        }
        if ((int) $fila['area_beta'] !== 1) {
            throw new EspacioException('AREA_NO_BETA', 'Los extras son solo para áreas beta.', 409);
        }
    }

    private function exigirEditable(int $areaId, string $fecha): void
    {
        $activa = Database::fetchOne(
            'SELECT id FROM #__asignaciones WHERE habitacion_id = ? AND fecha = ? AND activa = 1 LIMIT 1',
            [$areaId, $fecha]
        );
        if ($activa === null) {
            throw new EspacioException('EXTRA_SIN_ASIGNACION', 'Primero pide la limpieza de esta área para ese día.', 409);
        }
        if ($fecha !== date('Y-m-d')) {
            return;
        }
        $estado = (string) Database::fetchColumn('SELECT estado FROM #__habitaciones WHERE id = ?', [$areaId]);
        if ($estado === Habitacion::ESTADO_EN_PROGRESO) {
            throw new EspacioException('EXTRA_LIMPIEZA_EMPEZADA', 'La limpieza ya empezó: los extras se agregan antes de eso.', 409);
        }
        if ($estado !== Habitacion::ESTADO_SUCIA && $estado !== Habitacion::ESTADO_RECHAZADA) {
            throw new EspacioException('EXTRA_LIMPIEZA_HECHA', 'Esta limpieza ya se hizo. Para agregar extras, pide una limpieza nueva.', 409);
        }
    }
}
