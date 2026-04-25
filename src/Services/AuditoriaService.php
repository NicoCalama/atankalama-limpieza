<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Models\EjecucionChecklist;
use Atankalama\Limpieza\Models\AlertaActiva;
use Atankalama\Limpieza\Models\Habitacion;

final class AuditoriaService
{
    public function __construct(
        private readonly HabitacionService $habitaciones = new HabitacionService(),
        private readonly ChecklistService $checklist = new ChecklistService(),
        private readonly ?CloudbedsSyncService $cloudbeds = null,
        private readonly AlertasService $alertas = new AlertasService(),
        private readonly PushService $push = new PushService(),
    ) {
    }

    /**
     * Emite un veredicto sobre la habitación pendiente de auditoría.
     * Inmutable: una habitación/ejecución no puede ser re-auditada (UNIQUE en auditorias.ejecucion_id).
     *
     * @param list<int> $itemsDesmarcados
     */
    public function emitirVeredicto(
        int $habitacionId,
        int $auditorId,
        string $veredicto,
        ?string $comentario = null,
        array $itemsDesmarcados = [],
    ): Auditoria {
        if (!in_array($veredicto, Auditoria::VEREDICTOS_VALIDOS, true)) {
            throw new AuditoriaException('VEREDICTO_INVALIDO', "Veredicto inválido: {$veredicto}.", 400);
        }

        $habitacion = $this->habitaciones->obtener($habitacionId);
        if ($habitacion === null) {
            throw new AuditoriaException('HABITACION_NO_ENCONTRADA', 'Habitación no encontrada.', 404);
        }
        if ($habitacion->estado !== Habitacion::ESTADO_COMPLETADA_PENDIENTE_AUDITORIA) {
            throw new AuditoriaException(
                'HABITACION_NO_PENDIENTE',
                'La habitación no está pendiente de auditoría.',
                409
            );
        }

        $ejecFila = Database::fetchOne(
            "SELECT * FROM ejecuciones_checklist
              WHERE habitacion_id = ? AND estado = 'completada'
              ORDER BY id DESC LIMIT 1",
            [$habitacionId]
        );
        if ($ejecFila === null) {
            throw new AuditoriaException(
                'EJECUCION_NO_COMPLETADA',
                'No hay ejecución completada para auditar.',
                409
            );
        }
        $ejecucion = EjecucionChecklist::desdeFila($ejecFila);

        $existente = Database::fetchOne('SELECT id FROM auditorias WHERE ejecucion_id = ?', [$ejecucion->id]);
        if ($existente !== null) {
            throw new AuditoriaException('AUDITORIA_YA_EXISTE', 'Esta habitación ya fue auditada.', 409);
        }

        $esComentarioRequerido = in_array($veredicto, [
            Auditoria::VEREDICTO_APROBADO_CON_OBSERVACION,
            Auditoria::VEREDICTO_RECHAZADO,
        ], true);

        if ($esComentarioRequerido) {
            if ($comentario === null || strlen(trim($comentario)) < 10) {
                throw new AuditoriaException(
                    'COMENTARIO_REQUERIDO',
                    'El comentario debe tener al menos 10 caracteres.',
                    400
                );
            }
        }

        if ($veredicto === Auditoria::VEREDICTO_APROBADO_CON_OBSERVACION && $itemsDesmarcados !== []) {
            $this->checklist->desmarcarPorAuditor($ejecucion->id, $itemsDesmarcados, $ejecucion->templateId);
        } elseif ($veredicto !== Auditoria::VEREDICTO_APROBADO_CON_OBSERVACION && $itemsDesmarcados !== []) {
            throw new AuditoriaException(
                'ITEMS_DESMARCADOS_NO_APLICABLE',
                'Solo aprobado_con_observacion acepta items_desmarcados.',
                400
            );
        }

        $itemsJson = $itemsDesmarcados === [] ? null : json_encode(array_values($itemsDesmarcados));

        Database::execute(
            'INSERT INTO auditorias (ejecucion_id, habitacion_id, auditor_id, veredicto, comentario, items_desmarcados_json) VALUES (?, ?, ?, ?, ?, ?)',
            [$ejecucion->id, $habitacionId, $auditorId, $veredicto, $comentario, $itemsJson]
        );
        $auditoriaId = Database::lastInsertId();

        Database::execute(
            "UPDATE ejecuciones_checklist SET estado = 'auditada' WHERE id = ?",
            [$ejecucion->id]
        );

        $nuevoEstadoHab = match ($veredicto) {
            Auditoria::VEREDICTO_APROBADO => Habitacion::ESTADO_APROBADA,
            Auditoria::VEREDICTO_APROBADO_CON_OBSERVACION => Habitacion::ESTADO_APROBADA_CON_OBSERVACION,
            Auditoria::VEREDICTO_RECHAZADO => Habitacion::ESTADO_RECHAZADA,
        };
        $this->habitaciones->cambiarEstado($habitacionId, $nuevoEstadoHab, $auditorId, 'ui');

        if ($veredicto === Auditoria::VEREDICTO_APROBADO || $veredicto === Auditoria::VEREDICTO_APROBADO_CON_OBSERVACION) {
            if ($this->cloudbeds !== null) {
                try {
                    $habActualizada = $this->habitaciones->obtener($habitacionId);
                    if ($habActualizada !== null) {
                        $this->cloudbeds->escribirEstadoClean($habActualizada);
                    }
                } catch (\Throwable $e) {
                    Logger::error('auditoria', 'error sincronizando Clean a Cloudbeds', [
                        'habitacion_id' => $habitacionId,
                        'mensaje' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($veredicto === Auditoria::VEREDICTO_RECHAZADO) {
            $this->crearAlertaRechazo($habitacionId, $ejecucion->usuarioId, $comentario);
        }

        Logger::audit($auditorId, 'auditoria.emitir_veredicto', 'auditoria', $auditoriaId, [
            'habitacion_id' => $habitacionId,
            'veredicto' => $veredicto,
            'items_desmarcados' => $itemsDesmarcados,
        ]);

        return Auditoria::desdeFila(Database::fetchOne('SELECT * FROM auditorias WHERE id = ?', [$auditoriaId]));
    }

    public function obtener(int $id): ?Auditoria
    {
        $fila = Database::fetchOne('SELECT * FROM auditorias WHERE id = ?', [$id]);
        return $fila === null ? null : Auditoria::desdeFila($fila);
    }

    public function obtenerDeHabitacion(int $habitacionId): ?Auditoria
    {
        $fila = Database::fetchOne(
            'SELECT * FROM auditorias WHERE habitacion_id = ? ORDER BY id DESC LIMIT 1',
            [$habitacionId]
        );
        return $fila === null ? null : Auditoria::desdeFila($fila);
    }

    /** @return list<array<string, mixed>> */
    public function bandejaPendientes(?string $hotelCodigo = null): array
    {
        $sql = "SELECT h.id, h.numero, h.estado, ho.codigo AS hotel_codigo, th.nombre AS tipo_nombre, ec.id AS ejecucion_id, ec.usuario_id AS trabajador_id
                  FROM habitaciones h
                  JOIN hoteles ho ON ho.id = h.hotel_id
                  JOIN tipos_habitacion th ON th.id = h.tipo_habitacion_id
             LEFT JOIN ejecuciones_checklist ec
                    ON ec.habitacion_id = h.id AND ec.estado = 'completada'
                 WHERE h.estado = 'completada_pendiente_auditoria'
                   AND h.activa = 1";
        $params = [];
        if ($hotelCodigo !== null && $hotelCodigo !== 'ambos') {
            $sql .= ' AND ho.codigo = ?';
            $params[] = $hotelCodigo;
        }
        $sql .= ' ORDER BY ho.codigo, h.numero';
        return Database::fetchAll($sql, $params);
    }

    private function crearAlertaRechazo(int $habitacionId, int $trabajadorId, ?string $comentario): void
    {
        $habFila = Database::fetchOne(
            'SELECT h.numero, h.hotel_id, ho.codigo as hotel_codigo FROM habitaciones h JOIN hoteles ho ON ho.id=h.hotel_id WHERE h.id = ?',
            [$habitacionId]
        );
        $numero      = $habFila['numero'] ?? '?';
        $hotelCodigo = $habFila['hotel_codigo'] ?? '';

        $this->alertas->levantar(
            AlertaActiva::TIPO_HABITACION_RECHAZADA,
            "Habitación {$numero} rechazada",
            $comentario ?? 'Revisar y reasignar.',
            ['habitacion_id' => $habitacionId, 'trabajador_id' => $trabajadorId],
            isset($habFila['hotel_id']) && $habFila['hotel_id'] !== null ? (int) $habFila['hotel_id'] : null,
            "habitacion:{$habitacionId}",
        );

        // Push a todas las supervisoras con permiso alertas.recibir_predictivas
        $supervisoraIds = array_column(
            Database::fetchAll(
                "SELECT DISTINCT u.id FROM usuarios u
                 JOIN usuarios_roles ur ON ur.usuario_id = u.id
                 JOIN roles r ON r.id = ur.rol_id
                 JOIN rol_permisos rp ON rp.rol_id = r.id
                 JOIN permisos p ON p.id = rp.permiso_id
                 WHERE p.codigo = 'alertas.recibir_predictivas' AND u.activo = 1"
            ),
            'id'
        );
        if (!empty($supervisoraIds)) {
            $this->push->notificarRechazo(array_map('intval', $supervisoraIds), (string) $numero, $hotelCodigo);
        }
    }
}
