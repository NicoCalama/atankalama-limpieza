<?php
/**
 * Ajustes → Colores: editor de los colores de las tarjetas por estado de
 * habitación y por hotel. Permiso: apariencia.editar (Supervisora, Admin).
 *
 * La supervisora elige UN color base por concepto; el backend deriva las
 * variantes claro/oscuro (Helpers\Colores) y el layout las inyecta como CSS
 * custom properties para las clases .chip-estado-* / .hotel-accent-*.
 *
 * Endpoints:
 *  - GET /api/ui-config/colores  { colores, defaults, etiquetas }
 *  - PUT /api/ui-config/colores  { colores: { clave: '#rrggbb' } }
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */
?>

<div x-data="coloresApp()" x-init="cargar()">

    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between max-w-3xl mx-auto gap-3">
            <div class="flex items-center gap-2 min-w-0">
                <a href="<?= u('/ajustes') ?>" aria-label="Volver a Ajustes"
                   class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i data-lucide="arrow-left" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                </a>
                <div class="min-w-0">
                    <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">Colores</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate">Tarjetas por estado y hotel</p>
                </div>
            </div>
            <?php include __DIR__ . '/componentes/boton-tema.php'; ?>
        </div>
    </header>

    <!-- Toast -->
    <div x-show="toast.visible" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="fixed top-20 left-1/2 -translate-x-1/2 z-50 px-4 py-3 rounded-lg shadow-lg text-white text-sm font-medium max-w-sm w-[90%] text-center"
         :class="toast.tipo === 'exito' ? 'bg-green-600' : 'bg-red-600'"
         x-text="toast.mensaje"></div>

    <!-- Carga -->
    <template x-if="cargando && !listo">
        <div class="min-h-[50vh] flex items-center justify-center">
            <div class="flex flex-col items-center gap-3">
                <div class="spinner"></div>
                <p class="text-gray-600 dark:text-gray-400">Cargando...</p>
            </div>
        </div>
    </template>

    <!-- Error de carga (evita la pantalla en blanco si el GET inicial falla) -->
    <template x-if="errorCarga && !listo && !cargando">
        <div class="min-h-[50vh] flex items-center justify-center px-4">
            <div class="text-center max-w-xs">
                <i data-lucide="alert-circle" class="w-12 h-12 text-red-500 mx-auto mb-3"></i>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">No pudimos cargar los colores</h2>
                <p class="text-gray-600 dark:text-gray-400 mb-4">Verifica tu conexión e intenta de nuevo.</p>
                <button @click="cargar()"
                        class="min-h-[44px] px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                    Reintentar
                </button>
            </div>
        </div>
    </template>

    <template x-if="listo">
        <main class="pb-28 md:pb-10 px-4 py-4 max-w-3xl mx-auto space-y-5">

            <p class="text-sm text-gray-600 dark:text-gray-400">
                Elige un color base para cada estado y hotel. La app deriva sola las
                variantes para modo claro y oscuro, y el cambio aplica para todo el equipo.
            </p>

            <!-- Estados -->
            <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 inline-flex items-center gap-2">
                        <i data-lucide="tag" class="w-4 h-4 text-blue-600 dark:text-blue-400"></i>
                        Estados de habitación
                    </h2>
                </div>
                <ul>
                    <template x-for="clave in clavesEstado" :key="clave">
                        <li class="px-4 py-3 border-b border-gray-100 dark:border-gray-700/60 last:border-b-0 flex items-center justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100" x-text="etiquetas[clave]"></p>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium mt-1"
                                      :style="estiloPreviewChip(form[clave])"
                                      x-text="etiquetas[clave]"></span>
                            </div>
                            <input type="color" x-model="form[clave]"
                                   :aria-label="'Color para ' + etiquetas[clave]"
                                   class="w-12 h-12 rounded-lg border border-gray-300 dark:border-gray-600 bg-transparent cursor-pointer flex-shrink-0">
                        </li>
                    </template>
                </ul>
            </section>

            <!-- Hoteles -->
            <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 inline-flex items-center gap-2">
                        <i data-lucide="building-2" class="w-4 h-4 text-teal-600 dark:text-teal-400"></i>
                        Acento por hotel
                    </h2>
                </div>
                <ul>
                    <template x-for="clave in clavesHotel" :key="clave">
                        <li class="px-4 py-3 border-b border-gray-100 dark:border-gray-700/60 last:border-b-0 flex items-center justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100" x-text="etiquetas[clave]"></p>
                                <!-- Mini-tarjeta de preview con borde izquierdo -->
                                <div class="mt-1 rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide inline-block"
                                     :style="estiloPreviewAcento(form[clave])"
                                     x-text="etiquetas[clave]"></div>
                            </div>
                            <input type="color" x-model="form[clave]"
                                   :aria-label="'Color para ' + etiquetas[clave]"
                                   class="w-12 h-12 rounded-lg border border-gray-300 dark:border-gray-600 bg-transparent cursor-pointer flex-shrink-0">
                        </li>
                    </template>
                </ul>
            </section>

            <!-- Acciones -->
            <div class="flex flex-col sm:flex-row gap-2">
                <button @click="guardar()" :disabled="guardando"
                        class="flex-1 min-h-[48px] px-4 py-2 text-sm font-semibold rounded-lg bg-blue-600 hover:bg-blue-700 text-white transition disabled:opacity-50 inline-flex items-center justify-center gap-2">
                    <i data-lucide="save" class="w-4 h-4"></i>
                    <span x-text="guardando ? 'Guardando...' : 'Guardar colores'"></span>
                </button>
                <button @click="restaurar()"
                        class="min-h-[48px] px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition inline-flex items-center justify-center gap-2">
                    <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                    Restaurar originales
                </button>
            </div>
            <p class="text-[11px] text-gray-400">
                «Restaurar originales» vuelve a la paleta inicial de la app; recuerda guardar para aplicarla.
            </p>
        </main>
    </template>
</div>

<script>
function coloresApp() {
    return {
        cargando: false,
        listo: false,
        errorCarga: false,
        guardando: false,
        form: {},
        defaults: {},
        etiquetas: {},
        toast: { visible: false, tipo: 'exito', mensaje: '' },

        get clavesEstado() {
            return Object.keys(this.form).filter(function (k) { return k.indexOf('color_estado_') === 0; });
        },
        get clavesHotel() {
            return Object.keys(this.form).filter(function (k) { return k.indexOf('color_hotel_') === 0; });
        },

        async cargar() {
            this.cargando = true;
            this.errorCarga = false;
            try {
                var r = await apiFetch('/api/ui-config/colores');
                if (r && r.ok) {
                    this.form = Object.assign({}, r.data.colores);
                    this.defaults = r.data.defaults;
                    this.etiquetas = r.data.etiquetas;
                    this.listo = true;
                } else {
                    this.errorCarga = true;
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos cargar los colores.');
                }
            } catch (e) {
                this.errorCarga = true;
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.cargando = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },

        async guardar() {
            if (this.guardando) return;
            this.guardando = true;
            try {
                var r = await apiPut('/api/ui-config/colores', { colores: this.form });
                if (r && r.ok) {
                    this.form = Object.assign({}, r.data.colores);
                    this.aplicarEnVivo();
                    this.mostrarToast('exito', 'Colores guardados. Ya se ven en toda la app.');
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos guardar.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.guardando = false;
            }
        },

        restaurar() {
            this.form = Object.assign({}, this.defaults);
            this.mostrarToast('exito', 'Paleta original cargada — guarda para aplicarla.');
        },

        // --- Derivación de variantes (misma receta que Helpers\Colores en PHP) ---

        rgb(hex) {
            return [
                parseInt(hex.substr(1, 2), 16),
                parseInt(hex.substr(3, 2), 16),
                parseInt(hex.substr(5, 2), 16)
            ];
        },
        mezclar(c, destino, f) {
            return Math.round(c * (1 - f) + destino * f);
        },
        hexDe(r, g, b) {
            var h = function (n) { return ('0' + Math.max(0, Math.min(255, n)).toString(16)).slice(-2); };
            return '#' + h(r) + h(g) + h(b);
        },
        variantes(hex) {
            var c = this.rgb(hex), r = c[0], g = c[1], b = c[2];
            return {
                bg: this.hexDe(this.mezclar(r, 255, 0.85), this.mezclar(g, 255, 0.85), this.mezclar(b, 255, 0.85)),
                fg: this.hexDe(Math.round(r * 0.57), Math.round(g * 0.57), Math.round(b * 0.57)),
                bgDark: 'rgba(' + r + ', ' + g + ', ' + b + ', 0.28)',
                fgDark: this.hexDe(this.mezclar(r, 255, 0.60), this.mezclar(g, 255, 0.60), this.mezclar(b, 255, 0.60))
            };
        },
        acento(hex) {
            var c = this.rgb(hex), r = c[0], g = c[1], b = c[2];
            return {
                borde: hex,
                tinte: 'rgba(' + r + ', ' + g + ', ' + b + ', 0.06)',
                tinteDark: 'rgba(' + r + ', ' + g + ', ' + b + ', 0.10)',
                texto: this.hexDe(Math.round(r * 0.70), Math.round(g * 0.70), Math.round(b * 0.70)),
                textoDark: this.hexDe(this.mezclar(r, 255, 0.45), this.mezclar(g, 255, 0.45), this.mezclar(b, 255, 0.45))
            };
        },

        esDark() {
            return document.documentElement.classList.contains('dark');
        },

        estiloPreviewChip(hex) {
            if (!hex) return '';
            var v = this.variantes(hex);
            return this.esDark()
                ? 'background-color:' + v.bgDark + ';color:' + v.fgDark
                : 'background-color:' + v.bg + ';color:' + v.fg;
        },

        estiloPreviewAcento(hex) {
            if (!hex) return '';
            var a = this.acento(hex);
            var tinte = this.esDark() ? a.tinteDark : a.tinte;
            var texto = this.esDark() ? a.textoDark : a.texto;
            return 'border-left:4px solid ' + a.borde + ';background-color:' + tinte + ';color:' + texto;
        },

        // Reescribe el <style id="ui-colores"> del layout con la paleta recién
        // guardada: el cambio se ve al instante, sin recargar.
        aplicarEnVivo() {
            var st = document.getElementById('ui-colores');
            if (!st) return;
            var claro = [], oscuro = [];
            for (var clave in this.form) {
                var hex = this.form[clave];
                if (clave.indexOf('color_estado_') === 0) {
                    var slug = clave.slice('color_estado_'.length);
                    var v = this.variantes(hex);
                    claro.push('--ce-' + slug + '-bg: ' + v.bg + '; --ce-' + slug + '-fg: ' + v.fg + ';');
                    oscuro.push('--ce-' + slug + '-bg: ' + v.bgDark + '; --ce-' + slug + '-fg: ' + v.fgDark + ';');
                } else {
                    var slugH = clave.slice('color_hotel_'.length);
                    var a = this.acento(hex);
                    claro.push('--ch-' + slugH + '-borde: ' + a.borde + '; --ch-' + slugH + '-tinte: ' + a.tinte + '; --ch-' + slugH + '-texto: ' + a.texto + ';');
                    oscuro.push('--ch-' + slugH + '-tinte: ' + a.tinteDark + '; --ch-' + slugH + '-texto: ' + a.textoDark + ';');
                }
            }
            st.textContent = ':root {\n' + claro.join('\n') + '\n}\n.dark {\n' + oscuro.join('\n') + '\n}';
        },

        mostrarToast(tipo, mensaje) {
            this.toast = { visible: true, tipo: tipo, mensaje: mensaje };
            var self = this;
            setTimeout(function () { self.toast.visible = false; }, 2500);
        }
    };
}
</script>
