<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\AlertaActiva;
use Atankalama\Limpieza\Models\EjecucionChecklist;
use Atankalama\Limpieza\Models\Habitacion;

final class ChecklistService
{
    public function __construct(
        private readonly HabitacionService $habitaciones = new HabitacionService(),
        private readonly AsignacionService $asignaciones = new AsignacionService(),
        private readonly ?AlertasPredictivasService $predictivas = null,
        private readonly ?AlertasService $alertas = null,
    ) {
    }

    // ----- Templates -----

    /** @return list<array<string, mixed>> */
    public function listarTemplates(): array
    {
        // Solo templates de tipo (piezas de huésped). Los de espacio (habitacion_id != NULL)
        // se editan desde la pantalla de áreas comunes, no acá. Ver docs/areas-comunes.md
        // items_count / creditos_total (solo obligatorios activos) alimentan las tarjetas del editor.
        return Database::fetchAll(
            'SELECT ct.*, th.nombre AS tipo_nombre,
                    (SELECT COUNT(*) FROM #__items_checklist ic
                      WHERE ic.template_id = ct.id AND ic.activo = 1) AS items_count,
                    (SELECT COALESCE(SUM(ic.creditos), 0) FROM #__items_checklist ic
                      WHERE ic.template_id = ct.id AND ic.activo = 1 AND ic.obligatorio = 1) AS creditos_total
               FROM #__checklists_template ct
               JOIN #__tipos_habitacion th ON th.id = ct.tipo_habitacion_id
              WHERE ct.activo = 1 AND ct.habitacion_id IS NULL
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

    /**
     * Edita los ítems de un template de TIPO (pieza de huésped): descripción, orden, obligatorio,
     * peso de créditos y la etiqueta de sábanas. Los ítems con id se actualizan IN-PLACE (así las
     * ejecuciones en progreso, que leen el template en vivo filtrando activo=1, quedan coherentes),
     * los nuevos se insertan y los quitados se desactivan (nunca DELETE: FK RESTRICT desde
     * ejecuciones históricas). Los templates de espacio (habitacion_id != NULL) se editan desde
     * áreas comunes, no acá. Requiere permiso checklists.editar (gateado en el Kernel).
     *
     * @param list<array<string, mixed>> $items cada uno: {id?, descripcion, obligatorio, creditos, es_cambio_sabanas?}
     */
    public function editarTemplate(int $templateId, ?string $nombre, array $items, ?int $actorId = null): void
    {
        $template = Database::fetchOne(
            'SELECT id FROM #__checklists_template WHERE id = ? AND habitacion_id IS NULL AND activo = 1',
            [$templateId]
        );
        if ($template === null) {
            throw new ChecklistException('TEMPLATE_NO_ENCONTRADO', 'El checklist no existe o no se edita desde acá.', 404);
        }

        $normalizados = $this->normalizarItemsTemplate($items);
        if ($normalizados === []) {
            throw new ChecklistException('CHECKLIST_VACIO', 'El checklist debe tener al menos un ítem.', 400);
        }

        // Ids que el cliente conserva: deben pertenecer a este template (activos o no) para no
        // pisar ítems de otro checklist por un id inyectado.
        $idsEnviados = [];
        foreach ($normalizados as $it) {
            if (isset($it['id'])) {
                $idsEnviados[] = $it['id'];
            }
        }
        if ($idsEnviados !== []) {
            $ph = implode(',', array_fill(0, count($idsEnviados), '?'));
            $delTemplate = Database::fetchAll(
                "SELECT id FROM #__items_checklist WHERE template_id = ? AND id IN ($ph)",
                array_merge([$templateId], $idsEnviados)
            );
            $validos = array_map(static fn(array $f) => (int) $f['id'], $delTemplate);
            foreach ($idsEnviados as $idEnv) {
                if (!in_array($idEnv, $validos, true)) {
                    throw new ChecklistException('ITEM_AJENO', 'Un ítem no pertenece a este checklist.', 400);
                }
            }
        }

        Database::transaction(function () use ($templateId, $nombre, $normalizados, $idsEnviados): void {
            if ($nombre !== null && trim($nombre) !== '') {
                Database::execute(
                    "UPDATE #__checklists_template SET nombre = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                    [trim($nombre), $templateId]
                );
            }

            // Primero se desactivan los ítems activos que ya no vienen en el payload (los quitados).
            // Va ANTES de insertar los nuevos: si se hiciera después, la cláusula "id NOT IN
            // ($idsEnviados)" apagaría a los recién insertados (sus ids no están en la lista enviada).
            if ($idsEnviados === []) {
                Database::execute('UPDATE #__items_checklist SET activo = 0 WHERE template_id = ? AND activo = 1', [$templateId]);
            } else {
                $ph = implode(',', array_fill(0, count($idsEnviados), '?'));
                Database::execute(
                    "UPDATE #__items_checklist SET activo = 0 WHERE template_id = ? AND activo = 1 AND id NOT IN ($ph)",
                    array_merge([$templateId], $idsEnviados)
                );
            }

            // Luego se actualizan in-place los conservados y se insertan los nuevos, en el orden
            // del arreglo (la posición fija el campo orden).
            $orden = 1;
            foreach ($normalizados as $it) {
                if (isset($it['id'])) {
                    Database::execute(
                        'UPDATE #__items_checklist
                            SET orden = ?, descripcion = ?, obligatorio = ?, creditos = ?, es_cambio_sabanas = ?, activo = 1
                          WHERE id = ? AND template_id = ?',
                        [$orden, $it['descripcion'], $it['obligatorio'], $it['creditos'], $it['es_cambio_sabanas'], $it['id'], $templateId]
                    );
                } else {
                    Database::execute(
                        'INSERT INTO #__items_checklist (template_id, orden, descripcion, obligatorio, creditos, es_cambio_sabanas, activo)
                         VALUES (?, ?, ?, ?, ?, ?, 1)',
                        [$templateId, $orden, $it['descripcion'], $it['obligatorio'], $it['creditos'], $it['es_cambio_sabanas']]
                    );
                }
                $orden++;
            }
        });

        Logger::audit($actorId, 'checklist.editar_template', 'checklists_template', $templateId, [
            'items' => count($normalizados),
        ]);
    }

    /**
     * Valida y normaliza los ítems del editor. Descarta filas sin descripción; cada ítem queda con
     * descripción (≤255), obligatorio (0/1), creditos (0..100; solo cuenta para créditos si es
     * obligatorio) y es_cambio_sabanas (0/1). El orden lo fija la posición en el arreglo.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array{id?:int, descripcion:string, obligatorio:int, creditos:int, es_cambio_sabanas:int}>
     */
    private function normalizarItemsTemplate(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $descripcion = trim((string) ($item['descripcion'] ?? ''));
            if ($descripcion === '') {
                continue; // fila vacía: se ignora
            }
            if (mb_strlen($descripcion) > 255) {
                $descripcion = mb_substr($descripcion, 0, 255);
            }

            $obligatorio = $this->esVerdadero($item['obligatorio'] ?? true) ? 1 : 0;
            // Los opcionales no dan crédito: se normaliza a 0 en el servidor (no solo en la UI),
            // para no dejar peso "sucio" en la BD si un cliente no-UI manda creditos > 0 sin obligatorio.
            $creditos = $obligatorio === 1 ? max(0, min(100, (int) ($item['creditos'] ?? 1))) : 0;

            $norm = [
                'descripcion' => $descripcion,
                'obligatorio' => $obligatorio,
                'creditos' => $creditos,
                'es_cambio_sabanas' => $this->esVerdadero($item['es_cambio_sabanas'] ?? false) ? 1 : 0,
            ];
            $id = $item['id'] ?? null;
            if ($id !== null && (int) $id > 0) {
                $norm['id'] = (int) $id;
            }
            $out[] = $norm;
        }
        return $out;
    }

    /** Interpreta booleanos que pueden llegar como bool, int o string (JSON o form). */
    private function esVerdadero(mixed $valor): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }
        if (is_int($valor)) {
            return $valor !== 0;
        }
        return in_array(strtolower((string) $valor), ['1', 'true', 'yes', 'on'], true);
    }

    public function templateParaTipo(int $tipoHabitacionId): ?int
    {
        // habitacion_id IS NULL: solo templates "de tipo" (piezas), no los propios de un espacio.
        $fila = Database::fetchOne(
            'SELECT id FROM #__checklists_template WHERE tipo_habitacion_id = ? AND habitacion_id IS NULL AND activo = 1 ORDER BY id LIMIT 1',
            [$tipoHabitacionId]
        );
        return $fila === null ? null : (int) $fila['id'];
    }

    /**
     * Resuelve el template de una habitación: primero el propio del espacio (área común), y si no
     * tiene, cae al template de su tipo (pieza de huésped). Ver docs/areas-comunes.md
     */
    public function templateParaHabitacion(Habitacion $habitacion): ?int
    {
        $fila = Database::fetchOne(
            'SELECT id FROM #__checklists_template WHERE habitacion_id = ? AND activo = 1 ORDER BY id LIMIT 1',
            [$habitacion->id]
        );
        if ($fila !== null) {
            return (int) $fila['id'];
        }
        return $this->templateParaTipo($habitacion->tipoHabitacionId);
    }

    // ----- Ejecución -----

    /**
     * Inicia ejecución del checklist para una habitación asignada al trabajador.
     * Idempotente: si ya existe una ejecución 'en_progreso' la retorna (reanuda).
     */
    public function iniciarEjecucion(int $habitacionId, int $usuarioId, string $fecha, bool $exigirOrden = false): EjecucionChecklist
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

        // Candado "una habitación a la vez": el trabajador no puede iniciar una
        // habitación nueva si ya tiene otra en progreso. Reanudar la misma sí se
        // permite (se resuelve arriba). Ver docs/checklist.md y docs/home-trabajador.md.
        //
        // Se acota a las ejecuciones que cuelgan de una asignación ACTIVA de la MISMA
        // fecha (join a asignaciones), en simetría con obtenerEjecucionEnProgresoDeCola:
        //  - Otra fecha: una ejecución huérfana de un turno anterior no aparece hoy en
        //    la cola y no es alcanzable para terminarla ni saltarla.
        //  - Asignación inactiva (a.activa=0): si la habitación fue reasignada a otra
        //    persona, la ejecución del trabajador queda huérfana y tampoco es saltable
        //    ni completable; contarla en el candado lo dejaría trabado sin salida.
        $otraEnProgreso = Database::fetchOne(
            "SELECT ec.habitacion_id
               FROM #__ejecuciones_checklist ec
               JOIN #__asignaciones a ON a.id = ec.asignacion_id
              WHERE ec.usuario_id = ? AND ec.estado = 'en_progreso'
                AND ec.habitacion_id != ? AND a.fecha = ? AND a.activa = 1
              ORDER BY ec.id DESC LIMIT 1",
            [$usuarioId, $habitacionId, $fecha]
        );
        if ($otraEnProgreso !== null) {
            throw new ChecklistException(
                'YA_TIENE_HABITACION_EN_PROGRESO',
                'Ya tienes una habitación en curso. Termínala o salta antes de empezar otra.',
                409
            );
        }

        // Orden obligatorio (flujo "una habitación a la vez"): el trabajador solo
        // puede iniciar la habitación que le toca ahora (la primera pendiente de su
        // cola), no adelantarse a otra por URL/API directa. No aplica a roles con
        // habitaciones.ver_todas (el controller pasa $exigirOrden=false para ellos).
        // Reanudar la misma habitación en progreso ya se resolvió arriba ($existente).
        if ($exigirOrden) {
            $actual = $this->asignaciones->habitacionActualDeCola($usuarioId, $fecha);
            if ($actual === null || (int) $actual['habitacion_id'] !== $habitacionId) {
                throw new ChecklistException(
                    'NO_ES_TU_HABITACION_ACTUAL',
                    'Debes empezar por tu habitación actual.',
                    409
                );
            }
        }

        if ($habitacion->estado !== Habitacion::ESTADO_SUCIA && $habitacion->estado !== Habitacion::ESTADO_RECHAZADA && $habitacion->estado !== Habitacion::ESTADO_EN_PROGRESO) {
            throw new ChecklistException('ESTADO_INVALIDO_PARA_INICIAR', 'La habitación no está en un estado que permita iniciar.', 409);
        }

        $templateId = $this->templateParaHabitacion($habitacion);
        if ($templateId === null) {
            throw new ChecklistException('TEMPLATE_NO_ENCONTRADO', 'No hay checklist template para esta habitación.', 500);
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
     * Como obtenerEjecucionEnProgreso, pero exige que la ejecución cuelgue de una
     * asignación ACTIVA de la fecha dada (la que el trabajador tiene hoy en su cola).
     * Descarta ejecuciones huérfanas —de turnos anteriores o de asignaciones ya
     * reasignadas— que no deben ser saltables, para no revertir a 'sucia' una
     * habitación que ya limpió y auditó otra persona.
     */
    private function obtenerEjecucionEnProgresoDeCola(int $habitacionId, int $usuarioId, string $fecha): ?int
    {
        $fila = Database::fetchOne(
            "SELECT ec.id
               FROM #__ejecuciones_checklist ec
               JOIN #__asignaciones a ON a.id = ec.asignacion_id
              WHERE ec.habitacion_id = ? AND ec.usuario_id = ? AND ec.estado = 'en_progreso'
                AND a.fecha = ? AND a.activa = 1
              ORDER BY ec.id DESC LIMIT 1",
            [$habitacionId, $usuarioId, $fecha]
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
            "SELECT ic.id, ic.orden, ic.descripcion, ic.obligatorio, ic.es_cambio_sabanas,
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

        // Áreas comunes no pasan por auditoría: se auto-cierran (en_progreso → aprobada = "listo").
        // Las piezas de huésped quedan pendientes de auditoría. Ver docs/areas-comunes.md
        $habitacion = $this->habitaciones->obtener($ejec->habitacionId);
        $esEspacio = $habitacion !== null && $habitacion->esEspacioComun;
        $estadoDestino = $esEspacio
            ? Habitacion::ESTADO_APROBADA
            : Habitacion::ESTADO_COMPLETADA_PENDIENTE_AUDITORIA;
        $this->habitaciones->cambiarEstado($ejec->habitacionId, $estadoDestino, $usuarioId, 'ui');

        Logger::audit($usuarioId, 'checklist.completar', 'ejecucion_checklist', $ejecucionId, [
            'habitacion_id' => $ejec->habitacionId, 'es_espacio_comun' => $esEspacio,
        ]);

        // Si esta habitación/espacio había sido saltado hoy, terminarlo hace que la
        // condición desaparezca: se resuelve la alerta P2 para que no quede colgada en la
        // bandeja de la supervisora. Ver docs/home-supervisora.md (ciclo de vida de alertas).
        $alertas = $this->alertas ?? new AlertasService();
        $alertas->resolverPorDedupe(
            AlertaActiva::TIPO_HABITACION_SALTADA,
            "saltada:{$ejec->habitacionId}"
        );

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

    /**
     * Válvula de escape: el trabajador no puede terminar la habitación actual
     * (huésped no salió, falta insumo, etc.). Cierra la ejecución en progreso,
     * devuelve la habitación a 'sucia' y la manda al final de su cola para poder
     * reintentarla más tarde en el turno. La alerta a la supervisora la levanta
     * el controller.
     *
     * @return array{habitacion_id:int, motivo:string}
     */
    public function saltarEjecucion(int $habitacionId, int $usuarioId, string $motivo, string $fecha): array
    {
        // Solo se puede saltar la ejecución en curso que cuelga de una asignación
        // ACTIVA de hoy (la que el trabajador ve en su cola). Acotarlo evita saltar
        // una ejecución huérfana que revertiría a 'sucia' una habitación ya auditada
        // por otra persona (inmutabilidad post-auditoría). Ver docs/home-trabajador.md §7.
        $ejecId = $this->obtenerEjecucionEnProgresoDeCola($habitacionId, $usuarioId, $fecha);
        if ($ejecId === null) {
            throw new ChecklistException(
                'EJECUCION_NO_ENCONTRADA',
                'No tienes esta habitación en curso.',
                404
            );
        }

        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new ChecklistException('MOTIVO_REQUERIDO', 'Indica un motivo para saltar la habitación.', 400);
        }
        // Cap de largo server-side (coincide con el maxlength=200 del textarea del modal):
        // sin esto, por API se podría enviar un texto enorme que se persiste tres veces
        // (audit_log, alertas_activas, bitacora_alertas) y ensucia la bandeja de alertas.
        if (mb_strlen($motivo) > 200) {
            $motivo = mb_substr($motivo, 0, 200);
        }

        $alertas = $this->alertas ?? new AlertasService();

        // Todas las escrituras del salto son atómicas: si algo falla a mitad,
        // se revierte y la habitación no queda en un estado inconsistente
        // (ejecución borrada pero habitación aún 'en_progreso', o sin alerta).
        Database::transaction(function () use ($habitacionId, $usuarioId, $motivo, $fecha, $ejecId, $alertas): void {
            // Se descarta el progreso parcial: la ejecución abandonada se elimina
            // (sus items caen por cascade). Cuando el trabajador la retome, empieza
            // desde cero. El registro del salto queda en audit_log y en la alerta.
            Logger::audit($usuarioId, 'checklist.saltar', 'ejecucion_checklist', $ejecId, [
                'habitacion_id' => $habitacionId,
                'motivo' => $motivo,
            ]);

            Database::execute('DELETE FROM #__ejecuciones_items WHERE ejecucion_id = ?', [$ejecId]);
            Database::execute('DELETE FROM #__ejecuciones_checklist WHERE id = ?', [$ejecId]);

            // La habitación vuelve a estar disponible para limpiar.
            $this->habitaciones->cambiarEstado($habitacionId, Habitacion::ESTADO_SUCIA, $usuarioId, 'ui');

            // Al final de la cola del trabajador, para que reaparezca después.
            $this->asignaciones->enviarAlFinalDeCola($habitacionId, $usuarioId, $fecha, $usuarioId);

            // Alerta a la supervisora (P2). El trabajador nunca la ve.
            $trabajador = Database::fetchOne('SELECT nombre FROM #__usuarios WHERE id = ?', [$usuarioId]);
            $hab = Database::fetchOne('SELECT numero, hotel_id, es_espacio_comun FROM #__habitaciones WHERE id = ?', [$habitacionId]);
            $nombreTrab = $trabajador['nombre'] ?? 'Un trabajador';
            $numero = $hab['numero'] ?? (string) $habitacionId;
            $hotelId = isset($hab['hotel_id']) ? (int) $hab['hotel_id'] : null;
            // Los espacios comunes van por la misma cola que las piezas (funcionan como
            // habitaciones), pero el texto se adapta para que no diga "Habitación Piscina".
            $esEspacio = (bool) ($hab['es_espacio_comun'] ?? false);
            $tituloAlerta = $esEspacio ? "Espacio {$numero} saltado" : "Habitación {$numero} saltada";
            $lugar = $esEspacio ? "el espacio {$numero}" : "la habitación {$numero}";

            $alertas->levantar(
                AlertaActiva::TIPO_HABITACION_SALTADA,
                $tituloAlerta,
                "{$nombreTrab} no pudo terminar {$lugar}: {$motivo}.",
                ['habitacion_id' => $habitacionId, 'usuario_id' => $usuarioId, 'motivo' => $motivo],
                $hotelId,
                // Dedupe por habitación (sin fecha): una habitación saltada es una
                // condición por pieza, y así completar() puede resolverla sin adivinar
                // la fecha exacta del salto. Consistente con habitacion_rechazada.
                "saltada:{$habitacionId}",
            );
        });

        return ['habitacion_id' => $habitacionId, 'motivo' => $motivo];
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
