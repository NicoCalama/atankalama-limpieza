<?php
/**
 * Modal reutilizable para cambio de contraseña del propio usuario.
 * Se abre con: window.dispatchEvent(new CustomEvent('abrir-modal-cambiar-password'))
 * Endpoint: POST /api/auth/cambiar-contrasena
 */
?>

<div x-data="modalCambiarPasswordApp()"
     @abrir-modal-cambiar-password.window="abrir()"
     @keydown.escape.window="cerrar()"
     x-show="abierto" x-cloak
     class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 p-0 sm:p-4"
     x-transition.opacity>

    <div @click.away="cerrar()"
         class="w-full sm:max-w-md bg-white dark:bg-gray-800 rounded-t-2xl sm:rounded-xl shadow-xl max-h-[90vh] overflow-y-auto"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="translate-y-full sm:translate-y-0 sm:opacity-0"
         x-transition:enter-end="translate-y-0 sm:opacity-100">

        <!-- Header -->
        <header class="sticky top-0 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3 flex items-center justify-between">
            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Cambiar contraseña</h2>
            <button type="button" @click="cerrar()" class="min-h-[44px] min-w-[44px] flex items-center justify-center -mr-2" aria-label="Cerrar">
                <i data-lucide="x" class="w-5 h-5 text-gray-500 dark:text-gray-400"></i>
            </button>
        </header>

        <div class="p-4 space-y-4">
            <div>
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Contraseña actual</label>
                <div class="relative">
                    <input :type="ver.actual ? 'text' : 'password'" x-model="form.actual" autocomplete="current-password"
                           class="w-full min-h-[44px] px-3 py-2 pr-10 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <button type="button" @click="ver.actual = !ver.actual"
                            class="absolute inset-y-0 right-0 px-3 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                        <i :data-lucide="ver.actual ? 'eye-off' : 'eye'" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Nueva contraseña</label>
                <div class="relative">
                    <input :type="ver.nueva ? 'text' : 'password'" x-model="form.nueva" autocomplete="new-password"
                           class="w-full min-h-[44px] px-3 py-2 pr-10 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <button type="button" @click="ver.nueva = !ver.nueva"
                            class="absolute inset-y-0 right-0 px-3 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                        <i :data-lucide="ver.nueva ? 'eye-off' : 'eye'" class="w-4 h-4"></i>
                    </button>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Mínimo 8 caracteres.</p>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Confirmar nueva contraseña</label>
                <div class="relative">
                    <input :type="ver.confirmar ? 'text' : 'password'" x-model="form.confirmar" autocomplete="new-password"
                           class="w-full min-h-[44px] px-3 py-2 pr-10 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <button type="button" @click="ver.confirmar = !ver.confirmar"
                            class="absolute inset-y-0 right-0 px-3 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                        <i :data-lucide="ver.confirmar ? 'eye-off' : 'eye'" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>

            <div x-show="error" x-cloak
                 class="bg-rose-50 dark:bg-rose-900/20 border border-rose-200 dark:border-rose-800 text-rose-700 dark:text-rose-300 text-sm rounded-lg px-3 py-2"
                 x-text="error"></div>

            <div x-show="exito" x-cloak
                 class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 text-sm rounded-lg px-3 py-2">
                Contraseña actualizada correctamente.
            </div>
        </div>

        <!-- Footer -->
        <footer class="sticky bottom-0 bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 px-4 py-3 flex items-center justify-end gap-2">
            <button type="button" @click="cerrar()"
                    class="min-h-[44px] px-4 py-2 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 text-sm font-medium transition">
                Cerrar
            </button>
            <button type="button" @click="enviar()" :disabled="!puedeEnviar() || guardando"
                    class="min-h-[44px] inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium transition">
                <template x-if="guardando">
                    <svg class="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle>
                        <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" class="opacity-75"></path>
                    </svg>
                </template>
                <span x-text="guardando ? 'Guardando...' : 'Cambiar contraseña'"></span>
            </button>
        </footer>
    </div>
</div>

<script>
function modalCambiarPasswordApp() {
    return {
        abierto: false,
        guardando: false,
        error: '',
        exito: false,
        form: { actual: '', nueva: '', confirmar: '' },
        ver: { actual: false, nueva: false, confirmar: false },

        abrir() {
            this.form = { actual: '', nueva: '', confirmar: '' };
            this.ver = { actual: false, nueva: false, confirmar: false };
            this.error = '';
            this.exito = false;
            this.abierto = true;
            this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
        },

        cerrar() {
            this.abierto = false;
        },

        puedeEnviar() {
            return this.form.actual.length > 0
                && this.form.nueva.length >= 8
                && this.form.nueva === this.form.confirmar;
        },

        async enviar() {
            if (!this.puedeEnviar() || this.guardando) return;
            this.error = '';
            this.exito = false;
            this.guardando = true;
            try {
                const res = await apiPost('/api/auth/cambiar-contrasena', {
                    password_actual: this.form.actual,
                    password_nueva: this.form.nueva,
                    password_nueva_confirmacion: this.form.confirmar,
                });
                if (!res.ok) {
                    this.error = res.error?.mensaje || 'No se pudo cambiar la contraseña.';
                    return;
                }
                this.exito = true;
                setTimeout(() => { this.cerrar(); }, 1500);
            } catch (e) {
                this.error = 'Error de red, intenta de nuevo.';
            } finally {
                this.guardando = false;
            }
        },
    };
}
</script>
