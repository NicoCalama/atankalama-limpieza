<?php
/**
 * Modal "Importar usuarios desde Excel" — carga masiva desde el calendario
 * semanal .xlsx (columnas DNI, Nombre; el resto de columnas —turnos por
 * fecha— se ignora, eso es alcance del importador de turnos).
 *
 * Para abrirlo desde cualquier parte:
 *   window.dispatchEvent(new CustomEvent('abrir-modal-usuario-importar'))
 *
 * Al importar, emite 'usuario-creado' (mismo evento que el alta manual, para
 * que la lista de usuarios.php se refresque igual).
 */
?>
<div x-data="modalUsuarioImportar()"
     @abrir-modal-usuario-importar.window="abrir()">

    <div x-show="abierto" x-cloak
         class="fixed inset-0 z-[60] flex items-end md:items-center justify-center p-4 bg-black/50"
         @click.self="cerrar()">
        <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-5 shadow-xl max-h-[90vh] overflow-y-auto">

            <!-- Vista 1: subir archivo + elegir rol -->
            <template x-if="paso === 'form'">
                <div>
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Importar usuarios desde Excel</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Calendario semanal (.xlsx) con columnas DNI y Nombre.</p>
                        </div>
                        <button @click="cerrar()" class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                            <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                        </button>
                    </div>

                    <div class="space-y-4">
                        <!-- Archivo -->
                        <div>
                            <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Archivo .xlsx *</label>
                            <label class="block border-2 border-dashed rounded-xl p-6 text-center cursor-pointer transition-colors"
                                   :class="archivoNombre ? 'border-blue-400 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-300 dark:border-gray-600 hover:border-blue-400 hover:bg-gray-50 dark:hover:bg-gray-700/30'"
                                   @dragover.prevent
                                   @drop.prevent="onDrop($event)">
                                <input type="file" accept=".xlsx" class="sr-only" @change="onFileChange($event)">
                                <i data-lucide="file-up" class="w-8 h-8 mx-auto mb-2"
                                   :class="archivoNombre ? 'text-blue-500' : 'text-gray-400'"></i>
                                <p class="text-sm" :class="archivoNombre ? 'font-semibold text-blue-700 dark:text-blue-300' : 'text-gray-600 dark:text-gray-400'"
                                   x-text="archivoNombre || 'Arrastra el .xlsx aquí o haz clic para elegir'"></p>
                            </label>
                        </div>

                        <!-- Rol único para el lote -->
                        <div>
                            <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Rol para todos los usuarios nuevos *</label>
                            <template x-if="rolesDisponibles.length === 0">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Cargando roles...</p>
                            </template>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="r in rolesDisponibles" :key="r.id">
                                    <button type="button" @click="rolId = r.id"
                                            :class="rolId === r.id ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'"
                                            class="min-h-[36px] px-3 py-1.5 text-xs font-medium rounded-full border transition"
                                            x-text="r.nombre"></button>
                                </template>
                            </div>
                        </div>

                        <template x-if="error">
                            <div class="p-3 bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-sm rounded-lg" x-text="error"></div>
                        </template>

                        <div class="flex gap-2 pt-2">
                            <button type="button" @click="cerrar()"
                                    class="flex-1 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition">
                                Cancelar
                            </button>
                            <button type="button" @click="analizar()" :disabled="!archivo || !rolId || cargando"
                                    class="flex-1 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white transition inline-flex items-center justify-center gap-2">
                                <template x-if="cargando">
                                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                </template>
                                <span x-text="cargando ? 'Analizando...' : 'Analizar archivo'"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Vista 2: preview -->
            <template x-if="paso === 'preview' && preview">
                <div>
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Revisar antes de importar</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400" x-text="archivoNombre"></p>
                        </div>
                        <button @click="cerrar()" class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                            <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                        </button>
                    </div>

                    <div class="space-y-4">
                        <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 rounded-xl p-4 text-center">
                            <p class="text-2xl font-bold text-emerald-800 dark:text-emerald-200" x-text="preview.a_crear"></p>
                            <p class="text-xs text-emerald-700 dark:text-emerald-300">usuarios nuevos a crear</p>
                        </div>

                        <template x-if="preview.omitidos_duplicado.length > 0">
                            <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl overflow-hidden">
                                <div class="px-4 py-2 border-b border-amber-200 dark:border-amber-700">
                                    <h4 class="text-xs font-semibold text-amber-800 dark:text-amber-200"
                                        x-text="'Ya existen, se omiten (' + preview.omitidos_duplicado.length + ')'"></h4>
                                </div>
                                <div class="divide-y divide-amber-100 dark:divide-amber-800 max-h-32 overflow-y-auto">
                                    <template x-for="u in preview.omitidos_duplicado" :key="u.rut">
                                        <div class="flex items-center justify-between px-4 py-1.5">
                                            <p class="text-xs text-amber-800 dark:text-amber-200" x-text="u.nombre"></p>
                                            <span class="text-xs text-amber-600 dark:text-amber-400 font-mono" x-text="u.rut"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <template x-if="preview.omitidos_invalido.length > 0">
                            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-xl overflow-hidden">
                                <div class="px-4 py-2 border-b border-red-200 dark:border-red-700">
                                    <h4 class="text-xs font-semibold text-red-800 dark:text-red-200"
                                        x-text="'RUT o nombre inválido, se omiten (' + preview.omitidos_invalido.length + ')'"></h4>
                                </div>
                                <div class="divide-y divide-red-100 dark:divide-red-800 max-h-32 overflow-y-auto">
                                    <template x-for="(u, i) in preview.omitidos_invalido" :key="i">
                                        <div class="flex items-center justify-between px-4 py-1.5">
                                            <p class="text-xs text-red-800 dark:text-red-200" x-text="u.nombre || '(sin nombre)'"></p>
                                            <span class="text-xs text-red-600 dark:text-red-400 font-mono" x-text="u.rut || '(sin rut)'"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <template x-if="error">
                            <div class="p-3 bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-sm rounded-lg" x-text="error"></div>
                        </template>

                        <div class="flex gap-2 pt-2">
                            <button type="button" @click="paso = 'form'; preview = null; error = null"
                                    class="flex-1 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition">
                                Volver
                            </button>
                            <button type="button" @click="confirmar()" :disabled="preview.a_crear === 0 || cargando"
                                    class="flex-1 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white transition inline-flex items-center justify-center gap-2">
                                <template x-if="cargando">
                                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                </template>
                                <span x-text="cargando ? 'Importando...' : 'Confirmar importación (' + preview.a_crear + ')'"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Vista 3: resultado -->
            <template x-if="paso === 'resultado' && resultado">
                <div>
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-semibold text-green-700 dark:text-green-400">Importación lista</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400" x-text="resultado.creados.length + ' usuarios creados'"></p>
                        </div>
                        <button @click="cerrar()" class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                            <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                        </button>
                    </div>

                    <div class="bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 rounded-lg p-3 mb-3">
                        <div class="flex gap-2">
                            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0"></i>
                            <p class="text-sm text-amber-800 dark:text-amber-200">
                                Anota estas contraseñas temporales. <strong>No volverán a mostrarse.</strong>
                            </p>
                        </div>
                    </div>

                    <div class="bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden mb-3">
                        <div class="divide-y divide-gray-200 dark:divide-gray-700 max-h-64 overflow-y-auto">
                            <template x-for="u in resultado.creados" :key="u.id">
                                <div class="flex items-center justify-between px-4 py-2">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate" x-text="u.nombre"></p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 font-mono" x-text="u.rut"></p>
                                    </div>
                                    <code class="text-sm font-mono font-bold text-gray-900 dark:text-gray-100 flex-shrink-0 ml-2" x-text="u.password_temporal"></code>
                                </div>
                            </template>
                        </div>
                    </div>

                    <template x-if="resultado.errores && resultado.errores.length > 0">
                        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-xl overflow-hidden mb-3">
                            <div class="px-4 py-2 border-b border-red-200 dark:border-red-700">
                                <h4 class="text-xs font-semibold text-red-800 dark:text-red-200" x-text="resultado.errores.length + ' errores'"></h4>
                            </div>
                            <div class="divide-y divide-red-100 dark:divide-red-800 max-h-32 overflow-y-auto">
                                <template x-for="(e, i) in resultado.errores" :key="i">
                                    <p class="px-4 py-1.5 text-xs text-red-700 dark:text-red-300 font-mono" x-text="e"></p>
                                </template>
                            </div>
                        </div>
                    </template>

                    <div class="flex gap-2">
                        <button type="button" @click="copiarTodo()"
                                class="flex-1 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition inline-flex items-center justify-center gap-2">
                            <i :data-lucide="copiado ? 'check' : 'copy'" class="w-4 h-4"></i>
                            <span x-text="copiado ? 'Copiado' : 'Copiar todo'"></span>
                        </button>
                        <button type="button" @click="cerrar()"
                                class="flex-1 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 text-white transition">
                            Listo
                        </button>
                    </div>
                </div>
            </template>

        </div>
    </div>
</div>

<script>
function modalUsuarioImportar() {
    return {
        abierto: false,
        paso: 'form',
        cargando: false,
        error: null,
        rolesDisponibles: [],
        _rolesCargados: false,
        rolId: null,
        archivo: null,
        archivoNombre: '',
        token: '',
        preview: null,
        resultado: null,
        copiado: false,

        async abrir() {
            this.reset();
            this.abierto = true;
            await this.cargarRoles();
            this.$nextTick(function () { lucide.createIcons(); });
        },

        cerrar() {
            this.abierto = false;
        },

        reset() {
            this.paso = 'form';
            this.cargando = false;
            this.error = null;
            this.rolId = null;
            this.archivo = null;
            this.archivoNombre = '';
            this.token = '';
            this.preview = null;
            this.resultado = null;
            this.copiado = false;
        },

        async cargarRoles() {
            if (this._rolesCargados) return;
            try {
                var r = await apiFetch('/api/roles');
                if (r && r.ok) {
                    this.rolesDisponibles = r.data.roles || [];
                    this._rolesCargados = true;
                }
            } catch (e) {
                // Si falla, el form muestra "Cargando..."
            }
        },

        onFileChange(e) {
            var file = e.target.files[0];
            if (file) { this.archivo = file; this.archivoNombre = file.name; this.error = null; }
        },

        onDrop(e) {
            var file = e.dataTransfer.files[0];
            if (file) { this.archivo = file; this.archivoNombre = file.name; this.error = null; }
        },

        async analizar() {
            if (!this.archivo || !this.rolId || this.cargando) return;
            this.cargando = true;
            this.error = null;
            try {
                var fd = new FormData();
                fd.append('excel_file', this.archivo);
                var resp = await fetch(u('/api/usuarios/importar/preview'), { method: 'POST', body: fd });
                var json = await resp.json();
                if (!json.ok) { this.error = json.error.mensaje; return; }
                this.preview = json.data;
                this.token   = json.data.token;
                this.paso    = 'preview';
                this.$nextTick(function () { lucide.createIcons(); });
            } catch (e) {
                this.error = 'Error de red. Intenta de nuevo.';
            } finally {
                this.cargando = false;
            }
        },

        async confirmar() {
            if (this.cargando) return;
            this.cargando = true;
            this.error = null;
            try {
                var r = await apiPost('/api/usuarios/importar/confirmar', { token: this.token, rol_id: this.rolId });
                if (!r || !r.ok) { this.error = (r && r.error && r.error.mensaje) || 'No pudimos importar los usuarios.'; return; }
                this.resultado = r.data;
                this.paso      = 'resultado';
                window.dispatchEvent(new CustomEvent('usuario-creado', { detail: r.data }));
                this.$nextTick(function () { lucide.createIcons(); });
            } catch (e) {
                this.error = 'Error de red. Intenta de nuevo.';
            } finally {
                this.cargando = false;
            }
        },

        async copiarTodo() {
            if (!this.resultado) return;
            var lineas = this.resultado.creados.map(function (u) {
                return u.nombre + '\t' + u.rut + '\t' + u.password_temporal;
            });
            try {
                await navigator.clipboard.writeText(lineas.join('\n'));
                this.copiado = true;
                setTimeout(() => { this.copiado = false; }, 2000);
            } catch (e) {
                // No crítico si falla el clipboard
            }
        }
    };
}
</script>
