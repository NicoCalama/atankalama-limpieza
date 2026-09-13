<?php
/**
 * Modal reutilizable "Nuevo ticket".
 * Incluir en layout.php (si el usuario tiene permiso tickets.crear).
 *
 * Para abrirlo desde cualquier parte, dispatch del evento:
 *   this.$dispatch('abrir-modal-ticket', { habitacionId: 42, hotelCodigo: '1_sur' })
 *
 * Al crearse exitosamente, emite 'ticket-creado' con el ticket como detail.
 * La página consumidora puede escuchar para refrescar listas.
 */
// Asignar de inmediato al crear: mismo permiso que ya gatea /asignar (panel "Asignar
// responsable" de tickets.php) y el mismo componente se usa en toda la app (aquí y en
// "Reportar un problema" de habitacion-detalle.php), así que la regla aplica parejo
// donde sea que se abra.
$ticketPuedeAsignar = $usuario->tienePermiso('tickets.ver_todos');
// Elegir la prioridad al crear (en vez de nacer siempre en 'normal') exige el mismo
// permiso que después permite cambiarla — ver TicketsController::crear() y ::cambiarPrioridad().
$ticketPuedeEditarPrioridad = $usuario->tienePermiso('tickets.editar_prioridad');
?>
<div x-data="modalTicketNuevo(<?= $ticketPuedeAsignar ? 'true' : 'false' ?>, <?= $ticketPuedeEditarPrioridad ? 'true' : 'false' ?>)"
     @abrir-modal-ticket.window="abrir($event.detail || {})">

    <div x-show="abierto" x-cloak
         class="fixed inset-0 z-[60] flex items-end md:items-center justify-center p-4 bg-black/50"
         @click.self="cerrar()">
        <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-5 shadow-xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Reportar problema</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Cuéntanos qué pasa y lo revisaremos.</p>
                </div>
                <button @click="cerrar()" class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                    <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                </button>
            </div>

            <form @submit.prevent="crear()" class="space-y-3">
                <!-- Hotel (requerido) -->
                <div>
                    <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Hotel *</label>
                    <select x-model.number="form.hotel_id"
                            :disabled="form.habitacion_id !== null"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px] disabled:opacity-60">
                        <option :value="null">Selecciona un hotel</option>
                        <template x-for="h in hoteles" :key="h.id">
                            <option :value="h.id" x-text="h.nombre"></option>
                        </template>
                    </select>
                </div>

                <!-- Habitación (opcional) -->
                <div>
                    <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Habitación (opcional)</label>
                    <template x-if="form.habitacion_id !== null">
                        <div class="flex items-center gap-2 px-3 py-2 bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded-lg text-sm">
                            <i data-lucide="bed" class="w-4 h-4"></i>
                            <span x-text="'Habitación ' + (habitacionSeleccionadaNumero || form.habitacion_id)"></span>
                            <button type="button" @click="quitarHabitacion()" class="ml-auto text-xs text-blue-700 dark:text-blue-300 hover:underline">Cambiar</button>
                        </div>
                    </template>
                    <template x-if="form.habitacion_id === null">
                        <input type="text"
                               x-model="busquedaHabitacion"
                               @focus="abrirBuscador = true"
                               @input="abrirBuscador = true"
                               placeholder="Buscar por número..."
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px]">
                    </template>
                    <template x-if="form.habitacion_id === null && abrirBuscador && habitacionesFiltradas().length > 0">
                        <ul class="mt-1 max-h-40 overflow-y-auto border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 divide-y divide-gray-100 dark:divide-gray-700">
                            <template x-for="hab in habitacionesFiltradas().slice(0, 10)" :key="hab.id">
                                <li>
                                    <button type="button" @click="seleccionarHabitacion(hab)"
                                            class="w-full text-left px-3 py-2 text-sm text-gray-900 dark:text-gray-100 hover:bg-blue-50 dark:hover:bg-blue-900/30">
                                        <span class="font-semibold" x-text="hab.numero"></span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400 ml-2" x-text="nombreHotelCorto(hab.hotel_codigo)"></span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </template>
                </div>

                <!-- Descripción -->
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Descripción del problema *</label>
                        <template x-if="soportaDictado">
                            <button type="button"
                                    @click="toggleDictado()"
                                    :aria-pressed="grabando"
                                    :aria-label="grabando ? 'Detener dictado' : 'Dictar descripción por voz'"
                                    class="min-h-[36px] min-w-[36px] flex items-center justify-center rounded-lg transition"
                                    :class="grabando ? 'bg-red-100 dark:bg-red-900/40 text-red-600 dark:text-red-400 animate-pulse' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'">
                                <!-- SVG inline (no data-lucide): lucide.createIcons() reemplaza el nodo <i> por un
                                     <svg> nuevo y Alpine pierde la referencia, dejando iconos huérfanos al alternar. -->
                                <svg x-show="!grabando" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="9" y="2" width="6" height="13" rx="3"></rect>
                                    <path d="M19 10v2a7 7 0 0 1-14 0v-2"></path>
                                    <path d="M12 19v3"></path>
                                </svg>
                                <svg x-show="grabando" x-cloak xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                                </svg>
                            </button>
                        </template>
                    </div>
                    <textarea x-model="form.descripcion" rows="4" maxlength="500" required
                              placeholder="Cuéntanos qué pasa..."
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm"></textarea>
                </div>

                <!-- Prioridad (opcional, solo quien tiene tickets.editar_prioridad) -->
                <template x-if="puedeEditarPrioridad">
                    <div>
                        <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Prioridad</label>
                        <select x-model="form.prioridad"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px]">
                            <option value="baja">Baja</option>
                            <option value="normal">Normal</option>
                            <option value="alta">Alta</option>
                            <option value="urgente">Urgente</option>
                        </select>
                    </div>
                </template>

                <!-- Asignar a (opcional, solo Admin/Supervisor — tickets.ver_todos) -->
                <template x-if="puedeAsignar">
                    <div>
                        <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Asignar a (opcional)</label>
                        <select x-model.number="form.asignado_a"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px]">
                            <option :value="null">Sin asignar</option>
                            <template x-for="u in usuariosAsignables" :key="u.id">
                                <option :value="u.id" x-text="u.nombre"></option>
                            </template>
                        </select>
                    </div>
                </template>

                <!-- Fotos (opcional) -->
                <div>
                    <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Fotos (opcional, máx. 3)</label>
                    <div class="flex flex-wrap gap-2 mb-2" x-show="fotos.length > 0">
                        <template x-for="(foto, idx) in fotos" :key="foto.url">
                            <div class="relative w-16 h-16">
                                <img :src="foto.url" class="w-16 h-16 object-cover rounded-lg border border-gray-300 dark:border-gray-600">
                                <button type="button" @click="quitarFoto(idx)"
                                        class="absolute -top-1.5 -right-1.5 w-5 h-5 flex items-center justify-center rounded-full bg-red-600 text-white text-xs leading-none"
                                        aria-label="Quitar foto">×</button>
                            </div>
                        </template>
                    </div>
                    <div x-show="fotos.length < 3" class="flex gap-2">
                        <label class="inline-flex items-center gap-2 px-3 py-2 border border-dashed border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 min-h-[44px]">
                            <i data-lucide="camera" class="w-4 h-4"></i>
                            <span>Tomar foto</span>
                            <!-- Sin "multiple": la cámara solo entrega 1 archivo por captura -->
                            <input type="file" accept="image/*" capture="environment" class="hidden" @change="onFotosSeleccionadas($event)">
                        </label>
                        <label class="inline-flex items-center gap-2 px-3 py-2 border border-dashed border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 min-h-[44px]">
                            <i data-lucide="image" class="w-4 h-4"></i>
                            <span>Galería</span>
                            <!-- Sin "capture": deja elegir varias fotos de la librería en Android e iOS -->
                            <input type="file" accept="image/*" multiple class="hidden" @change="onFotosSeleccionadas($event)">
                        </label>
                    </div>
                </div>

                <!-- Error inline -->
                <template x-if="error">
                    <div class="p-3 bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-sm rounded-lg" x-text="error"></div>
                </template>

                <!-- Acciones -->
                <div class="flex gap-2 pt-2">
                    <button type="button" @click="cerrar()"
                            class="flex-1 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition">
                        Cancelar
                    </button>
                    <button type="submit" :disabled="enviando || !formValido()"
                            class="flex-1 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white transition inline-flex items-center justify-center gap-2">
                        <template x-if="enviando">
                            <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                        </template>
                        <span x-text="enviando ? 'Enviando...' : 'Crear ticket'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function modalTicketNuevo(puedeAsignar, puedeEditarPrioridad) {
    return {
        abierto: false,
        enviando: false,
        error: null,
        puedeAsignar: puedeAsignar,
        puedeEditarPrioridad: puedeEditarPrioridad,
        hoteles: [],
        habitaciones: [],
        usuariosAsignables: [], // cargados on-demand, solo si puedeAsignar
        _datosCargados: false,
        busquedaHabitacion: '',
        abrirBuscador: false,
        habitacionSeleccionadaNumero: null,
        fotos: [], // [{ file, url }] — máx. 3, el server vuelve a validar igual
        form: {
            hotel_id: null,
            habitacion_id: null,
            descripcion: '',
            asignado_a: null,
            prioridad: 'normal',
        },

        // Dictado por voz de la descripción (Web Speech API) — mismo patrón que
        // auditoria-detalle.php (comentario de observación/rechazo).
        grabando: false,
        reconocimiento: null,
        dictadoReinicios: 0,

        get soportaDictado() {
            return !!(window.SpeechRecognition || window.webkitSpeechRecognition);
        },

        async abrir(detail) {
            this.reset();
            this.abierto = true;
            await this.asegurarDatos();
            if (detail && detail.habitacionId) {
                var hab = this.habitaciones.find(h => h.id === detail.habitacionId);
                if (hab) this.seleccionarHabitacion(hab);
                else this.form.habitacion_id = detail.habitacionId;
            }
            if (detail && detail.hotelCodigo) {
                var h = this.hoteles.find(x => x.codigo === detail.hotelCodigo);
                if (h) this.form.hotel_id = h.id;
            }
            this.$nextTick(function () { lucide.createIcons(); });
        },

        cerrar() {
            this.detenerDictado();
            this.abierto = false;
            this.abrirBuscador = false;
            this.limpiarFotos();
        },

        // Dicta la descripción por voz. El texto reconocido se agrega al que ya haya
        // (no lo reemplaza), para poder mezclar teclado y voz. Ver auditoria-detalle.php,
        // mismo patrón (reinicio automático en Safari/iOS, tope de seguridad, permisos).
        toggleDictado() {
            if (this.grabando) {
                this.detenerDictado();
                return;
            }
            if (!this.soportaDictado) return;

            var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            var reco = new SpeechRecognition();
            var self = this;
            reco.lang = 'es-CL';
            reco.continuous = true;
            reco.interimResults = false;

            reco.onresult = function (event) {
                var textoNuevo = '';
                for (var i = event.resultIndex; i < event.results.length; i++) {
                    if (event.results[i].isFinal) {
                        textoNuevo += event.results[i][0].transcript;
                    }
                }
                textoNuevo = textoNuevo.trim();
                if (textoNuevo === '') return;
                self.dictadoReinicios = 0; // hubo voz real: el contador de seguridad se reinicia
                var actual = self.form.descripcion.trim();
                self.form.descripcion = (actual === '' ? textoNuevo : actual + ' ' + textoNuevo).slice(0, 500);
            };

            reco.onerror = function (event) {
                // 'no-speech' (pausa) y 'aborted' (nuestro propio stop) son normales:
                // no cortan el dictado, los maneja onend.
                if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                    self.grabando = false;
                    self.error = 'Permiso de micrófono denegado.';
                } else if (event.error !== 'no-speech' && event.error !== 'aborted') {
                    self.grabando = false;
                    self.error = 'No pudimos usar el micrófono.';
                }
            };

            reco.onend = function () {
                // Safari/iOS ignora continuous y corta al primer silencio. Mientras no se
                // toque "detener", reanudamos para poder hacer pausas y seguir hablando.
                if (!self.grabando) return;
                if (self.dictadoReinicios >= 30) { self.grabando = false; return; } // tope: micrófono en silencio
                self.dictadoReinicios++;
                setTimeout(function () {
                    if (!self.grabando) return;
                    try { reco.start(); } catch (e) { self.grabando = false; }
                }, 250);
            };

            this.reconocimiento = reco;
            this.dictadoReinicios = 0;
            this.grabando = true;
            reco.start();
        },

        detenerDictado() {
            this.grabando = false;
            this.dictadoReinicios = 0;
            if (this.reconocimiento) {
                try { this.reconocimiento.stop(); } catch (e) {}
            }
        },

        reset() {
            this.form = { hotel_id: null, habitacion_id: null, descripcion: '', asignado_a: null, prioridad: 'normal' };
            this.error = null;
            this.enviando = false;
            this.busquedaHabitacion = '';
            this.abrirBuscador = false;
            this.habitacionSeleccionadaNumero = null;
            this.limpiarFotos();
        },

        async onFotosSeleccionadas(event) {
            var espacio = 3 - this.fotos.length;
            var archivos = Array.from(event.target.files || []).slice(0, espacio);
            event.target.value = ''; // permite volver a elegir el mismo archivo si se saca y se agrega de nuevo
            // Comprimir antes de mostrar/subir — ver comprimirFotoParaSubir() en app.js.
            for (var i = 0; i < archivos.length; i++) {
                var comprimido = await comprimirFotoParaSubir(archivos[i], 1600, 0.8);
                this.fotos.push({ file: comprimido, url: URL.createObjectURL(comprimido) });
            }
        },

        quitarFoto(idx) {
            URL.revokeObjectURL(this.fotos[idx].url);
            this.fotos.splice(idx, 1);
        },

        limpiarFotos() {
            this.fotos.forEach(function (f) { URL.revokeObjectURL(f.url); });
            this.fotos = [];
        },

        async asegurarDatos() {
            if (this._datosCargados) return;
            try {
                var rh = await apiFetch('/api/hoteles');
                if (rh && rh.ok) this.hoteles = rh.data.hoteles || [];
                var rhab = await apiFetch('/api/habitaciones');
                if (rhab && rhab.ok) this.habitaciones = rhab.data.habitaciones || [];
                if (this.puedeAsignar) {
                    var ru = await apiFetch('/api/tickets/usuarios-asignables');
                    if (ru && ru.ok) this.usuariosAsignables = ru.data.usuarios || [];
                }
                this._datosCargados = true;
            } catch (e) {
                // Sin datos no bloqueamos: user puede elegir hotel manualmente si se cargó
            }
        },

        habitacionesFiltradas() {
            var q = (this.busquedaHabitacion || '').trim().toLowerCase();
            if (!q) return this.habitaciones;
            return this.habitaciones.filter(function (h) {
                return (h.numero || '').toString().toLowerCase().includes(q);
            });
        },

        seleccionarHabitacion(hab) {
            this.form.habitacion_id = hab.id;
            this.form.hotel_id = hab.hotel_id;
            this.habitacionSeleccionadaNumero = hab.numero;
            this.abrirBuscador = false;
            this.busquedaHabitacion = '';
        },

        quitarHabitacion() {
            this.form.habitacion_id = null;
            this.habitacionSeleccionadaNumero = null;
        },

        nombreHotelCorto(codigo) {
            if (codigo === 'inn') return 'Inn';
            if (codigo === '1_sur') return 'Atankalama';
            return codigo || '';
        },

        formValido() {
            return this.form.hotel_id !== null
                && (this.form.descripcion || '').trim().length > 0;
        },

        async crear() {
            if (!this.formValido() || this.enviando) return;
            this.enviando = true;
            this.error = null;
            try {
                var descripcion = (this.form.descripcion || '').trim();
                // Título auto-generado desde la descripción (primeros 80 chars, sin saltos de línea)
                var titulo = descripcion.replace(/\s+/g, ' ').slice(0, 80);

                var datos = new FormData();
                datos.append('hotel_id', this.form.hotel_id);
                datos.append('titulo', titulo);
                datos.append('descripcion', descripcion);
                datos.append('prioridad', this.puedeEditarPrioridad ? this.form.prioridad : 'normal');
                if (this.form.habitacion_id !== null) datos.append('habitacion_id', this.form.habitacion_id);
                if (this.puedeAsignar && this.form.asignado_a !== null) datos.append('asignado_a', this.form.asignado_a);
                this.fotos.forEach(function (f) { datos.append('fotos[]', f.file); });

                var r = await apiPostForm('/api/tickets', datos);
                if (r && r.ok) {
                    // adjuntos_fallidos no aborta la creación (ver TicketsController::crear) — se
                    // relaya en el detail del evento para que la página lo muestre en el toast.
                    var detalle = Object.assign({}, r.data.ticket, {
                        _adjuntos_fallidos: r.data.adjuntos_fallidos || [],
                    });
                    this.$dispatch('ticket-creado', detalle);
                    this.cerrar();
                } else {
                    this.error = (r && r.error && r.error.mensaje) || 'No pudimos crear el ticket.';
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
