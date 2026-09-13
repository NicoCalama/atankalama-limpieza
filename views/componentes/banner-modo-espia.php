<?php
/**
 * Banner "modo espía": visible en todas las pantallas mientras un admin ve la
 * app como otro usuario. Se incluye desde layout.php solo si EspiaContext::activo().
 *
 * Variable PHP requerida: $usuario (el usuario objetivo — layout.php ya lo tiene
 * en scope, sustituido por AuthCheck).
 */
use Atankalama\Limpieza\Support\EspiaContext;
$adminNombre = EspiaContext::adminNombre();
?>
<div x-data="bannerModoEspia()"
     class="fixed top-0 left-0 right-0 z-[70] bg-amber-500 text-amber-950 px-4 py-2 text-sm font-medium shadow-md">
    <div class="max-w-5xl mx-auto flex items-center justify-between gap-3">
        <span class="flex items-center gap-2 min-w-0">
            <i data-lucide="eye" class="w-4 h-4 flex-shrink-0"></i>
            <span class="truncate">
                Modo espía: viendo la app como <strong><?= htmlspecialchars($usuario->nombre, ENT_QUOTES, 'UTF-8') ?></strong>
                (solo lectura) — activado por <?= htmlspecialchars((string) $adminNombre, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </span>
        <button type="button" @click="salir()" :disabled="saliendo"
                class="flex-shrink-0 min-h-[32px] px-3 py-1 rounded-lg bg-amber-950 hover:bg-amber-900 text-amber-50 text-xs font-semibold transition disabled:opacity-50">
            <span x-text="saliendo ? 'Saliendo...' : 'Salir del modo espía'"></span>
        </button>
    </div>
</div>

<script>
function bannerModoEspia() {
    return {
        saliendo: false,
        async salir() {
            if (this.saliendo) return;
            this.saliendo = true;
            try {
                var r = await apiPost('/api/modo-espia/salir', {});
                if (r && r.ok) {
                    window.location.href = u('/home');
                } else {
                    this.saliendo = false;
                    alert((r && r.error && r.error.mensaje) || 'No pudimos salir del modo espía.');
                }
            } catch (e) {
                this.saliendo = false;
                alert('No pudimos conectar con el servidor.');
            }
        }
    };
}
</script>
