<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\EjecucionChecklist;
use Atankalama\Limpieza\Models\Habitacion;

final class ChecklistService
{
    public function __construct(
        private readonly HabitacionService $habitaciones = new HabitacionService(),
        private readonly AsignacionService $asignaciones = new AsignacionService(),
        private readonly ?AlertasPredictivasService $predictivas = null,
    ) {
    }

    // ----- Templates -----

    /** @return list<array<string, mixed>> */
    public function listarTemplates(): array
    {
        return Database::fetchAll(
            'SELECT ct.*, th.nombre AS tipo_nombre
               FROM #__checklists_template ct
               JOIN #__tipos_habitacion th ON th.id = ct.tipo_habitacion_id
              WHERE ct.activo = 1
              ORDER BY th.nombre'
        );
    }

    /** @return list<array<string, mixed>> */
    public function itemsDelTemplate(int $templateId, bool $soloActivos = true): array
    {
        $where = 'template_id = ?';
        if ($soloActivos) {
            $where .= ' AND activo = 1';
        }
        return Database::fetchAll(
            "SELECT * FROM #__items_checklist WHERE $where ORDER BY orden, id",
            [$templateId]
        );
    }

    public function templateParaTipo(int $tipoHabitacionId): ?int
    {
        $fila = Database::fetchOne(
            'SELECT id FROM #__checklists_template WHERE tipo_habitacion_id = ? AND activo = 1 ORDER BY id LIMIT 1',
            [$tipoHabitacionId]
        );
        return $fila === null ? null : (int) $fila['id'];
    }

    // ----- Ejecución -----

    /**
     * Inicia ejecución del checklist para una habitación asignada al trabajador.
     * Idempotente: si ya existe una ejecución 'en_progreso' la retorna (reanuda).
     */
    public function iniciarEjecucion(int $habitacionId, int $usuarioId, string $fecha): EjecucionChecklist
    {
        $habitacion = $this->habitaciones->obtener($habitacionId);
        if ($habitacion === null) {
            throw new ChecklistException('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada.', 404);
        }
        if (!$this->asignaciones->esHabitacionAsignadaA($habitacionId, $usuarioId, $fecha)) {
            throw new ChecklistException('HABITACION_NO_ASIGNADA', 'Esta habitación no está asignada a ti.', 403);
        }

        $asignacion = $this->asignaciones->obtenerActivaDeHabitacion($habitacionId);
        if ($asignacion === null) {
            throw new ChecklistException('ASIGNACION_NO_ACTIVA', 'No hay asignación activa.', 409);
        }

        $existente = Database::fetchOne(
            "SELECT * FROM #__ejecuciones_checklist
              WHERE habitacion_id = ? AND asignacion_id = ? AND estado = 'en_progreso'
              ORDER BY id DESC LIMIT 1",
            [$habitacionId, $asignacion->id]
        );
        if ($existente !== null) {
            return EjecucionChecklist::desdeFila($existente);
        }

        if ($habitacion->estado !== Habitacion::ESTADO_SUCIA && $habitacion->estado !== Habitacion::ESTADO_RECHAZADA && $habitacion->estado !== Habitacion::ESTADO_EN_PROGRESO) {
            throw new ChecklistException('ESTADO_INVALIDO_PARA_INICIAR', 'La habitación no está en un estado que permita iniciar.', 409);
        }

        $templateId = $this->templateParaTipo($habitacion->tipoHabitacionId);
        if ($templateId === null) {
            throw new ChecklistException('TEMPLATE_NO_ENCONTRADO', 'No hay checklist template para este tipo de habitación.', 500);
        }

        if ($habitacion->estado === Habitacion::ESTADO_SUCIA || $habitacion->estado === Habitacion::ESTADO_RECHAZADA) {
            $this->habitaciones->cambiarEstado($habitacionId, Habitacion::ESTADO_EN_PROGRESO, $usuarioId, 'ui');
        }

        Database::execute(
            'INSERT INTO #__ejecuciones_checklist (habitacion_id, asignacion_id, usuario_id, template_id, estado) VALUES (?, ?, ?, ?, ?)',
            [$habitacionId, $asignacion->id, $usuarioId, $templateId, EjecucionChecklist::ESTADO_EN_PROGRESO]
        );
        $id = Database::lastInsertId();

        // Re-limpieza: hereda los ítems que quedaron bien del intento anterior si esta pieza
        // venía de un rechazo. Así el nuevo trabajador solo completa lo desmarcado y cada ítem
        // conserva a nombre de quién lo hizo (reparto de créditos). Ver docs/creditos-rework.md.
        $heredados = $this->heredarItemsSiEsRelimpieza($habitacionId, $id, $templateId);

        Logger::audit($usuarioId, 'checklist.iniciar', 'ejecucion_checklist', $id, [
            'habitacion_id' => $habitacionId, 'template_id' => $templateId, 'items_heredados' => $heredados,
        ]);

        $fila = Database::fetchOne('SELECT * FROM #__ejecuciones_checklist WHERE id = ?', [$id]);
        return EjecucionChecklist::desdeFila($fila);
    }

    public function obtenerEjecucion(int $id): ?EjecucionChecklist
    {
        $fila = Database::fetchOne('SELECT * FROM #__ejecuciones_checklist WHERE id = ?', [$id]);
        return $fila === null ? null : EjecucionChecklist::desdeFila($fila);
    }

    /**
     * Devuelve el id de la ejecución 'en_progreso' más reciente para una habitación
     * y trabajador específicos, o null si no existe.
     */
    public function obtenerEjecucionEnProgreso(int $habitacionId, int $usuarioId): ?int
    {
        $fila = Database::fetchOne(
            "SELECT id FROM #__ejecuciones_checklist
              WHERE habitacion_id = ? AND usuario_id = ? AND estado = 'en_progreso'
              ORDER BY id DESC LIMIT 1",
            [$habitacionId, $usuarioId]
        );
        return $fila === null ? null : (int) $fila['id'];
    }

    /**
     * Devuelve la última ejecución (cualquier estado) para una habitación, o null.
     * Útil para la pantalla de auditoría que muestra el último checklist completado.
     */
    public function obtenerUltimaEjecucionDeHabitacion(int $habitacionId): ?EjecucionChecklist
    {
        $fila = Database::fetchOne(
            'SELECT * FROM #__ejecuciones_checklist
              WHERE habitacion_id = ?
              ORDER BY id DESC LIMIT 1',
            [$habitacionId]
        );
        return $fila === null ? null : EjecucionChecklist::desdeFila($fila);
    }

    /**
     * Estado detallado de una ejecución con sus items y progreso.
     *
     * @return array<string, mixed>
     */
    public function estadoEjecucion(int $ejecucionId): array
    {
        $ejec = $this->obtenerEjecucion($ejecucionId);
        if ($ejec === null) {
            throw new ChecklistException('EJECUCION_NO_ENCONTRADA', 'Ejecución no encontrada.', 404);
        }

        $items = Database::fetchAll(
            "SELECT ic.id, ic.orden, ic.descripcion, ic.obligatorio,
                    COALESCE(ei.marcado, 0) AS marcado,
                    COALESCE(ei.desmarcado_por_auditor, 0) AS desmarcado_por_auditor,
                    ei.marcado_por
               FROM #__items_checklist ic
          LEFT JOIN #__ejecuciones_items ei
                 ON ei.item_id = ic.id AND ei.ejecucion_id = ?
              WHERE ic.template_id = ? AND ic.activo = 1
              ORDER BY ic.orden, ic.id",
            [$ejecucionId, $ejec->templateId]
        );

        $progreso = $this->calcularProgreso($ejecucionId, $ejec->templateId);

        return [
            'ejecucion' => $ejec->toArrayPublico(),
            'items' => $items,
            'progreso' => $progreso,
        ];
    }

    /**
     * Marca/desmarca un item. Persistencia tap-a-tap.
     *
     * @return array<string, mixed> progreso actual
     */
    public function marcarItem(int $ejecucionId, int $itemId, bool $marcado, int $usuarioId): array
    {
        $ejec = $this->obtenerEjecucion($ejecucionId);
        if ($ejec === null) {
            throw new ChecklistException('EJECUCION_NO_ENCONTRADA', 'Ejecución no encontrada.', 404);
        }
        if ($ejec->usuarioId !== $usuarioId) {
            throw new ChecklistException('EJECUCION_AJENA', 'Esta ejecución no es tuya.', 403);
        }
        if ($ejec->estado !== EjecucionChecklist::ESTADO_EN_PROGRESO) {
            throw new ChecklistException('EJECUCION_NO_EDITABLE', 'No se puede modificar una ejecución ya completada.', 409);
        }

        $item = Database::fetchOne(
            'SELECT id FROM #__items_checklist WHERE id = ? AND template_id = ? AND activo = 1',
            [$itemId, $ejec->templateId]
        );
        if ($item === null) {
            throw new ChecklistException('ITEM_INVALIDO', 'El item no pertenece al template de esta ejecución.', 400);
        }

        $existente = Database::fetchOne(
            'SELECT id FROM #__ejecuciones_items WHERE ejecucion_id = ? AND item_id = ?',
            [$ejecucionId, $itemId]
        );
        // marcado_por = quién dejó el ítem marcado (null al desmarcar). Clave para repartir
        // créditos en re-limpieza: cada ítem queda a nombre de quien lo completó.
        $marcadoPor = $marcado ? $usuarioId : null;
        if ($existente === null) {
            Database::execute(
                'INSERT INTO #__ejecuciones_items (ejecucion_id, item_id, marcado, marcado_por) VALUES (?, ?, ?, ?)',
                [$ejecucionId, $itemId, $marcado ? 1 : 0, $marcadoPor]
            );
        } else {
            Database::execute(
                "UPDATE #__ejecuciones_items SET marcado = ?, marcado_por = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                [$marcado ? 1 : 0, $marcadoPor, (int) $existente['id']]
            );
        }

        Logger::audit(
            $usuarioId,
            $marcado ? 'checklist.marcar_item' : 'checklist.desmarcar_item',
            'ejecucion_checklist',
            $ejecucionId,
            ['item_id' => $itemId]
        );

        return $this->calcularProgreso($ejecucionId, $ejec->templateId);
    }

    /**
     * Marca la habitación como terminada. Valida 100% obligatorios.
     */
    public function completar(int $ejecucionId, int $usuarioId): void
    {
        $ejec = $this->obtenerEjecucion($ejecucionId);
        if ($ejec === null) {
            throw new ChecklistException('EJECUCION_NO_ENCONTRADA', 'Ejecución no encontrada.', 404);
        }
        if ($ejec->usuarioId !== $usuarioId) {
            throw new ChecklistException('EJECUCION_AJENA', 'Esta ejecución no es tuya.', 403);
        }
        if ($ejec->estado !== EjecucionChecklist::ESTADO_EN_PROGRESO) {
            throw new ChecklistException('EJECUCION_NO_EDITABLE', 'Ejecución ya completada.', 409);
        }

        $progreso = $this->calcularProgreso($ejecucionId, $ejec->templateId);
        if ($progreso['obligatorios_pendientes'] > 0) {
            throw new ChecklistException('CHECKLIST_INCOMPLETO', 'Faltan items obligatorios por marcar.', 409);
        }

        Database::execute(
            "UPDATE #__ejecuciones_checklist
                SET estado = 'completada',
                    timestamp_fin = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE id = ?",
            [$ejecucionId]
        );

        $this->habitaciones->cambiarEstado(
            $ejec->habitacionId,
            Habitacion::ESTADO_COMPLETADA_PENDIENTE_AUDITORIA,
            $usuarioId,
            'ui'
        );

        Logger::audit($usuarioId, 'checklist.completar', 'ejecucion_checklist', $ejecucionId, [
            'habitacion_id' => $ejec->habitacionId,
        ]);

        try {
            $svc = $this->predictivas ?? new AlertasPredictivasService();
            $turno = Database::fetchOne(
                'SELECT t.hora_fin
                   FROM #__usuarios_turnos ut
                   JOIN #__turnos t ON t.id = ut.turno_id
                  WHERE ut.usuario_id = ? AND ut.fecha = date(\'now\')
                  ORDER BY ut.id DESC LIMIT 1',
                [$usuarioId]
            );
            if ($turno !== null) {
                $svc->evaluarTrabajador($usuarioId, date('Y-m-d'), (string) $turno['hora_fin'], date('H:i'));
            }
        } catch (\Throwable $e) {
            Logger::error('alertas_predictivas', 'fallo recálculo post-completar', [
                'mensaje' => $e->getMessage(),
            ]);
        }
    }

    /** @return array{marcados:int,total:int,porcentaje:int,obligatorios_total:int,obligatorios_marcados:int,obligatorios_pendientes:int} */
    private function calcularProgreso(int $ejecucionId, int $templateId): array
    {
        $fila = Database::fetchOne(
            "SELECT
                SUM(CASE WHEN ic.activo = 1 THEN 1 ELSE 0 END) AS total,
                SUM(CASE WHEN ic.activo = 1 AND COALESCE(ei.marcado, 0) = 1 THEN 1 ELSE 0 END) AS marcados,
                SUM(CASE WHEN ic.activo = 1 AND ic.obligatorio = 1 THEN 1 ELSE 0 END) AS obligatorios_total,
                SUM(CASE WHEN ic.activo = 1 AND ic.obligatorio = 1 AND COALESCE(ei.marcado, 0) = 1 THEN 1 ELSE 0 END) AS obligatorios_marcados
               FROM #__items_checklist ic
          LEFT JOIN #__ejecuciones_items ei
                 ON ei.item_id = ic.id AND ei.ejecucion_id = ?
              WHERE ic.template_id = ?",
            [$ejecucionId, $templateId]
        );
        $total = (int) ($fila['total'] ?? 0);
        $marcados = (int) ($fila['marcados'] ?? 0);
        $oblTotal = (int) ($fila['obligatorios_total'] ?? 0);
        $oblMarcados = (int) ($fila['obligatorios_marcados'] ?? 0);
        return [
            'marcados' => $marcados,
            'total' => $total,
            'porcentaje' => $total === 0 ? 0 : (int) round($marcados * 100 / $total),
            'obligatorios_total' => $oblTotal,
            'obligatorios_marcados' => $oblMarcados,
            'obligatorios_pendientes' => $oblTotal - $oblMarcados,
        ];
    }

    /**
     * Marca items como desmarcados por auditor. Usado por AuditoriaService.
     *
     * @param list<int> $itemIds
     */
    public function desmarcarPorAuditor(int $ejecucionId, array $itemIds, int $templateId): void
    {
        if ($itemIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $validos = Database::fetchAll(
            "SELECT id FROM #__items_checklist WHERE template_id = ? AND id IN ($placeholders)",
            array_merge([$templateId], $itemIds)
        );
        $validosIds = array_map(static fn(array $f) => (int) $f['id'], $validos);
        if (count($validosIds) !== count($itemIds)) {
            throw new ChecklistException('ITEMS_DESMARCADOS_INVALIDO', 'Algunos items no pertenecen al template.', 400);
        }

        foreach ($itemIds as $itemId) {
            $ex = Database::fetchOne(
                'SELECT id FROM #__ejecuciones_items WHERE ejecucion_id = ? AND item_id = ?',
                [$ejecucionId, $itemId]
            );
            if ($ex === null) {
                Database::execute(
                    'INSERT INTO #__ejecuciones_items (ejecucion_id, item_id, marcado, desmarcado_por_auditor) VALUES (?, ?, 0, 1)',
                    [$ejecucionId, $itemId]
                );
            } else {
                // Se conserva marcado_por: el ítem fallido sigue atribuido a quien lo marcó mal,
                // para que cuente como intento fallido en SU denominador de créditos (castiga el %).
                // No se hereda a la re-limpieza (marcado=0); quien lo rehaga crea su propia fila.
                Database::execute(
                    "UPDATE #__ejecuciones_items SET marcado = 0, desmarcado_por_auditor = 1, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                    [(int) $ex['id']]
                );
            }
        }
    }

    /**
     * Si el último veredicto de la habitación fue un rechazo, copia a la nueva ejecución los
     * ítems que quedaron marcados (los que el auditor NO desmarcó) del intento anterior, con su
     * marcado_por original. Así el nuevo trabajador solo completa lo desmarcado y cada ítem
     * conserva a nombre de quién lo hizo (reparto de créditos). Devuelve cuántos ítems heredó.
     *
     * Se detecta por el último veredicto (no por el estado): al reasignar, la pieza ya pasó de
     * 'rechazada' a 'sucia'. Tras una aprobación (ciclo nuevo) no hereda.
     */
    private function heredarItemsSiEsRelimpieza(int $habitacionId, int $nuevaEjecucionId, int $templateId): int
    {
        $anterior = Database::fetchOne(
            "SELECT ec.id, a.veredicto
               FROM #__ejecuciones_checklist ec
               JOIN #__auditorias a ON a.ejecucion_id = ec.id
              WHERE ec.habitacion_id = ? AND ec.estado = 'auditada'
              ORDER BY ec.id DESC LIMIT 1",
            [$habitacionId]
        );
        if ($anterior === null || $anterior['veredicto'] !== 'rechazado') {
            return 0;
        }

        // Copia los ítems marcados del intento rechazado (que siguen activos en el template),
        // preservando marcado_por. Los desmarcados por el auditor NO se copian: quedan pendientes.
        return Database::execute(
            'INSERT INTO #__ejecuciones_items (ejecucion_id, item_id, marcado, marcado_por)
             SELECT ?, ei.item_id, 1, ei.marcado_por
               FROM #__ejecuciones_items ei
               JOIN #__items_checklist ic ON ic.id = ei.item_id
              WHERE ei.ejecucion_id = ? AND ei.marcado = 1 AND ic.template_id = ? AND ic.activo = 1',
            [$nuevaEjecucionId, (int) $anterior['id'], $templateId]
        );
    }
}
