<?php
/**
 * Ajustes → Checklists y listas → pestaña «Listas» (v7.3, pedido de gerencia del 10/10/2026: las acciones
 * no llevan cuadro de texto, se elige de una lista). Una tarjeta por lista, agrupadas por quién la usa; al
 * tocarla se abre el editor: ordenar (↑↓), renombrar (lápiz), activar/desactivar e «Agregar opción», con la
 * vista «Así se ve» tal como la verá quien elige. Todo se guarda al instante. Las opciones no se borran: se
 * desactivan (el historial las conserva) y cada lista conserva al menos una activa.
 *
 * Endpoints (ListasOpcionesController, permiso listas.editar):
 *  - GET  /api/listas                       { listas: [{lista, activas, total}] }
 *  - GET  /api/listas/{lista}?todos=1       { opciones: [{id, nombre, activo, orden}] }
 *  - POST /api/listas/{lista}               { nombre }
 *  - PUT  /api/listas/{lista}/{id}          { nombre?, activo? }
 *  - PUT  /api/listas/{lista}/orden         { ids: [...] }
 *
 * Se incluye desde views/ajustes-checklists.php dentro de un <template x-if>: no lleva header propio, y su
 * componente Alpine (listasOpcionesApp) vive en views/recursos/ajustes/listas-opciones.js.
 */
?>
<div x-data="listasOpcionesApp()" x-init="cargar()">
    <main class="pb-32 md:pb-8 px-4 py-4 max-w-3xl mx-auto space-y-5">
        <p class="text-xs text-gray-500 dark:text-gray-400 bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-900/40 rounded-lg px-3 py-2">
            Estas son las opciones que se eligen en cada acción: ya no hay cuadros para escribir. Si falta una opción, agrégala acá.
            Desactivar una opción la saca de la lista, pero el historial la conserva.
        </p>

        <!-- Cargando -->
        <template x-if="cargando && !resumen">
            <div class="flex flex-col items-center justify-center py-16 gap-3">
                <svg class="animate-spin h-8 w-8 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <p class="text-sm text-gray-500 dark:text-gray-400">Cargando...</p>
            </div>
        </template>

        <!-- Error -->
        <template x-if="error && !resumen">
            <div class="text-center py-12">
                <i data-lucide="alert-circle" class="w-10 h-10 text-red-500 mx-auto mb-3"></i>
                <p class="text-sm text-gray-600 dark:text-gray-300 mb-4" x-text="error"></p>
                <button @click="cargar()" class="min-h-[44px] px-4 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold">Reintentar</button>
            </div>
        </template>

        <!-- Tarjetas, agrupadas por quién usa la lista -->
        <div class="space-y-5" data-tour="lst.tarjetas">
            <template x-for="g in grupos" :key="g.titulo">
                <section x-show="resumen">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2" x-text="g.titulo"></h2>
                    <div class="space-y-2">
                        <template x-for="l in g.listas" :key="l.id">
                            <button type="button" @click="abrir(l)"
                                    class="w-full min-h-[72px] text-left bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 flex items-center gap-3 hover:border-blue-400 dark:hover:border-blue-500 transition">
                                <span class="w-11 h-11 rounded-lg bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                                    <i :data-lucide="l.icono" class="w-5 h-5 text-blue-600 dark:text-blue-400"></i>
                                </span>
                                <span class="flex-1 min-w-0">
                                    <span class="block font-semibold text-gray-900 dark:text-gray-100" x-text="l.titulo"></span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400" x-text="l.cuando"></span>
                                    <span class="block text-xs font-medium text-blue-700 dark:text-blue-300 mt-0.5" x-text="conteo(l.id)"></span>
                                </span>
                                <i data-lucide="chevron-right" class="w-5 h-5 text-gray-400 flex-shrink-0"></i>
                            </button>
                        </template>
                    </div>
                </section>
            </template>
        </div>
    </main>

    <!-- Editor de una lista: hoja desde abajo en el teléfono, ventana centrada en escritorio. x-if (no x-show):
         así las anclas de la ayuda solo existen con la ventana abierta y el modo «diálogo» espera a que se abra. -->
    <template x-if="editor.abierto">
    <div
         @keydown.escape.window="editor.abierto && cerrar()"
         @click.self="cerrar()"
         class="fixed inset-0 z-50 flex items-end md:items-center justify-center p-0 md:p-4 bg-black/50">
        <div class="bg-white dark:bg-gray-800 rounded-t-2xl md:rounded-xl max-w-lg w-full shadow-xl max-h-[92vh] md:max-h-[88vh] flex flex-col"
             role="dialog" aria-modal="true" aria-labelledby="lst-editor-titulo">
            <div class="flex items-start justify-between gap-2 p-5 border-b border-gray-200 dark:border-gray-700">
                <div class="min-w-0">
                    <h3 id="lst-editor-titulo" class="text-lg font-semibold text-gray-900 dark:text-gray-100" x-text="editor.lista ? editor.lista.titulo : ''"></h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400" x-text="editor.lista ? ('Lo ve: ' + editor.lista.quien + ', ' + editor.lista.cuando) : ''"></p>
                </div>
                <button type="button" @click="cerrar()" aria-label="Cerrar"
                        class="min-h-[44px] min-w-[44px] -mr-2 -mt-2 flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto p-5 space-y-5">
                <template x-if="editor.cargando && editor.opciones.length === 0">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Cargando opciones...</p>
                </template>
                <template x-if="editor.error">
                    <div class="p-3 rounded-lg bg-red-50 dark:bg-red-900/30 text-sm text-red-700 dark:text-red-300">
                        <p x-text="editor.error"></p>
                        <button type="button" @click="cargarOpciones()" class="mt-2 min-h-[40px] px-3 rounded-lg bg-white dark:bg-gray-800 border border-red-300 dark:border-red-700 font-medium">Reintentar</button>
                    </div>
                </template>

                <!-- Opciones: ordenar, renombrar, activar -->
                <ul class="space-y-2" data-tour="lst.opciones">
                    <template x-for="(o, i) in editor.opciones" :key="o.id">
                        <li class="border border-gray-200 dark:border-gray-700 rounded-xl p-2 flex items-center gap-1"
                            :class="o.activo ? 'bg-white dark:bg-gray-800' : 'bg-gray-50 dark:bg-gray-900/40'">
                            <button type="button" @click="mover(i, -1)" :disabled="i === 0 || editor.guardando" :aria-label="'Subir ' + o.nombre"
                                    class="min-h-[44px] min-w-[36px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-30">
                                <i data-lucide="chevron-up" class="w-4 h-4 text-gray-600 dark:text-gray-400"></i>
                            </button>
                            <button type="button" @click="mover(i, 1)" :disabled="i === editor.opciones.length - 1 || editor.guardando" :aria-label="'Bajar ' + o.nombre"
                                    class="min-h-[44px] min-w-[36px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-30">
                                <i data-lucide="chevron-down" class="w-4 h-4 text-gray-600 dark:text-gray-400"></i>
                            </button>

                            <template x-if="editor.editandoId !== o.id">
                                <div class="flex-1 min-w-0 px-1">
                                    <span class="block text-sm font-medium break-words"
                                          :class="o.activo ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400 dark:text-gray-500 line-through'"
                                          x-text="o.nombre"></span>
                                    <span x-show="!o.activo" class="text-[11px] text-gray-500 dark:text-gray-400">Desactivada</span>
                                </div>
                            </template>
                            <template x-if="editor.editandoId === o.id">
                                <form class="flex-1 min-w-0 flex gap-1" @submit.prevent="guardarNombre(o)">
                                    <input type="text" maxlength="60" x-model="editor.editandoNombre" aria-label="Nombre de la opción"
                                           class="flex-1 min-w-0 min-h-[40px] rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 px-2 text-base">
                                    <button type="submit" class="min-h-[40px] px-3 rounded-lg bg-blue-600 text-white text-sm font-semibold">Guardar</button>
                                    <button type="button" @click="editor.editandoId = null" aria-label="Cancelar"
                                            class="min-h-[40px] min-w-[40px] rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 flex items-center justify-center">
                                        <i data-lucide="x" class="w-4 h-4"></i>
                                    </button>
                                </form>
                            </template>

                            <button type="button" x-show="editor.editandoId !== o.id" @click="editar(o)" :aria-label="'Cambiar el nombre de ' + o.nombre"
                                    class="min-h-[44px] min-w-[40px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                                <i data-lucide="pencil" class="w-4 h-4 text-gray-500 dark:text-gray-400"></i>
                            </button>
                            <button type="button" x-show="editor.editandoId !== o.id" @click="alternarActivo(o)" role="switch"
                                    :aria-checked="o.activo ? 'true' : 'false'" :aria-label="(o.activo ? 'Desactivar ' : 'Activar ') + o.nombre"
                                    :class="o.activo ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600'"
                                    class="relative w-11 h-6 rounded-full transition flex-shrink-0 mx-1">
                                <span :class="o.activo ? 'translate-x-5' : 'translate-x-0.5'"
                                      class="absolute top-0.5 left-0 w-5 h-5 rounded-full bg-white shadow transition-transform"></span>
                            </button>
                        </li>
                    </template>
                </ul>

                <!-- Agregar -->
                <form class="flex gap-2" data-tour="lst.nueva" @submit.prevent="agregar()">
                    <input type="text" maxlength="60" x-model="editor.nuevoNombre" aria-label="Nueva opción"
                           :placeholder="editor.lista ? ('Nueva opción, ej: ' + editor.lista.ejemplo) : 'Nueva opción'"
                           class="flex-1 min-w-0 min-h-[44px] rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 px-3 text-base focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <button type="submit" :disabled="editor.nuevoNombre.trim() === '' || editor.guardando"
                            class="min-h-[44px] px-4 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold disabled:opacity-50 inline-flex items-center gap-1">
                        <i data-lucide="plus" class="w-4 h-4"></i> Agregar
                    </button>
                </form>

                <!-- Así se ve: los botones tal como los ve quien elige (solo las activas, en este orden) -->
                <div class="rounded-xl bg-gray-50 dark:bg-gray-900/40 border border-gray-200 dark:border-gray-700 p-3">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-2" x-text="editor.lista ? ('Así lo ve ' + editor.lista.quienCorto + ':') : ''"></p>
                    <div class="grid grid-cols-2 gap-2">
                        <template x-for="o in editor.opciones.filter(x => x.activo)" :key="'v' + o.id">
                            <span class="min-h-[48px] rounded-xl border-2 border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 text-sm font-medium text-gray-800 dark:text-gray-200 flex items-center" x-text="o.nombre"></span>
                        </template>
                    </div>
                </div>
            </div>

            <div class="p-4 border-t border-gray-200 dark:border-gray-700">
                <button type="button" @click="cerrar()"
                        class="w-full min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition">
                    Listo
                </button>
            </div>
        </div>
    </div>
    </template>

    <!-- Toast -->
    <div x-show="toast.visible" x-cloak x-transition
         :class="toast.tipo === 'error' ? 'bg-rose-600' : 'bg-emerald-600'"
         class="fixed bottom-20 md:bottom-6 left-1/2 -translate-x-1/2 text-white px-4 py-2 rounded-lg shadow-lg text-sm z-[60] max-w-[90%] text-center"
         x-text="toast.mensaje"></div>
</div>

