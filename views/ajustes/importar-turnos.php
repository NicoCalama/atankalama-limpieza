<?php
/**
 * Importador de turnos desde CSV de Breik.
 * Flujo 3 pasos: subir → preview → resultado.
 */
?>

<div x-data="importarTurnos()" class="min-h-screen bg-gray-50 dark:bg-gray-900">

    <!-- Header sticky -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center gap-3 max-w-3xl mx-auto">
            <a href="/ajustes/turnos"
               class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500 dark:text-gray-400">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>
            <div>
                <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Importar turnos desde Breik</h1>
                <p class="text-xs text-gray-500 dark:text-gray-400">Reporte de turnos planificados (.csv)</p>
            </div>
        </div>
    </header>

    <div class="max-w-3xl mx-auto px-4 py-6 space-y-6 pb-24">

        <!-- ─── Indicador de pasos ─────────────────────────────────── -->
        <div class="flex items-center gap-0">
            <template x-for="(paso, i) in ['Subir archivo', 'Revisar', 'Listo']" :key="i">
                <div class="flex items-center flex-1 last:flex-none">
                    <div class="flex flex-col items-center">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold transition-colors"
                             :class="step > i + 1 ? 'bg-emerald-500 text-white' : step === i + 1 ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-500'">
                            <template x-if="step > i + 1">
                                <i data-lucide="check" class="w-4 h-4"></i>
                            </template>
                            <template x-if="step <= i + 1">
                                <span x-text="i + 1"></span>
                            </template>
                        </div>
                        <span class="text-xs mt-1 text-gray-500 dark:text-gray-400 whitespace-nowrap" x-text="paso"></span>
                    </div>
                    <div x-show="i < 2" class="flex-1 h-0.5 mx-2 mb-4"
                         :class="step > i + 1 ? 'bg-emerald-400' : 'bg-gray-200 dark:bg-gray-700'"></div>
                </div>
            </template>
        </div>

        <!-- ─── Paso 1: Subir archivo ─────────────────────────────── -->
        <template x-if="step === 1">
            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6 space-y-5">

                <div>
                    <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">¿Cómo exportar desde Breik?</h2>
                    <ol class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-400 list-decimal list-inside">
                        <li>En Breik → Reportes → Turnos planificados</li>
                        <li>Selecciona el rango de fechas</li>
                        <li>Exportar → CSV</li>
                        <li>Sube ese archivo aquí</li>
                    </ol>
                </div>

                <!-- Zona drag & drop -->
                <label
                    class="block border-2 border-dashed rounded-xl p-8 text-center cursor-pointer transition-colors"
                    :class="archivoNombre ? 'border-blue-400 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-300 dark:border-gray-600 hover:border-blue-400 hover:bg-gray-50 dark:hover:bg-gray-700/30'"
                    @dragover.prevent
                    @drop.prevent="onDrop($event)">
                    <input type="file" accept=".csv,.txt" class="sr-only" @change="onFileChange($event)">
                    <i data-lucide="file-up" class="w-10 h-10 mx-auto mb-3"
                       :class="archivoNombre ? 'text-blue-500' : 'text-gray-400'"></i>
                    <template x-if="!archivoNombre">
                        <div>
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Arrastra el CSV aquí o haz clic para seleccionar</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Formato: Reporte_turnos_planificados.csv de Breik (máx. 5 MB)</p>
                        </div>
                    </template>
                    <template x-if="archivoNombre">
                        <div>
                            <p class="text-sm font-semibold text-blue-700 dark:text-blue-300" x-text="archivoNombre"></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Clic para cambiar archivo</p>
                        </div>
                    </template>
                </label>

                <template x-if="error">
                    <div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-700 rounded-xl p-4 text-sm text-red-700 dark:text-red-300"
                         x-text="error"></div>
                </template>

                <button @click="analizarArchivo()"
                        :disabled="!archivo || cargando"
                        class="w-full min-h-[48px] bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold rounded-xl transition flex items-center justify-center gap-2">
                    <template x-if="cargando">
                        <svg class="animate-spin w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                    </template>
                    <span x-text="cargando ? 'Analizando...' : 'Analizar archivo'"></span>
                </button>
            </div>
        </template>

        <!-- ─── Paso 2: Preview ───────────────────────────────────── -->
        <template x-if="step === 2 && preview">
            <div class="space-y-5">

                <!-- Cards resumen -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center">
                        <p class="text-2xl font-bold text-gray-900 dark:text-gray-100" x-text="preview.a_importar"></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Turnos nuevos</p>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center">
                        <p class="text-2xl font-bold text-gray-900 dark:text-gray-100" x-text="preview.usuarios_encontrados.length"></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Personas</p>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center">
                        <p class="text-2xl font-bold text-gray-900 dark:text-gray-100"
                           x-text="preview.rango_fechas.desde ? formatFecha(preview.rango_fechas.desde) : '—'"></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Desde</p>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center">
                        <p class="text-2xl font-bold text-gray-900 dark:text-gray-100"
                           x-text="preview.rango_fechas.hasta ? formatFecha(preview.rango_fechas.hasta) : '—'"></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Hasta</p>
                    </div>
                </div>

                <!-- Usuarios encontrados -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700 flex items-center gap-2">
                        <i data-lucide="user-check" class="w-4 h-4 text-emerald-600 dark:text-emerald-400"></i>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                            Personas encontradas en el sistema
                            <span class="ml-1 text-xs font-normal text-emerald-600 dark:text-emerald-400"
                                  x-text="'(' + preview.usuarios_encontrados.length + ')'"></span>
                        </h3>
                    </div>
                    <div class="divide-y divide-gray-100 dark:divide-gray-700 max-h-60 overflow-y-auto">
                        <template x-for="u in preview.usuarios_encontrados" :key="u.id">
                            <div class="flex items-center justify-between px-4 py-2.5">
                                <div>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100" x-text="u.nombre"></p>
                                    <p class="text-xs text-gray-400 dark:text-gray-500" x-text="u.rut"></p>
                                </div>
                                <span class="text-xs bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 px-2 py-0.5 rounded-full"
                                      x-text="u.turnos + ' turnos'"></span>
                            </div>
                        </template>
                        <template x-if="preview.usuarios_encontrados.length === 0">
                            <div class="px-4 py-6 text-sm text-gray-400 text-center">Ninguna persona del archivo coincide con usuarios del sistema.</div>
                        </template>
                    </div>
                </div>

                <!-- Usuarios NO encontrados (si hay) -->
                <template x-if="preview.usuarios_no_encontrados.length > 0">
                    <div class="bg-amber-50 dark:bg-amber-900/20 rounded-2xl border border-amber-200 dark:border-amber-700 overflow-hidden">
                        <div class="px-4 py-3 border-b border-amber-200 dark:border-amber-700 flex items-center gap-2">
                            <i data-lucide="alert-triangle" class="w-4 h-4 text-amber-600 dark:text-amber-400"></i>
                            <h3 class="text-sm font-semibold text-amber-800 dark:text-amber-200">
                                Personas no encontradas (se omitirán)
                                <span class="ml-1 text-xs font-normal"
                                      x-text="'(' + preview.usuarios_no_encontrados.length + ')'"></span>
                            </h3>
                        </div>
                        <div class="divide-y divide-amber-100 dark:divide-amber-800 max-h-48 overflow-y-auto">
                            <template x-for="u in preview.usuarios_no_encontrados" :key="u.rut">
                                <div class="flex items-center justify-between px-4 py-2.5">
                                    <p class="text-sm text-amber-800 dark:text-amber-200" x-text="u.nombre"></p>
                                    <span class="text-xs text-amber-600 dark:text-amber-400 font-mono" x-text="u.rut"></span>
                                </div>
                            </template>
                        </div>
                        <p class="px-4 py-2 text-xs text-amber-700 dark:text-amber-300">
                            Estas personas no están registradas en el sistema. Sus turnos no se importarán.
                        </p>
                    </div>
                </template>

                <!-- Turnos nuevos a crear (si hay) -->
                <template x-if="preview.turnos_nuevos.length > 0">
                    <div class="bg-blue-50 dark:bg-blue-900/20 rounded-2xl border border-blue-200 dark:border-blue-700 overflow-hidden">
                        <div class="px-4 py-3 border-b border-blue-200 dark:border-blue-700 flex items-center gap-2">
                            <i data-lucide="clock" class="w-4 h-4 text-blue-600 dark:text-blue-400"></i>
                            <h3 class="text-sm font-semibold text-blue-800 dark:text-blue-200">
                                Tipos de turno nuevos a crear en catálogo
                                <span class="ml-1 text-xs font-normal"
                                      x-text="'(' + preview.turnos_nuevos.length + ')'"></span>
                            </h3>
                        </div>
                        <div class="divide-y divide-blue-100 dark:divide-blue-800 max-h-48 overflow-y-auto">
                            <template x-for="t in preview.turnos_nuevos" :key="t.hora_inicio + t.hora_fin">
                                <div class="flex items-center justify-between px-4 py-2.5">
                                    <div>
                                        <p class="text-sm text-blue-800 dark:text-blue-200" x-text="t.nombre"></p>
                                        <template x-if="t.cruza_medianoche">
                                            <span class="text-xs text-blue-600 dark:text-blue-400">↪ Cruza medianoche</span>
                                        </template>
                                    </div>
                                    <span class="text-xs font-mono text-blue-700 dark:text-blue-300"
                                          x-text="t.hora_inicio + ' → ' + t.hora_fin"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <!-- Ya existentes -->
                <template x-if="preview.ya_existentes > 0">
                    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-4">
                        <div class="flex items-start gap-3">
                            <i data-lucide="refresh-cw" class="w-5 h-5 text-gray-400 flex-shrink-0 mt-0.5"></i>
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                    <span x-text="preview.ya_existentes"></span> turnos ya existen para esas fechas
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">¿Qué hacer con los duplicados?</p>
                                <div class="mt-3 flex gap-3">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="radio" name="modo" x-model="reemplazar" :value="false" class="accent-blue-600">
                                        <span class="text-sm text-gray-700 dark:text-gray-300">Saltar (mantener existentes)</span>
                                    </label>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="radio" name="modo" x-model="reemplazar" :value="true" class="accent-blue-600">
                                        <span class="text-sm text-gray-700 dark:text-gray-300">Reemplazar</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <template x-if="error">
                    <div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-700 rounded-xl p-4 text-sm text-red-700 dark:text-red-300"
                         x-text="error"></div>
                </template>

                <!-- Acciones -->
                <div class="flex gap-3">
                    <button @click="step = 1; preview = null; error = null"
                            class="flex-1 min-h-[48px] border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-medium rounded-xl hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        Volver
                    </button>
                    <button @click="confirmar()"
                            :disabled="preview.a_importar === 0 || cargando"
                            class="flex-1 min-h-[48px] bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold rounded-xl transition flex items-center justify-center gap-2">
                        <template x-if="cargando">
                            <svg class="animate-spin w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                        </template>
                        <span x-text="cargando ? 'Importando...' : 'Confirmar importación (' + preview.a_importar + ')'"></span>
                    </button>
                </div>
            </div>
        </template>

        <!-- ─── Paso 3: Resultado ─────────────────────────────────── -->
        <template x-if="step === 3 && resultado">
            <div class="space-y-5">

                <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 rounded-2xl p-6 text-center">
                    <div class="w-14 h-14 rounded-full bg-emerald-100 dark:bg-emerald-800 flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="check-circle" class="w-8 h-8 text-emerald-600 dark:text-emerald-400"></i>
                    </div>
                    <p class="text-xl font-bold text-emerald-800 dark:text-emerald-200">
                        <span x-text="resultado.importados"></span> turnos importados
                    </p>
                    <p class="text-sm text-emerald-700 dark:text-emerald-300 mt-1"
                       x-show="resultado.omitidos > 0"
                       x-text="resultado.omitidos + ' duplicados omitidos'"></p>
                </div>

                <template x-if="resultado.errores && resultado.errores.length > 0">
                    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-2xl overflow-hidden">
                        <div class="px-4 py-3 border-b border-red-200 dark:border-red-700 flex items-center gap-2">
                            <i data-lucide="x-circle" class="w-4 h-4 text-red-600"></i>
                            <h3 class="text-sm font-semibold text-red-800 dark:text-red-200"
                                x-text="resultado.errores.length + ' errores'"></h3>
                        </div>
                        <div class="divide-y divide-red-100 dark:divide-red-800 max-h-48 overflow-y-auto">
                            <template x-for="(e, i) in resultado.errores" :key="i">
                                <p class="px-4 py-2 text-xs text-red-700 dark:text-red-300 font-mono" x-text="e"></p>
                            </template>
                        </div>
                    </div>
                </template>

                <div class="flex gap-3">
                    <button @click="resetear()"
                            class="flex-1 min-h-[48px] border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-medium rounded-xl hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        Importar otro archivo
                    </button>
                    <a href="/ajustes/turnos"
                       class="flex-1 min-h-[48px] bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl transition flex items-center justify-center">
                        Ver turnos
                    </a>
                </div>
            </div>
        </template>

    </div>
</div>

<script>
function importarTurnos() {
    return {
        step:       1,
        archivo:    null,
        archivoNombre: '',
        preview:    null,
        resultado:  null,
        token:      '',
        reemplazar: false,
        cargando:   false,
        error:      null,

        onFileChange(e) {
            var file = e.target.files[0];
            if (file) { this.archivo = file; this.archivoNombre = file.name; this.error = null; }
        },

        onDrop(e) {
            var file = e.dataTransfer.files[0];
            if (file) { this.archivo = file; this.archivoNombre = file.name; this.error = null; }
        },

        async analizarArchivo() {
            if (!this.archivo) return;
            this.cargando = true;
            this.error = null;
            try {
                var fd = new FormData();
                fd.append('csv_file', this.archivo);
                var resp = await fetch('/api/turnos/importar/preview', { method: 'POST', body: fd });
                var json = await resp.json();
                if (!json.ok) { this.error = json.error.mensaje; return; }
                this.preview = json.data;
                this.token   = json.data.token;
                this.step    = 2;
                this.$nextTick(function() { lucide.createIcons(); });
            } catch (e) {
                this.error = 'Error de red. Intenta de nuevo.';
            } finally {
                this.cargando = false;
            }
        },

        async confirmar() {
            this.cargando = true;
            this.error = null;
            try {
                var resp = await fetch('/api/turnos/importar/confirmar', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ token: this.token, reemplazar: this.reemplazar }),
                });
                var json = await resp.json();
                if (!json.ok) { this.error = json.error.mensaje; return; }
                this.resultado = json.data;
                this.step      = 3;
                this.$nextTick(function() { lucide.createIcons(); });
            } catch (e) {
                this.error = 'Error de red. Intenta de nuevo.';
            } finally {
                this.cargando = false;
            }
        },

        resetear() {
            this.step          = 1;
            this.archivo       = null;
            this.archivoNombre = '';
            this.preview       = null;
            this.resultado     = null;
            this.token         = '';
            this.reemplazar    = false;
            this.error         = null;
        },

        formatFecha(iso) {
            if (!iso) return '';
            var p = iso.split('-');
            return p[2] + '/' + p[1] + '/' + p[0];
        },
    };
}
</script>
