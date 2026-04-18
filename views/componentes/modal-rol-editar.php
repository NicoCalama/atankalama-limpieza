<?php
/**
 * Modal reutilizable "Editar rol".
 * Incluir en layout.php (si el usuario tiene permiso permisos.asignar_a_rol).
 *
 * Para abrirlo:
 *   window.dispatchEvent(new CustomEvent('abrir-modal-rol-editar', { detail: { rol: {...} } }))
 *
 * Al éxito emite 'rol-actualizado'.
 * En roles de sistema: solo permite editar descripción (nombre inmutable).
 */
?>
<div x-data="modalRolEditar()"
     @abrir-modal-rol-editar.window="abrir($event.detail || {})">

    <div x-show="abierto" x-cloak
         class="fixed inset-0 z-[60] flex items-end md:items-center justify-center p-4 bg-black/50"
         @click.self="cerrar()">
        <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-5 shadow-xl max-h-[90vh] overflow-y-auto">
            <template x-if="rol">
                <div>
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Editar rol</h3>
                            <template x-if="rol.es_sistema === 1">
                                <p class="text-xs text-amber-600 dark:text-amber-400 mt-0.5">Rol del sistema — solo puedes editar la descripción.</p>
                            </template>
                        </div>
                        <button @click="cerrar()" class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                            <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                        </button>
                    </div>

                    <form @submit.prevent="guardar()" class="space-y-3">
                        <div>
                            <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Nombre *</label>
                            <input type="text" x-model="form.nombre" maxlength="50" required
                                   :disabled="rol.es_sistema === 1"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px] disabled:opacity-60 disabled:cursor-not-allowed">
                        </div>

                        <div>
                            <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Descripción</label>
                            <textarea x-model="form.descripcion" rows="3" maxlength="200"
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
                            <button type="submit" :disabled="enviando || !cambios()"
                                    class="flex-1 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white transition inline-flex items-center justify-center gap-2">
                                <template x-if="enviando">
                                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                </template>
                                <span x-text="enviando ? 'Guardando...' : 'Guardar'"></span>
                            </button>
                        </div>
                    </form>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
function modalRolEditar() {
    return {
        abierto: false,
        enviando: false,
        error: null,
        rol: null,
        form: { nombre: '', descripcion: '' },

        abrir(detail) {
            if (!detail || !detail.rol) return;
            this.rol = detail.rol;
            this.form = {
                nombre: this.rol.nombre || '',
                descripcion: this.rol.descripcion || '',
            };
            this.error = null;
            this.enviando = false;
            this.abierto = true;
            this.$nextTick(function () { lucide.createIcons(); });
        },

        cerrar() {
            this.abierto = false;
        },

        cambios() {
            if (!this.rol) return false;
            return (this.form.nombre || '').trim() !== (this.rol.nombre || '')
                || (this.form.descripcion || '').trim() !== (this.rol.descripcion || '');
        },

        async guardar() {
            if (!this.cambios() || this.enviando) return;
            this.enviando = true;
            this.error = null;
            try {
                var payload = { descripcion: (this.form.descripcion || '').trim() };
                if (this.rol.es_sistema !== 1) {
                    payload.nombre = (this.form.nombre || '').trim();
                }
                var r = await apiPut('/api/roles/' + this.rol.id, payload);
                if (r && r.ok) {
                    window.dispatchEvent(new CustomEvent('rol-actualizado', { detail: r.data }));
                    this.cerrar();
                } else {
                    this.error = (r && r.error && r.error.mensaje) || 'No pudimos guardar.';
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
