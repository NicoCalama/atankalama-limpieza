<?php
/**
 * Ajustes → Hora de la tarde (v7.23, reunión del 08/11/2026): desde esa hora las piezas Día/Noche vigentes que ya
 * quedaron aprobadas vuelven a sucias para su segundo aseo. Antes era 16:00 fijo. Solo Admin
 * (habitaciones.configurar_hora_tarde, lo chequea PaginasController; decisión de Nicolás, 10/11/2026).
 * Ver HoraTardeService y docs/nocheros.md.
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */
?>

<div x-data="ajustesHoraTardeApp()" x-init="cargar()">

    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between gap-3 max-w-3xl mx-auto">
            <div class="flex items-center gap-3 min-w-0">
                <a href="<?= u('/ajustes') ?>" class="min-h-[44px] min-w-[44px] flex items-center justify-center -ml-2" aria-label="Volver">
                    <i data-lucide="arrow-left" class="w-5 h-5 text-gray-700 dark:text-gray-300"></i>
                </a>
                <div class="min-w-0">
                    <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Hora de la tarde</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Desde cuándo las piezas Día/Noche vuelven a sucias</p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <?php include __DIR__ . '/componentes/boton-tema.php'; ?>
            </div>
        </div>
    </header>

    <main class="pb-24 md:pb-8 px-4 py-4 max-w-3xl mx-auto space-y-6">

        <!-- Cargando -->
        <div x-show="cargando && !listo" class="flex flex-col items-center justify-center py-16">
            <div class="w-8 h-8 border-4 border-blue-200 border-t-blue-600 rounded-full animate-spin"></div>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-3">Cargando...</p>
        </div>

        <div x-show="errorCarga && !cargando" x-cloak class="text-center py-12">
            <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">No pudimos cargar la hora.</p>
            <button @click="cargar()" class="min-h-[44px] px-4 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold">Reintentar</button>
        </div>

        <template x-if="listo">
            <div class="space-y-6">
                <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 space-y-4">
                    <div>
                        <label for="hora-tarde" class="block text-sm font-semibold text-gray-900 dark:text-gray-100 mb-1">Hora de la tarde</label>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">
                            Entre las <span x-text="minima"></span> y las <span x-text="maxima"></span>, en cuartos de hora. La de siempre es <span x-text="porDefecto"></span>.
                        </p>
                        <div class="flex items-center gap-2">
                            <input id="hora-tarde" type="time" x-model="hora" :min="minima" :max="maxima" step="900"
                                   class="w-36 min-h-[44px] px-3 py-2 text-base border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg tabular-nums">
                            <button type="button" @click="guardar()" :disabled="guardando || hora === guardada"
                                    class="min-h-[44px] px-4 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold transition disabled:opacity-50 disabled:cursor-not-allowed">
                                <span x-text="guardando ? 'Guardando...' : 'Guardar'"></span>
                            </button>
                        </div>
                        <p x-show="error" x-cloak class="mt-2 text-sm text-red-600 dark:text-red-400" x-text="error"></p>
                    </div>

                    <div class="text-sm text-gray-600 dark:text-gray-300 space-y-2 border-t border-gray-200 dark:border-gray-700 pt-3">
                        <p><strong>Qué hace:</strong> desde esta hora, cada pieza con la chapa <strong>Día/Noche</strong> que ya quedó aprobada vuelve a sucia (una vez al día) para su segundo aseo, y se le avisa a Cloudbeds.</p>
                        <p>Las piezas <strong>Noche</strong> tienen un solo aseo, en la tarde: no vuelven a sucias por esta hora.</p>
                        <p>El cambio rige desde la próxima pasada del sistema (cada 10 minutos). El Registro de días pasados y Reportes no cambian: tienen su propio corte.</p>
                    </div>
                </section>
            </div>
        </template>

        <!-- Toast -->
        <div x-show="toast.visible" x-cloak x-transition
             :class="toast.tipo === 'error' ? 'bg-rose-600' : 'bg-emerald-600'"
             class="fixed bottom-20 left-1/2 -translate-x-1/2 text-white px-4 py-2 rounded-lg shadow-lg text-sm z-50"
             x-text="toast.mensaje"></div>
    </main>
</div>

<script>
function ajustesHoraTardeApp() {
    return {
        cargando: false,
        errorCarga: false,
        listo: false,
        hora: '',
        guardada: '',
        porDefecto: '16:00',
        minima: '12:00',
        maxima: '20:00',
        guardando: false,
        error: '',
        toast: { visible: false, mensaje: '', tipo: 'ok' },
        toastTimer: null,

        async cargar() {
            this.cargando = true;
            this.errorCarga = false;
            try {
                var r = await apiFetch('/api/nocheros/hora-tarde');
                if (!r || !r.ok) {
                    this.errorCarga = true;
                    return;
                }
                this.hora = r.data.hora;
                this.guardada = r.data.hora;
                this.porDefecto = r.data.por_defecto;
                this.minima = r.data.minima;
                this.maxima = r.data.maxima;
                this.listo = true;
            } catch (e) {
                this.errorCarga = true;
            } finally {
                this.cargando = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },

        mostrarToast(mensaje, tipo) {
            var self = this;
            this.toast = { visible: true, mensaje: mensaje, tipo: tipo || 'ok' };
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(function () { self.toast.visible = false; }, 2500);
        },

        async guardar() {
            if (this.guardando || this.hora === this.guardada) return;
            this.guardando = true;
            this.error = '';
            try {
                // El input de hora del navegador puede traer segundos («16:30:00»): el servidor espera HH:MM.
                var r = await apiPut('/api/nocheros/hora-tarde', { hora: (this.hora || '').slice(0, 5) });
                if (r && r.ok) {
                    this.hora = r.data.hora;
                    this.guardada = r.data.hora;
                    this.mostrarToast('Guardado: desde las ' + r.data.hora);
                } else {
                    this.error = (r && r.error && r.error.mensaje) || 'No pudimos guardar la hora, intenta de nuevo en un momento.';
                }
            } catch (e) {
                this.error = 'No pudimos guardar la hora, intenta de nuevo en un momento.';
            } finally {
                this.guardando = false;
            }
        }
    };
}
</script>
