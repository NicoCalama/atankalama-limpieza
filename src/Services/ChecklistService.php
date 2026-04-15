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
    ) {
    }

    // ----- Templates -----

    /** @return list<array<string, mixed>> */
    public function listarTemplates(): array
    {
        return Database::fetchAll(
            'SELECT ct.*, th.nombre AS tipo_nombre
               FROM checklists_template ct
               JOIN tipos_habitacion th ON th.id = ct.tipo_habitacion_id
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
            "SELECT * FROM items_checklist WHERE $where ORDER BY orden, id",
            [$templateId]
        );
    }

    public function templateParaTipo(int $tipoHabitacionId): ?int
    {
        $fila = Database::fetchOne(
            'SELECT id FROM checklists_template WHERE tipo_habitacion_id = ? AND activo = 1 ORDER BY id LIMIT 1',
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
            "SELECT * FROM ejecuciones_checklist
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
            'INSERT INTO ejecuciones_checklist (habitacion_id, asignacion_id, usuario_id, template_id, estado) VALUES (?, ?, ?, ?, ?)',
            [$habitacionId, $asignacion->id, $usuarioId, $templateId, EjecucionChecklist::ESTADO_EN_PROGRESO]
        );
        $id = Database::lastInsertId();

        Logger::audit($usuarioId, 'checklist.iniciar', 'ejecucion_checklist', $id, [
            'habitacion_id' => $habitacionId, 'template_id' => $templateId,
        ]);

        $fila = Database::fetchOne('SELECT * FROM ejecuciones_checklist WHERE id = ?', [$id]);
        return EjecucionChecklist::desdeFila($fila);
    }

    public function obtenerEjecucion(int $id): ?EjecucionChecklist
    {
        $fila = Database::fetchOne('SELECT * FROM ejecuciones_checklist WHERE id = ?', [$id]);
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
                    COALESCE(ei.desmarcado_por_auditor, 0) AS desmarcado_por_auditor
               FROM items_checklist ic
          LEFT JOIN ejecuciones_items ei
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
            'SELECT id FROM items_checklist WHERE id = ? AND template_id = ? AND activo = 1',
            [$itemId, $ejec->templateId]
        );
        if ($item === null) {
            throw new ChecklistException('ITEM_INVALIDO', 'El item no pertenece al template de esta ejecución.', 400);
        }

        $existente = Database::fetchOne(
            'SELECT id FROM ejecuciones_items WHERE ejecucion_id = ? AND item_id = ?',
            [$ejecucionId, $itemId]
        );
        if ($existente === null) {
            Database::execute(
                'INSERT INTO ejecuciones_items (ejecucion_id, item_id, marcado) VALUES (?, ?, ?)',
                [$ejecucionId, $itemId, $marcado ? 1 : 0]
            );
        } else {
            Database::execute(
                "UPDATE ejecuciones_items SET marcado = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                [$marcado ? 1 : 0, (int) $existente['id']]
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
            "UPDATE ejecuciones_checklist
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
               FROM items_checklist ic
          LEFT JOIN ejecuciones_items ei
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
            "SELECT id FROM items_checklist WHERE template_id = ? AND id IN ($placeholders)",
            array_merge([$templateId], $itemIds)
        );
        $validosIds = array_map(static fn(array $f) => (int) $f['id'], $validos);
        if (count($validosIds) !== count($itemIds)) {
            throw new ChecklistException('ITEMS_DESMARCADOS_INVALIDO', 'Algunos items no pertenecen al template.', 400);
        }

        foreach ($itemIds as $itemId) {
            $ex = Database::fetchOne(
                'SELECT id FROM ejecuciones_items WHERE ejecucion_id = ? AND item_id = ?',
                [$ejecucionId, $itemId]
            );
            if ($ex === null) {
                Database::execute(
                    'INSERT INTO ejecuciones_items (ejecucion_id, item_id, marcado, desmarcado_por_auditor) VALUES (?, ?, 0, 1)',
                    [$ejecucionId, $itemId]
                );
            } else {
                Database::execute(
                    "UPDATE ejecuciones_items SET marcado = 0, desmarcado_por_auditor = 1, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                    [(int) $ex['id']]
                );
            }
        }
    }
}
