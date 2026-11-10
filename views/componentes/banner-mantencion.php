<?php
/**
 * Barra de mantención (v7.22, reunión del 08/11/2026): arriba de cada pantalla, a quien tiene
 * tickets.aviso_mantencion, con sus trabajos abiertos y los tickets sin responsable. Desaparece sola
 * cuando los dos llegan a 0 y lleva a Tickets. Va dentro del contenido, no fija sobre la pantalla:
 * así no tapa los encabezados de cada página. Se incluye desde layout.php.
 *
 * Variable PHP requerida: $usuario.
 */
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Services\AvisoMantencionService;

$__resumenMantencion = null;
try {
    $__resumenMantencion = (new AvisoMantencionService())->resumenDe($usuario->id);
} catch (\Throwable $e) {
    // Un aviso nunca debe tumbar la pantalla.
    Logger::warning('tickets', 'No se pudo calcular la barra de mantención', ['error' => $e->getMessage()]);
}
if ($__resumenMantencion !== null && $__resumenMantencion['mios'] + $__resumenMantencion['sin_responsable'] > 0):
?>
<a href="<?= u('/tickets') ?>" role="status" data-banner-mantencion
   class="flex items-center gap-2 px-4 py-2 min-h-[44px] bg-amber-100 dark:bg-amber-900/40 text-amber-900 dark:text-amber-100 text-sm font-medium border-b border-amber-200 dark:border-amber-800 hover:bg-amber-200/70 dark:hover:bg-amber-900/60 transition">
    <i data-lucide="wrench" class="w-4 h-4 flex-shrink-0"></i>
    <span class="flex-1 min-w-0"><?= htmlspecialchars(AvisoMantencionService::textoResumen($__resumenMantencion), ENT_QUOTES, 'UTF-8') ?></span>
    <span class="flex-shrink-0 text-xs font-semibold underline underline-offset-2">Ver tickets</span>
</a>
<?php endif; ?>
