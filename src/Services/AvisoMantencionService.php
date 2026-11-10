<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Helpers\Fechas;
use Atankalama\Limpieza\Models\Ticket;

/**
 * Aviso a mantención por trabajos abiertos (v7.22, reunión del 08/11/2026). Ver docs/tickets.md §6.2.
 *
 * «Mantención» es quien tiene el permiso tickets.aviso_mantencion (en producción, el rol Mantenimiento),
 * sin mirar nombres de rol. Tiene tres partes:
 *  - la barra de arriba de cada pantalla con sus trabajos abiertos y los sin responsable (resumenDe),
 *  - el aviso de un ticket nuevo que queda sin responsable (avisarTicketNuevo),
 *  - el recordatorio de la mañana, una vez por persona y día (enviarRecordatorioDiario).
 * Los tickets de la habitación de prueba no cuentan ni avisan.
 */
final class AvisoMantencionService
{
    public const PERMISO = 'tickets.aviso_mantencion';

    public const NOTIF_TICKET_NUEVO = 'ticket_nuevo';
    public const NOTIF_RECORDATORIO = 'tickets_pendientes';

    /**
     * Ventana del recordatorio (hora local). Desde las 08:00 y solo en la mañana: después de mediodía
     * ya no es «el recordatorio del día», y un ticket nuevo ya avisa por su cuenta.
     */
    public const HORA_RECORDATORIO_DESDE = '08:00';
    public const HORA_RECORDATORIO_HASTA = '12:00';

    /** Lo que mantención tiene que hacer: lo abierto y lo que alguien empezó. */
    private const ESTADOS_PENDIENTES = [Ticket::ESTADO_ABIERTO, Ticket::ESTADO_EN_PROGRESO];

    public function __construct(
        private readonly PushService $push = new PushService(),
    ) {
    }

    /** @return list<int> personas activas con el permiso */
    public function destinatarios(): array
    {
        return array_map('intval', array_column(Database::fetchAll(
            "SELECT DISTINCT u.id
               FROM #__usuarios u
               JOIN #__usuarios_roles ur ON ur.usuario_id = u.id
               JOIN #__rol_permisos rp ON rp.rol_id = ur.rol_id
              WHERE rp.permiso_codigo = ? AND u.activo = 1 AND u.rut <> 'SISTEMA-CRON'
              ORDER BY u.id",
            [self::PERMISO]
        ), 'id'));
    }

    /**
     * Para la barra y el recordatorio: los trabajos pendientes de la persona y los que nadie tiene.
     *
     * @return array{mios: int, sin_responsable: int}
     */
    public function resumenDe(int $usuarioId): array
    {
        [$enEstados, $params] = $this->filtroPendientes();
        $mios = (int) Database::fetchColumn(
            "SELECT COUNT(*)
               FROM #__tickets t
          LEFT JOIN #__habitaciones h ON h.id = t.habitacion_id
              WHERE {$enEstados}
                AND (h.id IS NULL OR h.es_prueba = 0)
                AND (t.asignado_a = ?
                     OR EXISTS (SELECT 1 FROM #__tickets_asignados ta WHERE ta.ticket_id = t.id AND ta.usuario_id = ?))",
            array_merge($params, [$usuarioId, $usuarioId])
        );
        $sinResponsable = (int) Database::fetchColumn(
            "SELECT COUNT(*)
               FROM #__tickets t
          LEFT JOIN #__habitaciones h ON h.id = t.habitacion_id
              WHERE {$enEstados}
                AND (h.id IS NULL OR h.es_prueba = 0)
                AND t.asignado_a IS NULL
                AND NOT EXISTS (SELECT 1 FROM #__tickets_asignados ta WHERE ta.ticket_id = t.id)",
            $params
        );
        return ['mios' => $mios, 'sin_responsable' => $sinResponsable];
    }

    /**
     * Ticket nuevo que quedó sin responsable: aviso (push + campanita) a toda mantención, salvo a quien
     * lo levantó. Si se creó con responsables, no se llama: avisa asignar() a ellos y a sus supervisores.
     *
     * @return int a cuántas personas se avisó
     */
    public function avisarTicketNuevo(Ticket $ticket, int $levantadoPor, string $dondeTexto): int
    {
        $ids = array_values(array_diff($this->destinatarios(), [$levantadoPor]));
        if ($ids === []) {
            return 0;
        }
        $quien = Database::fetchColumn('SELECT nombre FROM #__usuarios WHERE id = ?', [$levantadoPor]);
        $cuerpo = ($quien !== false && $quien !== null ? (string) $quien : 'Alguien')
            . " lo levantó en {$dondeTexto}. Todavía no tiene responsable.";
        $this->push->notificar(
            $ids,
            "Ticket nuevo: {$ticket->titulo}",
            $cuerpo,
            '/tickets?ticket=' . $ticket->id,
            [],
            false,
            self::NOTIF_TICKET_NUEVO
        );
        return count($ids);
    }

    /**
     * Recordatorio de la mañana: a cada persona de mantención con algo pendiente, una vez por día (si ya
     * tiene el de hoy en la campanita, no se repite). Lo llama el cron en la ventana de la mañana.
     *
     * @return int a cuántas personas se avisó
     */
    public function enviarRecordatorioDiario(string $hoy): int
    {
        $destinatarios = $this->destinatarios();
        if ($destinatarios === []) {
            return 0;
        }
        [$desde, $hasta] = Fechas::rangoUtcDelDia($hoy);
        $placeholders = implode(',', array_fill(0, count($destinatarios), '?'));
        $yaAvisadas = array_map('intval', array_column(Database::fetchAll(
            "SELECT DISTINCT usuario_id
               FROM #__notificaciones
              WHERE usuario_id IN ({$placeholders}) AND tipo = ? AND created_at >= ? AND created_at < ?",
            array_merge($destinatarios, [self::NOTIF_RECORDATORIO, $desde, $hasta])
        ), 'usuario_id'));

        $avisadas = 0;
        foreach (array_diff($destinatarios, $yaAvisadas) as $usuarioId) {
            $r = $this->resumenDe($usuarioId);
            if ($r['mios'] + $r['sin_responsable'] === 0) {
                continue;
            }
            $this->push->notificar(
                [$usuarioId],
                'Trabajos de mantención pendientes',
                self::textoResumen($r) . '.',
                '/tickets',
                [],
                false,
                self::NOTIF_RECORDATORIO
            );
            $avisadas++;
        }
        if ($avisadas > 0) {
            Logger::info('tickets', 'recordatorio de tickets pendientes', ['personas' => $avisadas]);
        }
        return $avisadas;
    }

    /**
     * «Tienes 3 trabajos abiertos · 2 sin responsable». Lo usan la barra y el recordatorio.
     *
     * @param array{mios: int, sin_responsable: int} $r
     */
    public static function textoResumen(array $r): string
    {
        $partes = [];
        if ($r['mios'] > 0) {
            $partes[] = 'Tienes ' . $r['mios'] . ($r['mios'] === 1 ? ' trabajo abierto' : ' trabajos abiertos');
        }
        if ($r['sin_responsable'] > 0) {
            $partes[] = $r['sin_responsable'] . ($r['sin_responsable'] === 1 ? ' ticket sin responsable' : ' tickets sin responsable');
        }
        return implode(' · ', $partes);
    }

    /** @return array{0: string, 1: list<string>} */
    private function filtroPendientes(): array
    {
        $ph = implode(',', array_fill(0, count(self::ESTADOS_PENDIENTES), '?'));
        return ["t.estado IN ({$ph})", self::ESTADOS_PENDIENTES];
    }
}
