<?php
/**
 * Vista de Áreas comunes (espacios). Ver docs/areas-comunes.md
 *
 * Espacios que no son habitaciones de huésped (piscina, pasillos, patio, bodega…), con checklist
 * propio, servicio on-demand y sin auditoría (se auto-cierran al completar).
 *
 * Endpoints:
 *  - GET    /api/espacios?hotel=              { espacios, trabajadores, fecha }
 *  - GET    /api/espacios/{id}                { espacio, items }
 *  - POST   /api/espacios                     { nombre, hotel, items:[{descripcion, creditos}] }
 *  - PUT    /api/espacios/{id}                { nombre, items:[{descripcion, creditos}] }
 *  - DELETE /api/espacios/{id}
 *  - POST   /api/espacios/{id}/pedir-limpieza { usuario_id, fecha }
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */
?>

<div x-data="espaciosApp()" x-init="cargar(); iniciarRefresco();"
     @visibilitychange.window="alVolverVisible()">

    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between max-w-5xl mx-auto gap-3">
            <div class="flex items-center gap-2 min-w-0">
                <a href="<?= u('/home') ?>" aria-label="Volver"
                   class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i data-lucide="arrow-left" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                </a>
                <div class="min-w-0">
                    <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">Áreas comunes</p>
                    <div class="relative" x-data="{ abierto: false }" @click.outside="abierto = false">
                        <button @click="abierto = !abierto"
                                class="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 inline-flex items-center gap-1">
                            <span x-text="etiquetaHotel()"></span>
                            <i data-lucide="chevron-down" class="w-3 h-3"></i>
                        </button>
                        <div x-show="abierto" x-cloak
                             class="absolute left-0 top-full mt-1 z-50 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg overflow-hidden min-w-[180px]">
                            <template x-for="op in hotelOpciones" :key="op.valor">
                                <button @click="setHotel(op.valor); abierto = false"
                                        :class="hotel === op.valor ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300' : 'text-gray-700 dark:text-gray-200'"
                                        class="block w-full text-left px-4 py-2.5 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 min-h-[44px]">
                                    <span x-text="op.etiqueta"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-1 flex-shrink-0">
                <button @click="cargar()" :disabled="cargando"
                        class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800"
                        aria-label="Refrescar">
                    <i data-lucide="rotate-cw" class="w-5 h-5 text-gray-600 dark:text-gray-400"
                       :class="cargando ? 'animate-spin' : ''"></i>
                </button>
                <?php include __DIR__ . '/componentes/boton-tema.php'; ?>
            </div>
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

    <!-- Carga inicial -->
    <template x-if="cargando && !data">
        <div class="min-h-[60vh] flex items-center justify-center">
            <div class="flex flex-col items-center gap-3">
                <svg class="animate-spin h-8 w-8 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <p class="text-gray-600 dark:text-gray-400">Cargando...</p>
            </div>
        </div>
    </template>

    <!-- Error -->
    <template x-if="error && !data">
        <div class="min-h-[60vh] flex items-center justify-center px-4">
            <div class="text-center max-w-xs">
                <i data-lucide="alert-circle" class="w-12 h-12 text-red-500 mx-auto mb-3"></i>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">Error al cargar</h2>
                <p class="text-gray-600 dark:text-gray-400 mb-4" x-text="error"></p>
                <button @click="cargar()" class="min-h-[44px] px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                    Reintentar
                </button>
            </div>
        </div>
    </template>

    <template x-if="data">
        <main class="pb-32 md:pb-8 px-4 py-4 max-w-5xl mx-auto space-y-4">

            <!-- Barra de acción -->
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 inline-flex items-center gap-2">
                    <i data-lucide="building-2" class="w-4 h-4 text-teal-600 dark:text-teal-400"></i>
                    Espacios
                    <span class="text-xs bg-teal-100 dark:bg-teal-900/40 text-teal-800 dark:text-teal-200 px-2 py-0.5 rounded-full"
                          x-text="data.espacios.length"></span>
                </h2>
                <template x-if="puedeEditar">
                    <button @click="abrirCrear()"
                            class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-teal-600 hover:bg-teal-700 text-white transition inline-flex items-center gap-1.5">
                        <i data-lucide="plus" class="w-4 h-4"></i> Nueva área
                    </button>
                </template>
            </div>

            <!-- Vacío -->
            <template x-if="data.espacios.length === 0">
                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-6 text-center">
                    <i data-lucide="building-2" class="w-10 h-10 text-gray-400 mx-auto mb-2"></i>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">Aún no hay áreas comunes en este hotel.</p>
                    <template x-if="puedeEditar">
                        <button @click="abrirCrear()" class="min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-teal-600 hover:bg-teal-700 text-white transition">
                            Crear la primera
                        </button>
                    </template>
                </div>
            </template>

            <!-- Lista -->
            <template x-if="data.espacios.length > 0">
                <div class="grid gap-3 sm:grid-cols-2">
                    <template x-for="esp in data.espacios" :key="esp.id">
                        <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 flex flex-col gap-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-semibold text-gray-900 dark:text-gray-100 truncate" x-text="esp.numero"></p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400" x-text="esp.hotel_nombre + ' · ' + esp.items_count + ' ítem' + (esp.items_count == 1 ? '' : 's') + ' · ' + (esp.creditos_total || 0) + ' crédito' + (esp.creditos_total == 1 ? '' : 's')"></p>
                                </div>
                                <span class="text-[11px] px-2 py-0.5 rounded-full flex-shrink-0" :class="claseBadge(esp.estado)" x-text="etiquetaEstado(esp.estado)"></span>
                            </div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <template x-if="puedePedir">
                                    <button @click="abrirPedir(esp)"
                                            class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 text-white transition inline-flex items-center gap-1.5">
                                        <i data-lucide="brush" class="w-4 h-4"></i> Pedir limpieza
                                    </button>
                                </template>
                                <template x-if="puedeEditar">
                                    <button @click="abrirEditar(esp)"
                                            class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition inline-flex items-center gap-1.5">
                                        <i data-lucide="pencil" class="w-4 h-4"></i> Editar
                                    </button>
                                </template>
                                <template x-if="puedeEditar">
                                    <button @click="archivar(esp)" aria-label="Archivar"
                                            class="min-h-[40px] min-w-[40px] flex items-center justify-center rounded-lg hover:bg-red-50 dark:hover:bg-red-900/30 text-gray-500 hover:text-red-600 dark:text-gray-400 transition">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
        </main>
    </template>

    <!-- Modal: crear / editar -->
    <div x-show="modalForm.abierto" x-cloak
         class="fixed inset-0 z-50 flex items-end md:items-center justify-center p-4 bg-black/50"
         @click.self="cerrarForm()">
        <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-5 shadow-xl max-h-[85vh] overflow-y-auto">
            <div class="flex items-start justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100"
                    x-text="form.id ? 'Editar área común' : 'Nueva área común'"></h3>
                <button @click="cerrarForm()" class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                    <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                </button>
            </div>

            <div class="space-y-4">
                <div>
                    <label class="block text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-1">Nombre</label>
                    <input x-model="form.nombre" type="text" maxlength="20" placeholder="Ej: Piscina, Pasillo 2º piso"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px]">
                    <p class="text-[11px] text-gray-400 mt-1">Máx. 20 caracteres.</p>
                </div>

                <div x-show="!form.id">
                    <label class="block text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-1">Hotel</label>
                    <select x-model="form.hotel"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px]">
                        <option value="1_sur">Atankalama</option>
                        <option value="inn">Atankalama Inn</option>
                    </select>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide">Checklist</label>
                        <span class="text-[11px] text-gray-400">Qué debe hacer quien limpia</span>
                    </div>
                    <div class="space-y-2">
                        <template x-for="(item, idx) in form.items" :key="idx">
                            <div class="flex items-center gap-2">
                                <input x-model="item.descripcion" type="text" maxlength="200"
                                       :placeholder="'Ítem ' + (idx + 1) + ' — ej: limpiar vidrios'"
                                       class="flex-1 min-w-0 px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px]">
                                <input x-model.number="item.creditos" type="number" min="0" max="100"
                                       title="Créditos (peso en KPI)" aria-label="Créditos del ítem"
                                       class="w-16 px-2 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px] text-center">
                                <button @click="quitarItem(idx)" aria-label="Quitar ítem"
                                        class="min-h-[40px] min-w-[40px] flex items-center justify-center rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 transition">
                                    <i data-lucide="x" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </template>
                    </div>
                    <div class="flex items-center justify-between mt-2">
                        <button @click="agregarItem()"
                                class="text-sm text-teal-600 dark:text-teal-400 hover:underline inline-flex items-center gap-1">
                            <i data-lucide="plus" class="w-4 h-4"></i> Agregar ítem
                        </button>
                        <span class="text-[11px] text-gray-400">
                            Créditos: peso del ítem en KPIs · total <span class="font-semibold" x-text="totalCreditos()"></span>
                        </span>
                    </div>
                </div>
            </div>

            <div class="flex gap-2 mt-5">
                <button @click="cerrarForm()"
                        class="flex-1 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition">
                    Cancelar
                </button>
                <button @click="guardar()" :disabled="modalForm.enviando"
                        class="flex-1 min-h-[44px] px-4 py-2 text-sm font-semibold rounded-lg bg-teal-600 hover:bg-teal-700 text-white transition disabled:opacity-50">
                    <span x-text="modalForm.enviando ? 'Guardando...' : 'Guardar'"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: pedir limpieza -->
    <div x-show="modalPedir.abierto" x-cloak
         class="fixed inset-0 z-50 flex items-end md:items-center justify-center p-4 bg-black/50"
         @click.self="cerrarPedir()">
        <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-5 shadow-xl max-h-[85vh] overflow-y-auto">
            <div class="flex items-start justify-between mb-3">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        Pedir limpieza: <span x-text="modalPedir.espacio?.numero"></span>
                    </h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Se asignará al trabajador que elijas para hoy.</p>
                </div>
                <button @click="cerrarPedir()" class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                    <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                </button>
            </div>
            <template x-if="data && data.trabajadores.length === 0">
                <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-4">No hay trabajadores con turno hoy en este hotel.</p>
            </template>
            <template x-if="data && data.trabajadores.length > 0">
                <ul class="space-y-1.5 max-h-[55vh] overflow-y-auto">
                    <template x-for="tr in data.trabajadores" :key="tr.id">
                        <li>
                            <button @click="confirmarPedir(tr)" :disabled="modalPedir.enviando"
                                    class="w-full flex items-center gap-3 px-3 py-2 bg-gray-50 dark:bg-gray-700/50 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded-lg transition disabled:opacity-50 text-left">
                                <span x-html="avatarUsuario(tr)"></span>
                                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate" x-text="tr.nombre"></span>
                            </button>
                        </li>
                    </template>
                </ul>
            </template>
        </div>
    </div>
</div>

<script>
function espaciosApp() {
    return {
        data: null,
        cargando: false,
        error: null,
        hotel: localStorage.getItem('espacios_hotel') || 'ambos',
        _intervalId: null,
        toast: { visible: false, tipo: 'exito', mensaje: '' },
        modalForm: { abierto: false, enviando: false },
        form: { id: null, nombre: '', hotel: '1_sur', items: [{ descripcion: '', creditos: 1 }] },
        modalPedir: { abierto: false, enviando: false, espacio: null },

        hotelOpciones: [
            { valor: 'ambos', etiqueta: 'Ambos hoteles' },
            { valor: '1_sur', etiqueta: 'Atankalama' },
            { valor: 'inn', etiqueta: 'Atankalama Inn' }
        ],

        get puedeEditar() {
            var a = Alpine.store('auth');
            return !!(a && typeof a.tienePermiso === 'function' && a.tienePermiso('espacios.crear_editar'));
        },
        get puedePedir() {
            var a = Alpine.store('auth');
            return !!(a && typeof a.tienePermiso === 'function' && a.tienePermiso('espacios.pedir_limpieza'));
        },
        get fechaHoy() {
            return window.hoyServidor();
        },

        async cargar() {
            this.cargando = true;
            this.error = null;
            try {
                var url = '/api/espacios';
                if (this.hotel && this.hotel !== 'ambos') url += '?hotel=' + encodeURIComponent(this.hotel);
                var r = await apiFetch(url);
                if (!r || !r.ok) {
                    this.error = (r && r.error && r.error.mensaje) || 'Error al cargar.';
                    return;
                }
                this.data = r.data;
            } catch (e) {
                this.error = 'No pudimos conectar con el servidor.';
            } finally {
                this.cargando = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },

        iniciarRefresco() {
            var self = this;
            this._intervalId = setInterval(function () { self.cargar(); }, 60000);
        },
        alVolverVisible() {
            if (!document.hidden) this.cargar();
        },

        setHotel(valor) {
            this.hotel = valor;
            localStorage.setItem('espacios_hotel', valor);
            this.cargar();
        },
        etiquetaHotel() {
            var op = this.hotelOpciones.find(o => o.valor === this.hotel);
            return op ? op.etiqueta : 'Ambos hoteles';
        },

        etiquetaEstado(estado) {
            var map = { 'aprobada': 'Listo', 'sucia': 'Limpieza pendiente', 'en_progreso': 'En limpieza' };
            return map[estado] || 'Listo';
        },
        claseBadge(estado) {
            var map = {
                'aprobada': 'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-200',
                'sucia': 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200',
                'en_progreso': 'bg-blue-100 dark:bg-blue-900/40 text-blue-800 dark:text-blue-200'
            };
            return map[estado] || 'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-200';
        },

        // --- Crear / editar ---
        abrirCrear() {
            this.form = { id: null, nombre: '', hotel: (this.hotel !== 'ambos' ? this.hotel : '1_sur'), items: [{ descripcion: '', creditos: 1 }] };
            this.modalForm = { abierto: true, enviando: false };
            this.$nextTick(function () { lucide.createIcons(); });
        },
        async abrirEditar(esp) {
            this.modalForm = { abierto: true, enviando: false };
            this.form = { id: esp.id, nombre: esp.numero, hotel: esp.hotel_codigo, items: [{ descripcion: '', creditos: 1 }] };
            try {
                var r = await apiFetch('/api/espacios/' + esp.id);
                if (r && r.ok) {
                    var items = (r.data.items || []).map(function (i) {
                        return { descripcion: i.descripcion, creditos: (i.creditos == null ? 1 : parseInt(i.creditos, 10)) };
                    });
                    this.form.items = items.length ? items : [{ descripcion: '', creditos: 1 }];
                    this.form.nombre = r.data.espacio.numero;
                }
            } catch (e) { /* deja el nombre básico */ }
            this.$nextTick(function () { lucide.createIcons(); });
        },
        cerrarForm() {
            this.modalForm.abierto = false;
        },
        agregarItem() {
            this.form.items.push({ descripcion: '', creditos: 1 });
            this.$nextTick(function () { lucide.createIcons(); });
        },
        quitarItem(idx) {
            this.form.items.splice(idx, 1);
            if (this.form.items.length === 0) this.form.items.push({ descripcion: '', creditos: 1 });
        },
        totalCreditos() {
            return this.form.items.reduce(function (acc, i) {
                var c = parseInt(i.creditos, 10);
                return acc + (((i.descripcion || '').trim() !== '' && !isNaN(c)) ? Math.max(0, Math.min(100, c)) : 0);
            }, 0);
        },
        async guardar() {
            if (this.modalForm.enviando) return;
            var nombre = (this.form.nombre || '').trim();
            var items = this.form.items
                .map(function (i) {
                    var c = parseInt(i.creditos, 10);
                    return {
                        descripcion: (i.descripcion || '').trim(),
                        creditos: isNaN(c) ? 1 : Math.max(0, Math.min(100, c))
                    };
                })
                .filter(function (i) { return i.descripcion !== ''; });
            if (nombre === '') { this.mostrarToast('error', 'Ponle un nombre al área.'); return; }
            if (items.length === 0) { this.mostrarToast('error', 'Agrega al menos un ítem al checklist.'); return; }

            this.modalForm.enviando = true;
            try {
                var r;
                if (this.form.id) {
                    r = await apiPut('/api/espacios/' + this.form.id, { nombre: nombre, items: items });
                } else {
                    r = await apiPost('/api/espacios', { nombre: nombre, hotel: this.form.hotel, items: items });
                }
                if (r && r.ok) {
                    this.mostrarToast('exito', this.form.id ? 'Área actualizada.' : 'Área creada.');
                    this.cerrarForm();
                    this.cargar();
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos guardar.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.modalForm.enviando = false;
            }
        },

        async archivar(esp) {
            if (!confirm('¿Archivar "' + esp.numero + '"? Dejará de aparecer, pero se conserva el historial.')) return;
            try {
                var r = await apiFetch('/api/espacios/' + esp.id, { method: 'DELETE' });
                if (r && r.ok) {
                    this.mostrarToast('exito', 'Área archivada.');
                    this.cargar();
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos archivar.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            }
        },

        // --- Pedir limpieza ---
        abrirPedir(esp) {
            this.modalPedir = { abierto: true, enviando: false, espacio: esp };
            this.$nextTick(function () { lucide.createIcons(); });
        },
        cerrarPedir() {
            this.modalPedir = { abierto: false, enviando: false, espacio: null };
        },
        async confirmarPedir(tr) {
            if (this.modalPedir.enviando || !this.modalPedir.espacio) return;
            this.modalPedir.enviando = true;
            try {
                var r = await apiPost('/api/espacios/' + this.modalPedir.espacio.id + '/pedir-limpieza', {
                    usuario_id: tr.id,
                    fecha: this.fechaHoy
                });
                if (r && r.ok) {
                    this.mostrarToast('exito', 'Limpieza pedida a ' + tr.nombre + '.');
                    this.cerrarPedir();
                    this.cargar();
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos pedir la limpieza.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.modalPedir.enviando = false;
            }
        },

        avatarUsuario(u) {
            if (!u) return '';
            var nombre = u.nombre || '';
            var inicial = nombre.trim().charAt(0).toUpperCase() || '?';
            var seed = (u.rut || nombre).toString();
            var hash = 0;
            for (var i = 0; i < seed.length; i++) { hash = ((hash << 5) - hash) + seed.charCodeAt(i); hash |= 0; }
            var colores = ['bg-blue-500', 'bg-emerald-500', 'bg-amber-500', 'bg-rose-500', 'bg-violet-500', 'bg-cyan-500', 'bg-orange-500', 'bg-pink-500'];
            var color = colores[Math.abs(hash) % colores.length];
            return '<span class="w-9 h-9 rounded-full ' + color + ' text-white font-bold flex items-center justify-center flex-shrink-0 text-sm">' + escapeHtml(inicial) + '</span>';
        },

        mostrarToast(tipo, mensaje) {
            this.toast = { visible: true, tipo: tipo, mensaje: mensaje };
            var self = this;
            setTimeout(function () { self.toast.visible = false; }, 2500);
        }
    };
}
</script>
