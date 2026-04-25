<?php
/**
 * Popup global de notificaciones.
 * Se activa con el evento de ventana 'toggle-notif'.
 * Opción A: marcar todo como leído al abrir.
 */
?>

<div x-data="notificacionesPopup()" @toggle-notif.window="abrir()">

    <!-- Overlay para cerrar al hacer clic fuera -->
    <div x-show="abierto" x-cloak
         class="fixed inset-0 z-40"
         @click="cerrar()"></div>

    <!-- Panel popup -->
    <div x-show="abierto" x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-2"
         class="fixed top-[68px] right-2 sm:right-4 z-50
                w-[calc(100vw-1rem)] sm:w-96
                bg-white dark:bg-gray-800
                border border-gray-200 dark:border-gray-700
                rounded-xl shadow-2xl
                flex flex-col
                max-h-[80vh] overflow-hidden">

        <!-- Header -->
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Notificaciones</h3>
            <button @click="cerrar()"
                    class="min-h-[32px] min-w-[32px] flex items-center justify-center rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <!-- Cargando -->
        <template x-if="cargando">
            <div class="flex items-center justify-center py-10">
                <svg class="animate-spin h-6 w-6 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            </div>
        </template>

        <!-- Lista de notificaciones -->
        <template x-if="!cargando && lista.length > 0">
            <div class="overflow-y-auto flex-1 divide-y divide-gray-100 dark:divide-gray-700">
                <template x-for="n in lista" :key="n.id">
                    <a :href="n.url"
                       @click="cerrar()"
                       class="flex items-start gap-3 px-4 py-3.5 hover:bg-gray-50 dark:hover:bg-gray-700/40 transition">

                        <!-- Ícono por tipo -->
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5"
                             :class="iconoBg(n.tipo)">
                            <i :data-lucide="iconoNombre(n.tipo)" class="w-4 h-4" :class="iconoColor(n.tipo)"></i>
                        </div>

                        <!-- Texto -->
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100 leading-snug"
                               x-text="n.titulo"></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 line-clamp-2"
                               x-text="n.cuerpo"></p>
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1"
                               x-text="relativo(n.created_at)"></p>
                        </div>

                        <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 dark:text-gray-600 flex-shrink-0 mt-1"></i>
                    </a>
                </template>
            </div>
        </template>

        <!-- Vacío -->
        <template x-if="!cargando && lista.length === 0">
            <div class="flex flex-col items-center justify-center py-10 px-4 text-center">
                <i data-lucide="bell-off" class="w-10 h-10 text-gray-300 dark:text-gray-600 mb-3"></i>
                <p class="text-sm text-gray-500 dark:text-gray-400">Sin notificaciones</p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Las novedades aparecerán aquí.</p>
            </div>
        </template>
    </div>
</div>

<script>
function notificacionesPopup() {
    return {
        abierto:  false,
        cargando: false,
        lista:    [],

        async abrir() {
            if (this.abierto) {
                this.cerrar();
                return;
            }
            this.abierto  = true;
            this.cargando = true;
            try {
                var resp = await fetch('/api/notificaciones');
                var json = await resp.json();
                if (json.ok) {
                    this.lista = json.data.notificaciones;
                    // Opción A: badge a 0 al abrir
                    if (typeof Alpine !== 'undefined' && Alpine.store('notif')) {
                        Alpine.store('notif').sinLeer = 0;
                    }
                    this.$nextTick(function() { lucide.createIcons(); });
                }
            } catch (e) { /* silencioso */ } finally {
                this.cargando = false;
            }
        },

        cerrar() {
            this.abierto = false;
        },

        iconoBg(tipo) {
            return {
                asignacion: 'bg-blue-50 dark:bg-blue-900/30',
                rechazo:    'bg-red-50 dark:bg-red-900/30',
                riesgo:     'bg-amber-50 dark:bg-amber-900/30',
                disponible: 'bg-emerald-50 dark:bg-emerald-900/30',
                auditoria:  'bg-purple-50 dark:bg-purple-900/30',
            }[tipo] || 'bg-gray-100 dark:bg-gray-700';
        },

        iconoNombre(tipo) {
            return {
                asignacion: 'clipboard-list',
                rechazo:    'x-circle',
                riesgo:     'clock',
                disponible: 'check-circle',
                auditoria:  'shield-check',
            }[tipo] || 'bell';
        },

        iconoColor(tipo) {
            return {
                asignacion: 'text-blue-600 dark:text-blue-400',
                rechazo:    'text-red-600 dark:text-red-400',
                riesgo:     'text-amber-600 dark:text-amber-400',
                disponible: 'text-emerald-600 dark:text-emerald-400',
                auditoria:  'text-purple-600 dark:text-purple-400',
            }[tipo] || 'text-gray-500 dark:text-gray-400';
        },

        relativo(iso) {
            if (!iso) return '';
            var diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
            if (diff < 60)   return 'Hace un momento';
            if (diff < 3600) return 'Hace ' + Math.floor(diff / 60) + ' min';
            if (diff < 86400) return 'Hace ' + Math.floor(diff / 3600) + ' h';
            var dias = Math.floor(diff / 86400);
            return 'Hace ' + dias + (dias === 1 ? ' día' : ' días');
        },
    };
}
</script>
