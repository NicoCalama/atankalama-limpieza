<?php
/**
 * Detalle de habitación + checklist persistente tap-a-tap.
 * Spec: docs/habitaciones.md, docs/checklist.md
 *
 * Flujos:
 *  - sucia + asignada a mí → botón "Comenzar limpieza" (POST iniciar → recarga)
 *  - en_progreso + asignada → checklist editable con cola offline
 *  - completada_pendiente_auditoria → checklist read-only, aviso "En auditoría"
 *  - aprobada / aprobada_con_observacion / rechazada → vista histórica (opacidad, badge "Auditada")
 *
 * Variables requeridas: $usuario, $habitacionId (int)
 */

require_once __DIR__ . '/componentes/badge-estado.php';
?>

<div x-data="habitacionDetalleApp(<?= (int) $habitacionId ?>, <?= (int) $usuario->id ?>)"
     x-init="cargar(); iniciarListeners();">

    <!-- Header sticky -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between max-w-3xl mx-auto gap-3">
            <a href="/habitaciones"
               class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800"
               aria-label="Volver">
                <i data-lucide="arrow-left" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
            </a>
            <h1 class="flex-1 text-lg font-semibold text-gray-900 dark:text-gray-100 text-center truncate">
                <template x-if="habitacion">
                    <span>Habitación <span x-text="habitacion.numero"></span></span>
                </template>
                <template x-if="!habitacion">
                    <span>Habitación</span>
                </template>
            </h1>
            <div class="w-11"></div>
        </div>
    </header>

    <!-- Banner sin conexión -->
    <div x-show="sinConexion" x-cloak
         class="bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200 px-4 py-2 text-sm text-center">
        Sin conexión a internet. Tus cambios se sincronizarán cuando vuelva.
    </div>

    <!-- Banner sincronizando -->
    <div x-show="cola.length > 0 && !sinConexion" x-cloak
         class="bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300 px-4 py-2 text-sm text-center flex items-center justify-center gap-2">
        <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        Sincronizando <span x-text="cola.length"></span> <span x-text="cola.length === 1 ? 'cambio' : 'cambios'"></span>...
    </div>

    <!-- Banner fallos permanentes -->
    <div x-show="errorPermanente" x-cloak
         class="bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 px-4 py-2 text-sm text-center">
        Algunos cambios no se guardaron. Intenta más tarde o contacta soporte.
    </div>

    <!-- Estado de carga -->
    <template x-if="cargando && !habitacion">
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
    <template x-if="error && !habitacion">
        <div class="min-h-[60vh] flex items-center justify-center px-4">
            <div class="text-center max-w-xs">
                <i data-lucide="alert-circle" class="w-12 h-12 text-red-500 mx-auto mb-3"></i>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">No pudimos cargar</h2>
                <p class="text-gray-600 dark:text-gray-400 mb-4" x-text="error"></p>
                <button @click="cargar()"
                        class="min-h-[44px] px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                    Reintentar
                </button>
            </div>
        </div>
    </template>

    <!-- Contenido -->
    <template x-if="habitacion">
        <main class="pb-24 md:pb-8 px-4 py-4 max-w-3xl mx-auto space-y-4"
              :class="esAuditada ? 'opacity-75' : ''">

            <!-- Tarjeta info habitación -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <p class="text-4xl font-bold text-gray-900 dark:text-gray-100" x-text="habitacion.numero"></p>
                        <p class="text-base text-gray-600 dark:text-gray-400 mt-1" x-text="habitacion.tipo_nombre"></p>
                        <p class="text-sm text-gray-500 dark:text-gray-500 mt-0.5" x-text="habitacion.hotel_nombre"></p>
                    </div>
                    <div class="flex flex-col items-end gap-2">
                        <span x-html="badgeEstado(habitacion.estado)"></span>
                        <template x-if="esAuditada">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-xs font-medium rounded-full">
                                <i data-lucide="lock" class="w-3 h-3"></i> Auditada
                            </span>
                        </template>
                    </div>
                </div>

                <!-- CTA: Comenzar limpieza (sucia + asignada) -->
                <template x-if="habitacion.estado === 'sucia' && estaAsignada && !esAuditada">
                    <button @click="iniciar()" :disabled="iniciando"
                            class="w-full min-h-[56px] mt-2 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 disabled:opacity-50 text-white text-lg font-semibold rounded-xl transition shadow-sm">
                        <span x-text="iniciando ? 'Iniciando...' : 'Comenzar limpieza'"></span>
                    </button>
                </template>

                <!-- No asignada y sucia -->
                <template x-if="habitacion.estado === 'sucia' && !estaAsignada && puedeVerTodas">
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                        Esta habitación está pendiente de asignación.
                    </p>
                </template>

                <!-- Auditoría pendiente (solo aviso, read-only) -->
                <template x-if="habitacion.estado === 'completada_pendiente_auditoria'">
                    <div class="mt-3 bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 rounded-lg p-3 flex items-start gap-3">
                        <i data-lucide="clock" class="w-5 h-5 text-indigo-600 dark:text-indigo-400 flex-shrink-0 mt-0.5"></i>
                        <p class="text-sm text-indigo-900 dark:text-indigo-200">Esta habitación está esperando auditoría.</p>
                    </div>
                </template>
            </div>

            <!-- Checklist (en_progreso o completada_pendiente_auditoria o auditada con ejecución) -->
            <template x-if="ejecucion && items.length > 0">
                <div class="space-y-3">
                    <?php include __DIR__ . '/componentes/progreso-checklist.php'; ?>

                    <!-- Lista de items -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <template x-for="item in items" :key="item.id">
                            <label class="flex items-start gap-3 px-4 py-4 border-b border-gray-200 dark:border-gray-700 last:border-b-0"
                                   :class="puedeEditar ? 'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50' : 'cursor-default'">
                                <input type="checkbox"
                                       :checked="item.marcado == 1"
                                       :disabled="!puedeEditar || item._guardando"
                                       @change="toggleItem(item, $event.target.checked)"
                                       class="mt-1 w-6 h-6 rounded border-2 border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-2 focus:ring-blue-500 disabled:opacity-60 flex-shrink-0">
                                <div class="flex-1 min-w-0">
                                    <p class="text-base text-gray-900 dark:text-gray-100"
                                       :class="item.marcado == 1 ? 'line-through text-gray-400 dark:text-gray-500' : ''"
                                       x-text="item.descripcion"></p>
                                    <div class="flex items-center gap-2 mt-1">
                                        <template x-if="item.obligatorio == 0">
                                            <span class="text-xs text-gray-500 dark:text-gray-400">Opcional</span>
                                        </template>
                                        <template x-if="item.desmarcado_por_auditor == 1">
                                            <span class="inline-flex items-center gap-1 text-xs text-amber-700 dark:text-amber-400">
                                                <i data-lucide="alert-triangle" class="w-3 h-3"></i> Auditor desmarcó
                                            </span>
                                        </template>
                                        <template x-if="item._error">
                                            <span class="text-xs text-red-600 dark:text-red-400" x-text="item._error"></span>
                                        </template>
                                    </div>
                                </div>
                            </label>
                        </template>
                    </div>

                    <!-- Botón "Habitación terminada" -->
                    <template x-if="puedeEditar">
                        <button @click="confirmarCompletar()"
                                :disabled="progreso.obligatorios_pendientes > 0 || completando"
                                class="w-full min-h-[56px] bg-green-600 hover:bg-green-700 active:bg-green-800 disabled:bg-gray-300 dark:disabled:bg-gray-700 disabled:text-gray-500 disabled:cursor-not-allowed text-white text-lg font-semibold rounded-xl transition shadow-sm">
                            <span x-text="completando ? 'Enviando...' : (progreso.obligatorios_pendientes > 0 ? 'Faltan items obligatorios' : 'Habitación terminada')"></span>
                        </button>
                    </template>
                </div>
            </template>

            <!-- Modal confirmación completar -->
            <div x-show="mostrarConfirmar" x-cloak
                 class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
                 @click.self="mostrarConfirmar = false">
                <div class="bg-white dark:bg-gray-800 rounded-xl max-w-sm w-full p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">¿Habitación terminada?</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-5">
                        Confirma que terminaste esta habitación. Pasará a auditoría y no podrás editarla.
                    </p>
                    <div class="flex gap-3">
                        <button @click="mostrarConfirmar = false"
                                class="flex-1 min-h-[44px] px-4 py-2 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 font-medium rounded-lg transition">
                            Cancelar
                        </button>
                        <button @click="completar()" :disabled="completando"
                                class="flex-1 min-h-[44px] px-4 py-2 bg-green-600 hover:bg-green-700 disabled:opacity-50 text-white font-medium rounded-lg transition">
                            <span x-text="completando ? 'Enviando...' : 'Confirmar'"></span>
                        </button>
                    </div>
                </div>
            </div>

        </main>
    </template>
</div>

<script>
function habitacionDetalleApp(habitacionId, usuarioId) {
    return {
        habitacionId: habitacionId,
        usuarioId: usuarioId,

        habitacion: null,
        ejecucion: null,
        items: [],
        progreso: { marcados: 0, total: 0, porcentaje: 0, obligatorios_total: 0, obligatorios_marcados: 0, obligatorios_pendientes: 0 },
        estaAsignada: false,
        puedeVerTodas: false,

        cargando: false,
        error: null,
        iniciando: false,
        completando: false,
        mostrarConfirmar: false,
        sinConexion: !navigator.onLine,
        cola: [],
        errorPermanente: false,
        _procesandoCola: false,

        get esAuditada() {
            if (!this.habitacion) return false;
            var e = this.habitacion.estado;
            return e === 'aprobada' || e === 'aprobada_con_observacion' || e === 'rechazada';
        },

        get puedeEditar() {
            if (!this.habitacion || !this.ejecucion) return false;
            return this.habitacion.estado === 'en_progreso'
                && this.estaAsignada
                && this.ejecucion.estado === 'en_progreso';
        },

        get colaKey() {
            return this.ejecucion ? ('checklist_queue_' + this.ejecucion.id) : null;
        },

        async cargar() {
            this.cargando = true;
            this.error = null;

            // Verificar permiso ver_todas y asignación
            try {
                var yo = Alpine.store('auth');
                if (yo && yo.cargado) {
                    this.puedeVerTodas = yo.tienePermiso('habitaciones.ver_todas');
                }

                var r1 = await apiFetch('/api/habitaciones/' + this.habitacionId);
                if (!r1 || !r1.ok) {
                    this.error = (r1 && r1.error && r1.error.mensaje) || 'No encontrada.';
                    return;
                }
                this.habitacion = r1.data.habitacion;

                // Consultar cola del trabajador para saber si está asignada
                var r2 = await apiFetch('/api/usuarios/' + this.usuarioId + '/cola');
                if (r2 && r2.ok) {
                    var ids = (r2.data.cola || []).map(function (a) { return a.habitacion_id; });
                    this.estaAsignada = ids.indexOf(this.habitacion.id) !== -1;
                }

                // Sólo el trabajador asignado carga el checklist editable
                // (el backend de iniciar es idempotente y retorna la ejecución existente si ya hay una).
                if (this.habitacion.estado === 'en_progreso' && this.estaAsignada) {
                    await this.cargarEjecucion();
                }

                // Cargar cola offline
                this.cargarColaLocal();
                if (this.cola.length > 0 && !this.sinConexion) {
                    this.procesarCola();
                }
            } catch (e) {
                this.error = 'No pudimos conectar con el servidor.';
            } finally {
                this.cargando = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },

        async cargarEjecucion() {
            // El backend de /iniciar es idempotente: si ya hay una ejecución 'en_progreso' para la habitación
            // + asignación del trabajador, la retorna sin efectos colaterales.
            try {
                var rPost = await apiPost('/api/habitaciones/' + this.habitacionId + '/iniciar', {});
                if (rPost && rPost.ok && rPost.data && rPost.data.ejecucion) {
                    var ejecId = rPost.data.ejecucion.id;
                    var rEjec = await apiFetch('/api/ejecuciones/' + ejecId);
                    if (rEjec && rEjec.ok) {
                        this.ejecucion = rEjec.data.ejecucion;
                        this.items = (rEjec.data.items || []).map(function (it) {
                            it._guardando = false;
                            it._error = null;
                            return it;
                        });
                        this.progreso = rEjec.data.progreso;
                    }
                }
            } catch (e) {
                // Silencioso — checklist no disponible para este rol/estado.
            }
        },

        async iniciar() {
            if (this.iniciando) return;
            this.iniciando = true;
            try {
                var json = await apiPost('/api/habitaciones/' + this.habitacionId + '/iniciar', {});
                if (json && json.ok) {
                    await this.cargar();
                } else {
                    alert((json && json.error && json.error.mensaje) || 'No pudimos iniciar.');
                }
            } catch (e) {
                alert('No pudimos conectar con el servidor.');
            } finally {
                this.iniciando = false;
            }
        },

        toggleItem(item, nuevoValor) {
            if (!this.puedeEditar) return;
            // Optimistic update
            var previo = item.marcado == 1;
            item.marcado = nuevoValor ? 1 : 0;
            item._error = null;

            // Recalcular progreso local (estimación)
            this.recalcularProgresoLocal();

            // Enviar o encolar
            if (this.sinConexion) {
                this.encolarMarca(item.id, nuevoValor);
                return;
            }

            item._guardando = true;
            this.enviarMarca(item.id, nuevoValor).then((exito) => {
                item._guardando = false;
                if (!exito) {
                    // Rollback
                    item.marcado = previo ? 1 : 0;
                    item._error = 'No se guardó';
                    this.recalcularProgresoLocal();
                    this.encolarMarca(item.id, nuevoValor);
                }
            });
        },

        async enviarMarca(itemId, marcado) {
            try {
                var json = await apiPut(
                    '/api/ejecuciones/' + this.ejecucion.id + '/items/' + itemId,
                    { marcado: !!marcado }
                );
                if (json && json.ok) {
                    this.progreso = json.data.progreso;
                    return true;
                }
                return false;
            } catch (e) {
                return false;
            }
        },

        recalcularProgresoLocal() {
            var marcados = 0, obligMarcados = 0, obligTotal = 0, total = 0;
            this.items.forEach(function (it) {
                total++;
                if (it.obligatorio == 1) obligTotal++;
                if (it.marcado == 1) {
                    marcados++;
                    if (it.obligatorio == 1) obligMarcados++;
                }
            });
            this.progreso = {
                marcados: marcados,
                total: total,
                porcentaje: total === 0 ? 0 : Math.round(marcados * 100 / total),
                obligatorios_total: obligTotal,
                obligatorios_marcados: obligMarcados,
                obligatorios_pendientes: obligTotal - obligMarcados
            };
        },

        // --- Cola offline ---

        cargarColaLocal() {
            if (!this.colaKey) return;
            var raw = localStorage.getItem(this.colaKey);
            this.cola = raw ? (JSON.parse(raw) || []) : [];
        },

        guardarColaLocal() {
            if (!this.colaKey) return;
            if (this.cola.length === 0) {
                localStorage.removeItem(this.colaKey);
            } else {
                localStorage.setItem(this.colaKey, JSON.stringify(this.cola));
            }
        },

        encolarMarca(itemId, marcado) {
            // Dedupe: remover entradas previas para el mismo item
            this.cola = this.cola.filter(function (c) { return c.item_id !== itemId; });
            this.cola.push({
                item_id: itemId,
                marcado: !!marcado,
                timestamp_local: new Date().toISOString(),
                intentos: 0
            });
            this.guardarColaLocal();
        },

        async procesarCola() {
            if (this._procesandoCola || this.cola.length === 0 || this.sinConexion || !this.ejecucion) return;
            this._procesandoCola = true;
            this.errorPermanente = false;

            var pendientes = this.cola.slice();
            for (var i = 0; i < pendientes.length; i++) {
                var entrada = pendientes[i];
                var exito = await this.enviarMarca(entrada.item_id, entrada.marcado);
                if (exito) {
                    // Quitar de la cola real
                    this.cola = this.cola.filter(function (c) { return c.item_id !== entrada.item_id || c.timestamp_local !== entrada.timestamp_local; });
                    this.guardarColaLocal();
                } else {
                    // Incrementar intentos
                    var idx = this.cola.findIndex(function (c) { return c.item_id === entrada.item_id && c.timestamp_local === entrada.timestamp_local; });
                    if (idx !== -1) {
                        this.cola[idx].intentos = (this.cola[idx].intentos || 0) + 1;
                        if (this.cola[idx].intentos >= 3) {
                            this.errorPermanente = true;
                            this.cola.splice(idx, 1);
                        }
                    }
                    this.guardarColaLocal();
                    // Backoff simple antes de seguir
                    await new Promise(function (r) { setTimeout(r, 1000); });
                }
            }
            this._procesandoCola = false;
        },

        iniciarListeners() {
            var self = this;
            window.addEventListener('online', function () {
                self.sinConexion = false;
                self.procesarCola();
            });
            window.addEventListener('offline', function () {
                self.sinConexion = true;
            });
        },

        confirmarCompletar() {
            if (this.progreso.obligatorios_pendientes > 0) return;
            this.mostrarConfirmar = true;
        },

        async completar() {
            if (this.completando) return;
            this.completando = true;
            try {
                var json = await apiPost('/api/habitaciones/' + this.habitacionId + '/completar', {});
                if (json && json.ok) {
                    this.mostrarConfirmar = false;
                    window.location.href = '/home';
                } else {
                    alert((json && json.error && json.error.mensaje) || 'No pudimos completar.');
                }
            } catch (e) {
                alert('No pudimos conectar con el servidor.');
            } finally {
                this.completando = false;
            }
        },

        badgeEstado(estado) {
            var configs = {
                'sucia': { texto: 'Pendiente', clase: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-200' },
                'en_progreso': { texto: 'En progreso', clase: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200' },
                'completada_pendiente_auditoria': { texto: 'Por auditar', clase: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200' },
                'aprobada': { texto: 'Aprobada', clase: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' },
                'aprobada_con_observacion': { texto: 'Aprobada c/obs.', clase: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' },
                'rechazada': { texto: 'Rechazada', clase: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200' }
            };
            var c = configs[estado] || { texto: estado, clase: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200' };
            return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' + c.clase + '">' + escapeHtml(c.texto) + '</span>';
        }
    };
}
</script>
