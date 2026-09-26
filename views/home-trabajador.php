<?php
/**
 * Home del Trabajador de Limpieza.
 * Spec: docs/home-trabajador.md
 *
 * Filosofía: "¿Qué tengo que hacer ahora?"
 * NO mostrar tiempos (docs/home-trabajador.md — regla no negociable). El contador
 * de habitaciones del header ("N limpias / faltan M") fue pedido por la empresa
 * (julio 2026) y revierte a propósito la regla original de "sin números"; los
 * tiempos siguen 100% ocultos para el trabajador.
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

require_once __DIR__ . '/componentes/avatar.php';

$primerNombre = explode(' ', $usuario->nombre)[0];

// Saludo contextual por hora
$hora = (int) date('H');
if ($hora < 12) {
    $saludo = 'Buenos días';
} elseif ($hora < 19) {
    $saludo = 'Buenas tardes';
} else {
    $saludo = 'Buenas noches';
}
?>

<div x-data="homeTrabajador()"
     x-init="cargar(); iniciarRefresco();"
     @visibilitychange.window="alVolverVisible()">

    <!-- Header sticky -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between max-w-2xl mx-auto">
            <div class="flex items-center gap-3 min-w-0 flex-1">
                <a href="<?= u('/ajustes') ?>" aria-label="Mi perfil" class="flex-shrink-0">
                    <?= avatarHtml($usuario->nombre, $usuario->rut) ?>
                </a>
                <div class="min-w-0">
                    <!-- En pantallas muy angostas el saludo se oculta para que el nombre
                         conviva con el contador sin truncarse -->
                    <p class="text-base sm:text-lg font-semibold text-gray-900 dark:text-gray-100 truncate"><span class="hidden min-[420px]:inline"><?= htmlspecialchars($saludo) ?>, </span><?= htmlspecialchars($primerNombre) ?></p>
                    <p class="text-sm text-gray-500 dark:text-gray-400 truncate" x-text="data?.hotel_actual?.nombre || '<?= htmlspecialchars($usuario->hotelDefault === 'inn' ? 'Atankalama INN' : 'Atankalama') ?>'"></p>
                </div>
            </div>
            <!-- Contador de habitaciones + campana + tema -->
            <div class="flex items-center gap-1 flex-shrink-0">
                <!-- Contador "limpias / faltan" (pedido empresa jul-2026; solo conteos, nunca tiempos) -->
                <template x-if="data && data.tiene_asignaciones_hoy">
                    <div class="text-right mr-1" aria-label="Progreso de hoy">
                        <p class="text-sm font-bold text-green-600 dark:text-green-400 leading-tight"
                           x-text="data.progreso.completadas + (data.progreso.completadas === 1 ? ' limpia' : ' limpias')"></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 leading-tight"
                           x-text="(data.progreso.total - data.progreso.completadas) === 0
                                       ? 'todo listo'
                                       : ((data.progreso.total - data.progreso.completadas) === 1
                                           ? 'falta 1'
                                           : 'faltan ' + (data.progreso.total - data.progreso.completadas))"></p>
                    </div>
                </template>
                <button @click="$dispatch('toggle-notif')"
                        class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 relative"
                        aria-label="Notificaciones">
                    <i data-lucide="bell" class="w-6 h-6 text-gray-600 dark:text-gray-400"></i>
                    <template x-if="$store.notif && $store.notif.sinLeer > 0">
                        <span class="absolute top-1 right-1 min-w-[16px] h-4 bg-blue-600 text-white text-[10px] font-bold rounded-full flex items-center justify-center px-0.5 leading-none"
                              x-text="$store.notif.sinLeer > 9 ? '9+' : $store.notif.sinLeer"></span>
                    </template>
                </button>
                <!-- Refrescar manual: en la app instalada en el celular no hay botón
                     de refrescar del navegador disponible. -->
                <button @click="cargar()" :disabled="cargando"
                        class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-50"
                        aria-label="Refrescar">
                    <span :class="cargando ? 'animate-spin' : ''" class="inline-flex">
                        <i data-lucide="refresh-cw" class="w-6 h-6 text-gray-600 dark:text-gray-400"></i>
                    </span>
                </button>
                <?php include __DIR__ . '/componentes/boton-tema.php'; ?>
            </div>
        </div>
    </header>

    <!-- Banner sin conexión -->
    <div x-show="sinConexion" x-cloak
         class="bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200 px-4 py-2 text-sm text-center">
        Sin conexión a internet. Tus cambios se sincronizarán cuando vuelva.
    </div>

    <!-- Estado de carga -->
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

    <!-- Error al cargar -->
    <template x-if="error && !data">
        <div class="min-h-[60vh] flex items-center justify-center px-4">
            <div class="text-center max-w-xs">
                <i data-lucide="alert-circle" class="w-12 h-12 text-red-500 mx-auto mb-3"></i>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">No pudimos cargar tu día</h2>
                <p class="text-gray-600 dark:text-gray-400 mb-4">Verifica tu conexión a internet e intenta de nuevo.</p>
                <button @click="cargar()"
                        class="min-h-[44px] px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                    Reintentar
                </button>
            </div>
        </div>
    </template>

    <!-- Contenido principal -->
    <template x-if="data">
        <main class="pb-24 md:pb-8 max-w-2xl mx-auto">

            <!-- Sin asignaciones -->
            <template x-if="!data.tiene_asignaciones_hoy">
                <div class="px-4 py-12 text-center">
                    <i data-lucide="coffee" class="w-16 h-16 mx-auto mb-4 text-gray-400 dark:text-gray-500"></i>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">No tienes habitaciones asignadas todavía</h2>
                    <p class="text-base text-gray-600 dark:text-gray-400 max-w-xs mx-auto mb-6">Espera a que tu supervisora te asigne, o avísale que estás disponible.</p>
                    <button @click="avisarDisponibilidad()" data-tour="htr.disponible"
                            :disabled="data.aviso_disponibilidad_enviado_hoy || enviandoAviso"
                            class="min-h-[44px] px-6 py-2 bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100
                                   border border-gray-300 dark:border-gray-600 rounded-lg font-medium transition
                                   hover:bg-gray-200 dark:hover:bg-gray-600
                                   disabled:opacity-50 disabled:cursor-not-allowed">
                        <span x-text="data.aviso_disponibilidad_enviado_hoy ? '✓ Aviso enviado' : (enviandoAviso ? 'Enviando...' : 'Avisar que estoy disponible')"></span>
                    </button>
                </div>
            </template>

            <!-- Con asignaciones -->
            <template x-if="data.tiene_asignaciones_hoy">
                <div>
                    <!-- Sección 2: Tarjeta de progreso -->
                    <div class="px-4 mt-4">
                        <!-- Día completado -->
                        <template x-if="data.progreso.todas_completadas">
                            <div class="bg-green-50 dark:bg-green-900/20 rounded-xl border border-green-200 dark:border-green-800 p-6 text-center">
                                <i data-lucide="party-popper" class="w-12 h-12 text-green-500 mx-auto mb-3"></i>
                                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">¡Día completado!</h2>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Excelente trabajo</p>
                            </div>
                        </template>

                        <!-- Progreso en curso -->
                        <template x-if="!data.progreso.todas_completadas">
                            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                                <p class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-3">Tu día de hoy</p>
                                <div class="w-full h-4 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden flex">
                                    <div class="bg-green-500 h-full transition-all duration-500"
                                         :style="'width:' + porcentaje(data.progreso.completadas) + '%'"></div>
                                    <div class="bg-blue-500 h-full transition-all duration-500"
                                         :style="'width:' + porcentaje(data.progreso.en_progreso) + '%'"></div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!--
                        Flujo "una habitación a la vez": el trabajador ve SOLO su
                        habitación actual, nunca la lista completa. Al terminarla (o
                        saltarla), el backend promueve la siguiente. Ver
                        docs/home-trabajador.md §7.
                    -->
                    <!-- Sección 3: Habitación actual (única que ve el trabajador) -->
                    <template x-if="data.habitacion_actual">
                        <div class="px-4 mt-4">
                            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5" data-tour="htr.actual">
                                <p class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-3">Habitación actual</p>
                                <div class="mb-4">
                                    <!-- Hotel de la pieza: misma etiqueta y colores que la tarjeta de Habitaciones -->
                                    <p class="text-xs uppercase tracking-wide font-semibold"
                                       :class="etiquetaHotel(data.habitacion_actual.hotel_codigo)"
                                       x-text="hotelCorto(data.habitacion_actual.hotel_codigo)"></p>
                                    <p class="text-4xl font-bold text-gray-900 dark:text-gray-100" x-text="data.habitacion_actual.numero"></p>
                                    <p class="text-base text-gray-600 dark:text-gray-400 mt-1" x-text="data.habitacion_actual.tipo"></p>
                                    <div class="mt-2 flex items-center gap-2 flex-wrap">
                                        <span x-html="badgeEstado(data.habitacion_actual.estado)"></span>
                                        <template x-if="data.habitacion_actual.franja">
                                            <span class="text-xs px-2 py-0.5 rounded-full bg-teal-100 dark:bg-teal-900/40 text-teal-800 dark:text-teal-200 capitalize"
                                                  x-text="data.habitacion_actual.franja"></span>
                                        </template>
                                        <template x-if="data.habitacion_actual.toca_sabanas">
                                            <span class="text-xs px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200 inline-flex items-center gap-1">
                                                <i data-lucide="bed-double" class="w-3.5 h-3.5"></i> Cambio de sábanas
                                            </span>
                                        </template>
                                    </div>
                                </div>
                                <a :href="u('/habitaciones/' + data.habitacion_actual.id)"
                                   class="block w-full min-h-[56px] bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white text-lg font-semibold rounded-xl transition shadow-sm flex items-center justify-center">
                                    <span x-text="data.habitacion_actual.estado === 'en_progreso' ? 'Continuar' : 'Acceder'"></span>
                                </a>
                                <?php if ($usuario->tienePermiso('habitaciones.saltar')): ?>
                                <button type="button" @click="abrirSaltar()" data-tour="htr.saltar"
                                        class="block w-full min-h-[44px] mt-2 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 text-sm font-medium rounded-xl transition">
                                    No puedo limpiarla ahora
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            <?php if ($usuario->tienePermiso('tickets.crear')): ?>
            <!-- Reportar problema -->
            <div class="px-4 mt-6">
                <button type="button"
                        @click="reportarProblema()" data-tour="htr.reportar"
                        class="w-full min-h-[52px] inline-flex items-center justify-center gap-2 px-4 py-2
                               bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600
                               text-gray-700 dark:text-gray-200 rounded-xl font-medium
                               hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500"></i>
                    Reportar un problema
                </button>
            </div>
            <?php endif; ?>

            <!-- Cerrar turno -->
            <div class="px-4 mt-6 pb-2">
                <button type="button"
                        @click="cerrarSesion()"
                        :disabled="cerrando"
                        class="w-full min-h-[56px] inline-flex items-center justify-center gap-2.5 px-4 py-3
                               bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800
                               text-red-700 dark:text-red-300 rounded-xl font-semibold text-base
                               hover:bg-red-100 dark:hover:bg-red-900/40 active:bg-red-200 transition
                               disabled:opacity-60 disabled:cursor-not-allowed">
                    <i data-lucide="log-out" class="w-5 h-5 flex-shrink-0"></i>
                    <span x-text="cerrando ? 'Cerrando sesión...' : 'Terminar turno y cerrar sesión'"></span>
                </button>
            </div>

        </main>
    </template>

    <!-- Modal "No puedo limpiarla ahora" — mismo motivo/flujo que habitacion-detalle.php,
         pero accesible directo desde la ficha del Home (sin entrar a la habitación). -->
    <div x-show="mostrarSaltar" x-cloak
         x-ref="modalSaltar"
         x-effect="mostrarSaltar && $nextTick(() => { var b = $refs.modalSaltar.querySelector('button'); if (b) b.focus(); })"
         @keydown.escape.window="mostrarSaltar && !saltando && cerrarSaltar()"
         @keydown.tab.window="atraparTabGenerico($event, $refs.modalSaltar, mostrarSaltar)"
         @keydown.window="manejarAltSaltar($event)"
         @keyup.window="altSaltar = false"
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
         @click.self="cerrarSaltar()">
        <div class="bg-white dark:bg-gray-800 rounded-xl max-w-sm w-full p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">No puedo limpiarla ahora</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                Elige un motivo. Se avisará a tu supervisora y esta habitación volverá más adelante a tu lista.
            </p>
            <div class="space-y-2 mb-4">
                <template x-for="m in motivosSaltar" :key="m">
                    <button type="button"
                            @click="motivoSaltar = m"
                            :class="motivoSaltar === m
                                ? 'border-blue-600 bg-blue-50 dark:bg-blue-900/30 text-blue-800 dark:text-blue-200'
                                : 'border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700'"
                            class="w-full min-h-[44px] text-left px-4 py-2 border rounded-lg text-sm font-medium transition">
                        <span x-text="m"></span>
                    </button>
                </template>
            </div>
            <template x-if="motivoSaltar === 'Otro'">
                <textarea x-model="motivoOtro" rows="2" maxlength="200"
                          placeholder="Cuéntanos brevemente qué pasó"
                          class="w-full mb-4 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500"></textarea>
            </template>
            <template x-if="errorSaltar">
                <p class="text-sm text-red-600 dark:text-red-400 mb-4" x-text="errorSaltar"></p>
            </template>
            <div class="flex gap-3">
                <button @click="cerrarSaltar()" :disabled="saltando"
                        class="flex-1 min-h-[44px] px-4 py-2 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 font-medium rounded-lg transition disabled:opacity-50">
                    <span :class="altSaltar ? 'underline' : ''">C</span>ancelar
                </button>
                <button @click="confirmarSaltar()" :disabled="saltando || !motivoSaltarValido"
                        class="flex-1 min-h-[44px] px-4 py-2 bg-amber-600 hover:bg-amber-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-medium rounded-lg transition">
                    <template x-if="saltando"><span>Enviando...</span></template>
                    <template x-if="!saltando"><span>C<span :class="altSaltar ? 'underline' : ''">o</span>nfirmar</span></template>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function homeTrabajador() {
    return {
        data: null,
        cargando: false,
        error: null,
        sinConexion: !navigator.onLine,
        enviandoAviso: false,
        cerrando: false,
        _intervalId: null,
        mostrarSaltar: false,
        altSaltar: false, // Alt/Option presionado: subraya C/O del modal "No puedo limpiarla ahora"
        saltando: false,
        motivoSaltar: null,
        motivoOtro: '',
        motivosSaltar: ['Huésped no ha salido', 'Falta un insumo', 'Requiere mantención', 'Otro'],
        errorSaltar: null,

        get motivoSaltarValido() {
            if (this.motivoSaltar === 'Otro') return this.motivoOtro.trim().length > 0;
            return !!this.motivoSaltar;
        },

        async cargar() {
            this.cargando = true;
            this.error = null;
            try {
                var resp = await fetch(u('/api/home/trabajador'));
                var json = await resp.json();
                if (json.ok) {
                    this.data = json.data;
                } else {
                    this.error = json.error?.mensaje || 'Error al cargar.';
                }
            } catch (e) {
                this.error = 'No pudimos conectar con el servidor.';
            } finally {
                this.cargando = false;
                this.$nextTick(function() { lucide.createIcons(); });
            }
        },

        iniciarRefresco() {
            // Refresco automático cada 5 minutos
            this._intervalId = setInterval(() => this.cargar(), 300000);

            // Detectar conexión
            window.addEventListener('online', () => { this.sinConexion = false; this.cargar(); });
            window.addEventListener('offline', () => { this.sinConexion = true; });
        },

        alVolverVisible() {
            if (!document.hidden) {
                this.cargar();
            }
        },

        // Etiqueta del hotel de la pieza: mismo texto y color que hotelCorto()/etiquetaHotel()
        // de habitaciones.php (clases .hotel-chip-* de custom.css, editables en Ajustes → Colores).
        hotelCorto(codigo) {
            if (codigo === '1_sur') return 'Atankalama';
            if (codigo === 'inn') return 'Atankalama INN';
            return codigo || '';
        },

        etiquetaHotel(codigo) {
            if (codigo === '1_sur' || codigo === 'inn') return 'hotel-chip-' + codigo;
            return 'text-gray-500 dark:text-gray-400';
        },

        porcentaje(valor) {
            if (!this.data || this.data.progreso.total === 0) return 0;
            return Math.round((valor / this.data.progreso.total) * 100);
        },

        badgeEstado(estado) {
            // Colores por estado: clases semánticas .chip-estado-* (editables en Ajustes → Colores).
            var configs = {
                'pendiente': { texto: 'Pendiente', clase: 'chip-estado-sucia' },
                'en_progreso': { texto: 'En progreso', clase: 'chip-estado-en_progreso' },
                'completada': { texto: 'Completada', clase: 'chip-estado-completada_pendiente_auditoria' },
                'aprobada': { texto: 'Aprobada', clase: 'chip-estado-aprobada' },
                // El trabajador NO distingue "con observación" de "aprobada". Siempre "Aprobada".
                'aprobada_con_observacion': { texto: 'Aprobada', clase: 'chip-estado-aprobada_con_observacion' },
                'rechazada': { texto: 'Rechazada', clase: 'chip-estado-rechazada' },
            };
            var c = configs[estado] || { texto: estado, clase: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200' };
            return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' + c.clase + '">' + escapeHtml(c.texto) + '</span>';
        },

        reportarProblema() {
            var detail = {};
            if (this.data && this.data.habitacion_actual) {
                // Número y hotel además del id: el modal de un trabajador no puede buscarlos
                // en /api/habitaciones (ver modal-ticket-nuevo.js, abrir()).
                detail.habitacionId = this.data.habitacion_actual.id;
                detail.habitacionNumero = this.data.habitacion_actual.numero;
                detail.hotelCodigo = this.data.habitacion_actual.hotel_codigo;
            }
            window.dispatchEvent(new CustomEvent('abrir-modal-ticket', { detail: detail }));
        },

        abrirSaltar() {
            this.mostrarSaltar = true;
            this.motivoSaltar = null;
            this.motivoOtro = '';
            this.errorSaltar = null;
        },

        cerrarSaltar() {
            if (this.saltando) return;
            this.mostrarSaltar = false;
        },

        // Accesibilidad de teclado del modal "No puedo limpiarla ahora" (accesibilidad-teclado.md).
        // Sin letra para los motivos (lista dinámica); "O" en vez de "C" para Confirmar porque
        // "Cancelar" ya usa la C (cOnfirmar).
        manejarAltSaltar(e) {
            if (!this.mostrarSaltar) return;
            if (e.key === 'Alt') { this.altSaltar = true; return; }
            if (!e.altKey || this.saltando) return;
            if (e.code === 'KeyC') { e.preventDefault(); this.cerrarSaltar(); }
            else if (e.code === 'KeyO' && this.motivoSaltarValido) { e.preventDefault(); this.confirmarSaltar(); }
        },

        // Focus trap genérico (accesibilidad-teclado.md): el modal tiene contenido variable
        // (lista de motivos + textarea condicional), no un par fijo de botones.
        atraparTabGenerico(e, elModal, activo) {
            if (!activo || !elModal) return;
            var focables = Array.prototype.slice.call(
                elModal.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])')
            );
            if (focables.length === 0) return;
            var primero = focables[0], ultimo = focables[focables.length - 1];
            if (e.shiftKey) {
                if (document.activeElement === primero || !elModal.contains(document.activeElement)) {
                    e.preventDefault(); ultimo.focus();
                }
            } else {
                if (document.activeElement === ultimo || !elModal.contains(document.activeElement)) {
                    e.preventDefault(); primero.focus();
                }
            }
        },

        // Salta la habitación actual sin pasar por su ficha de detalle. La habitación
        // recién asignada todavía no tiene ejecución en progreso (el trabajador no llegó
        // a tocar "Comenzar limpieza"), así que primero la abrimos con /iniciar —es
        // idempotente, igual que hace habitacion-detalle.php al cargar— y recién ahí
        // /saltar tiene algo que saltar. Mismo backend y mismas reglas de negocio que
        // el flujo "No puedo terminar esta ahora" del detalle; no se duplica lógica.
        async confirmarSaltar() {
            if (this.saltando || !this.motivoSaltarValido || !this.data || !this.data.habitacion_actual) return;
            this.saltando = true;
            this.errorSaltar = null;
            var habitacionId = this.data.habitacion_actual.id;
            var motivo = this.motivoSaltar === 'Otro' ? this.motivoOtro.trim() : this.motivoSaltar;
            try {
                var rIniciar = await apiPost('/api/habitaciones/' + habitacionId + '/iniciar', {});
                if (!rIniciar || !rIniciar.ok) {
                    this.errorSaltar = (rIniciar && rIniciar.error && rIniciar.error.mensaje) || 'No pudimos abrir la habitación.';
                    return;
                }
                var rSaltar = await apiPost('/api/habitaciones/' + habitacionId + '/saltar', { motivo: motivo });
                if (!rSaltar || !rSaltar.ok) {
                    this.errorSaltar = (rSaltar && rSaltar.error && rSaltar.error.mensaje) || 'No pudimos registrar el salto.';
                    return;
                }
                this.mostrarSaltar = false;
                await this.cargar();
            } catch (e) {
                this.errorSaltar = 'No pudimos conectar con el servidor.';
            } finally {
                this.saltando = false;
            }
        },

        async cerrarSesion() {
            if (this.cerrando) return;
            this.cerrando = true;
            try {
                await fetch(u('/api/auth/logout'), { method: 'POST' });
            } catch (e) { /* continuar igual */ }
            window.location.href = u('/login');
        },

        async avisarDisponibilidad() {
            if (this.enviandoAviso || this.data.aviso_disponibilidad_enviado_hoy) return;
            this.enviandoAviso = true;
            try {
                var resp = await fetch(u('/api/disponibilidad/avisar'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                });
                var json = await resp.json();
                if (json.ok) {
                    this.data.aviso_disponibilidad_enviado_hoy = true;
                }
            } catch (e) {
                // Silencioso — el usuario puede reintentar
            } finally {
                this.enviandoAviso = false;
            }
        }
    };
}
</script>
