<?php
/**
 * Modal reutilizable "Nuevo rol".
 * Incluir en layout.php (si el usuario tiene permiso permisos.asignar_a_rol).
 *
 * Para abrirlo: window.dispatchEvent(new CustomEvent('abrir-modal-rol-nuevo'))
 * Al éxito emite 'rol-creado'.
 */
?>
<div x-data="modalRolNuevo()"
     @abrir-modal-rol-nuevo.window="abrir()">

    <div x-show="abierto" x-cloak
         class="fixed inset-0 z-[60] flex items-end md:items-center justify-center p-4 bg-black/50"
         @click.self="cerrar()">
        <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-5 shadow-xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Nuevo rol</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Después podrás asignarle permisos en la matriz.</p>
                </div>
                <button @click="cerrar()" class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                    <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                </button>
            </div>

            <form @submit.prevent="crear()" class="space-y-3">
                <div>
                    <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Nombre *</label>
                    <input type="text" x-model="form.nombre" maxlength="50" required
                           placeholder="Ej: Jefa de turno"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px]">
                </div>

                <div>
                    <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Descripción (opcional)</label>
                    <textarea x-model="form.descripcion" rows="2" maxlength="200"
                              placeholder="Breve descripción del rol..."
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm"></textarea>
                </div>

                <template x-if="error">
                    <div class="p-3 bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-sm rounded-lg" x-text="error"></div>
                </template>

                <div class="flex gap-2 pt-2">
                    <button type="button" @click="cerrar()"
                            class="flex-1 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition">
                        Cancelar
                    </button>
                    <button type="submit" :disabled="enviando || !formValido()"
                            class="flex-1 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white transition inline-flex items-center justify-center gap-2">
                        <template x-if="enviando">
                            <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                        </template>
                        <span x-text="enviando ? 'Creando...' : 'Crear rol'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function modalRolNuevo() {
    return {
        abierto: false,
        enviando: false,
        error: null,
        form: { nombre: '', descripcion: '' },

        abrir() {
            this.form = { nombre: '', descripcion: '' };
            this.error = null;
            this.enviando = false;
            this.abierto = true;
            this.$nextTick(function () { lucide.createIcons(); });
        },

        cerrar() {
            this.abierto = false;
        },

        formValido() {
            return (this.form.nombre || '').trim().length >= 2;
        },

        async crear() {
            if (!this.formValido() || this.enviando) return;
            this.enviando = true;
            this.error = null;
            try {
                var payload = {
                    nombre: (this.form.nombre || '').trim(),
                    descripcion: (this.form.descripcion || '').trim() || null,
                    permisos: [],
                };
                var r = await apiPost('/api/roles', payload);
                if (r && r.ok) {
                    window.dispatchEvent(new CustomEvent('rol-creado', { detail: r.data }));
                    this.cerrar();
                } else {
                    this.error = (r && r.error && r.error.mensaje) || 'No pudimos crear el rol.';
                }
            } catch (e) {
                this.error = 'No pudimos conectar con el servidor.';
            } finally {
                this.enviando = false;
            }
        }
    };
}
</script>
