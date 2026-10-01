<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Logger;
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
 */
final class CierreDiaService
{
    public const COMENTARIO = 'Auto-aprobada por cierre de día (23:55) — sin auditoría real. Ver docs de la habitación.';

    public function __construct(
        private readonly AuditoriaService $auditorias = new AuditoriaService(),
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
            } catch (AuditoriaException $e) {
                $fallidas++;
                Logger::error('auditoria', 'cierre de día automático: fallo al auto-aprobar', [
                    'habitacion_id' => $fila['id'],
                    'numero' => $fila['numero'],
                    'codigo' => $e->codigo,
                    'mensaje' => $e->getMessage(),
                ]);
            }
        }
        return ['aprobadas' => $aprobadas, 'fallidas' => $fallidas];
    }
}
