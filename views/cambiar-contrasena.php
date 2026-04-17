<?php
/**
 * Pantalla de cambio de contraseña forzado (post-login).
 * Se muestra cuando requiere_cambio_pwd = true.
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */
?>

<main class="min-h-screen flex items-center justify-center p-4" x-data="cambiarPwdApp()">
    <div class="w-full max-w-sm">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
            <div class="text-center mb-4">
                <div class="w-12 h-12 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center mx-auto mb-3">
                    <i data-lucide="key-round" class="w-6 h-6 text-amber-600 dark:text-amber-400"></i>
                </div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Cambiar contraseña</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Debes crear una contraseña nueva antes de continuar.</p>
            </div>

            <form @submit.prevent="enviar()">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Contraseña actual</label>
                        <input type="password"
                               x-model="passwordActual"
                               autocomplete="current-password"
                               class="w-full min-h-[44px] px-3 py-2 text-base bg-gray-50 dark:bg-gray-700
                                      border border-gray-300 dark:border-gray-600 rounded-lg
                                      text-gray-900 dark:text-gray-100
                                      focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nueva contraseña</label>
                        <input type="password"
                               x-model="passwordNueva"
                               placeholder="Mínimo 8 caracteres, 1 letra y 1 número"
                               autocomplete="new-password"
                               class="w-full min-h-[44px] px-3 py-2 text-base bg-gray-50 dark:bg-gray-700
                                      border border-gray-300 dark:border-gray-600 rounded-lg
                                      text-gray-900 dark:text-gray-100
                                      focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p x-show="passwordNueva && !validarPwd()" class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                            Mínimo 8 caracteres, al menos 1 letra y 1 número
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Confirmar nueva contraseña</label>
                        <input type="password"
                               x-model="passwordConfirm"
                               autocomplete="new-password"
                               class="w-full min-h-[44px] px-3 py-2 text-base bg-gray-50 dark:bg-gray-700
                                      border border-gray-300 dark:border-gray-600 rounded-lg
                                      text-gray-900 dark:text-gray-100
                                      focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p x-show="passwordConfirm && passwordNueva !== passwordConfirm" class="text-xs text-red-600 dark:text-red-400 mt-1">
                            Las contraseñas no coinciden
                        </p>
                    </div>

                    <div x-show="error" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3">
                        <p x-text="error" class="text-sm text-red-700 dark:text-red-400"></p>
                    </div>

                    <button type="submit"
                            :disabled="!puedeEnviar() || cargando"
                            class="w-full min-h-[44px] bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg
                                   transition-colors flex items-center justify-center gap-2
                                   disabled:opacity-50 disabled:cursor-not-allowed">
                        <template x-if="cargando">
                            <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                        </template>
                        <span x-text="cargando ? 'Guardando...' : 'Cambiar contraseña'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
function cambiarPwdApp() {
    return {
        passwordActual: '',
        passwordNueva: '',
        passwordConfirm: '',
        cargando: false,
        error: '',

        validarPwd() {
            var p = this.passwordNueva;
            return p.length >= 8 && /[a-zA-Z]/.test(p) && /[0-9]/.test(p);
        },

        puedeEnviar() {
            return this.passwordActual.length > 0
                && this.validarPwd()
                && this.passwordNueva === this.passwordConfirm;
        },

        async enviar() {
            this.cargando = true;
            this.error = '';
            try {
                var resp = await fetch('/api/auth/cambiar-contrasena', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        password_actual: this.passwordActual,
                        password_nueva: this.passwordNueva,
                        password_nueva_confirmacion: this.passwordConfirm
                    })
                });
                var data = await resp.json();
                if (data.ok) {
                    window.location.href = '/home';
                } else {
                    this.error = data.error?.mensaje || 'Error al cambiar contraseña.';
                }
            } catch (e) {
                this.error = 'No pudimos conectar con el servidor. Intenta de nuevo.';
            } finally {
                this.cargando = false;
            }
        }
    };
}
</script>
