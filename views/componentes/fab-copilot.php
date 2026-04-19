<?php
/**
 * Panel del copilot IA + FAB.
 * Visible para usuarios con permiso copilot.usar_nivel_1_consultas.
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario).
 */
$puedeVerHistorial = $usuario->tienePermiso('copilot.ver_historial_propio');
?>

<div x-data="copilotApp(<?= $puedeVerHistorial ? 'true' : 'false' ?>)"
     @keydown.escape.window="cerrar()">

    <!-- FAB -->
    <button type="button" @click="toggle()"
            class="fixed bottom-20 right-4 md:bottom-6 md:right-6 z-40
                   w-14 h-14 rounded-full bg-blue-600 hover:bg-blue-700 dark:bg-blue-500 dark:hover:bg-blue-600
                   shadow-lg hover:shadow-xl transition
                   flex items-center justify-center text-white
                   focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900"
            aria-label="Abrir copilot IA">
        <i data-lucide="sparkles" class="w-6 h-6" x-show="!abierto"></i>
        <i data-lucide="x" class="w-6 h-6" x-show="abierto" x-cloak></i>
    </button>

    <!-- Backdrop -->
    <div x-show="abierto" x-cloak
         @click="cerrar()"
         x-transition.opacity
         class="fixed inset-0 z-40 bg-black/40"></div>

    <!-- Panel -->
    <div x-show="abierto" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="translate-y-full md:translate-y-0 md:translate-x-full opacity-0"
         x-transition:enter-end="translate-y-0 md:translate-x-0 opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="translate-y-0 md:translate-x-0 opacity-100"
         x-transition:leave-end="translate-y-full md:translate-y-0 md:translate-x-full opacity-0"
         class="fixed z-50 bg-white dark:bg-gray-800 shadow-2xl flex flex-col
                inset-x-0 bottom-0 max-h-[85vh] rounded-t-2xl
                md:inset-auto md:top-0 md:right-0 md:bottom-0 md:w-96 md:max-h-none md:rounded-none md:rounded-l-2xl">

        <!-- Header -->
        <header class="sticky top-0 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <template x-if="vista === 'historial'">
                    <button type="button" @click="irAChat()" class="min-h-[36px] min-w-[36px] flex items-center justify-center -ml-2" aria-label="Volver">
                        <i data-lucide="arrow-left" class="w-5 h-5 text-gray-600 dark:text-gray-300"></i>
                    </button>
                </template>
                <i data-lucide="sparkles" class="w-5 h-5 text-blue-600 dark:text-blue-400" x-show="vista === 'chat'"></i>
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100"
                    x-text="vista === 'chat' ? 'Asistente' : 'Historial'"></h2>
            </div>
            <div class="flex items-center gap-1">
                <template x-if="vista === 'chat' && puedeVerHistorial">
                    <button type="button" @click="irAHistorial()"
                            class="min-h-[36px] min-w-[36px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700"
                            aria-label="Ver historial" title="Historial">
                        <i data-lucide="history" class="w-4 h-4 text-gray-600 dark:text-gray-300"></i>
                    </button>
                </template>
                <template x-if="vista === 'chat'">
                    <button type="button" @click="nuevaConversacion()"
                            class="min-h-[36px] min-w-[36px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700"
                            aria-label="Nueva conversación" title="Nueva conversación">
                        <i data-lucide="square-pen" class="w-4 h-4 text-gray-600 dark:text-gray-300"></i>
                    </button>
                </template>
                <button type="button" @click="cerrar()"
                        class="min-h-[36px] min-w-[36px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700"
                        aria-label="Cerrar">
                    <i data-lucide="x" class="w-4 h-4 text-gray-600 dark:text-gray-300"></i>
                </button>
            </div>
        </header>

        <!-- Vista Chat -->
        <div x-show="vista === 'chat'" class="flex-1 flex flex-col min-h-0">
            <!-- Mensajes -->
            <div class="flex-1 overflow-y-auto px-4 py-3 space-y-3" x-ref="mensajes">
                <template x-if="mensajes.length === 0 && !enviando">
                    <div class="text-center text-sm text-gray-500 dark:text-gray-400 py-8">
                        <i data-lucide="message-circle" class="w-8 h-8 mx-auto mb-2 opacity-50"></i>
                        <p>Hola, ¿en qué te puedo ayudar?</p>
                    </div>
                </template>
                <template x-for="(m, idx) in mensajes" :key="idx">
                    <div>
                        <template x-if="m.rol === 'user'">
                            <div class="flex justify-end">
                                <div class="bg-blue-600 text-white text-sm rounded-2xl rounded-br-md px-4 py-2 max-w-[80%] whitespace-pre-wrap break-words"
                                     x-text="m.contenido"></div>
                            </div>
                        </template>
                        <template x-if="m.rol === 'assistant' && m.contenido">
                            <div class="flex justify-start">
                                <div class="bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm rounded-2xl rounded-bl-md px-4 py-2 max-w-[80%] whitespace-pre-wrap break-words"
                                     x-text="m.contenido"></div>
                            </div>
                        </template>
                        <template x-if="m.rol === 'tool'">
                            <div class="flex justify-start">
                                <div class="inline-flex items-center gap-1.5 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 text-blue-700 dark:text-blue-300 text-xs rounded-full px-3 py-1">
                                    <i data-lucide="wrench" class="w-3 h-3"></i>
                                    <span x-text="'Usé: ' + (m.tool_name || 'herramienta')"></span>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
                <template x-if="enviando">
                    <div class="flex justify-start">
                        <div class="bg-gray-100 dark:bg-gray-700 rounded-2xl rounded-bl-md px-4 py-3 inline-flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-gray-400 dark:bg-gray-500 animate-pulse" style="animation-delay:0ms"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-gray-400 dark:bg-gray-500 animate-pulse" style="animation-delay:150ms"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-gray-400 dark:bg-gray-500 animate-pulse" style="animation-delay:300ms"></span>
                        </div>
                    </div>
                </template>
                <template x-if="error">
                    <div class="bg-rose-50 dark:bg-rose-900/20 border border-rose-200 dark:border-rose-800 text-rose-700 dark:text-rose-300 text-xs rounded-lg px-3 py-2"
                         x-text="error"></div>
                </template>
            </div>

            <!-- Input -->
            <form @submit.prevent="enviar()" class="border-t border-gray-200 dark:border-gray-700 p-3 flex items-end gap-2">
                <textarea x-model="mensajeEnCurso"
                          @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); enviar(); }"
                          rows="1"
                          placeholder="Escribe tu pregunta..."
                          :disabled="enviando"
                          class="flex-1 min-h-[44px] max-h-32 px-3 py-2 text-sm bg-gray-100 dark:bg-gray-700
                                 border-0 rounded-lg text-gray-900 dark:text-gray-100
                                 placeholder-gray-500 dark:placeholder-gray-400 resize-none
                                 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50"></textarea>
                <button type="submit"
                        :disabled="mensajeEnCurso.trim() === '' || enviando"
                        class="min-h-[44px] min-w-[44px] flex items-center justify-center
                               rounded-lg bg-blue-600 hover:bg-blue-700 text-white
                               disabled:opacity-50 disabled:cursor-not-allowed transition">
                    <i data-lucide="send" class="w-4 h-4"></i>
                </button>
            </form>
        </div>

        <!-- Vista Historial -->
        <div x-show="vista === 'historial'" class="flex-1 overflow-y-auto px-2 py-2">
            <template x-if="cargandoHistorial">
                <div class="text-center text-sm text-gray-500 dark:text-gray-400 py-8">Cargando…</div>
            </template>
            <template x-if="!cargandoHistorial && conversaciones.length === 0">
                <div class="text-center text-sm text-gray-500 dark:text-gray-400 py-8">
                    <i data-lucide="inbox" class="w-8 h-8 mx-auto mb-2 opacity-50"></i>
                    <p>Sin conversaciones previas.</p>
                </div>
            </template>
            <ul class="space-y-1">
                <template x-for="c in conversaciones" :key="c.id">
                    <li class="flex items-stretch group rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <button type="button" @click="abrirConversacion(c.id)"
                                class="flex-1 text-left px-3 py-2 min-w-0">
                            <p class="text-sm text-gray-900 dark:text-gray-100 truncate" x-text="c.titulo || 'Sin título'"></p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400" x-text="formatearFecha(c.updated_at)"></p>
                        </button>
                        <button type="button" @click.stop="confirmarBorrar(c)"
                                class="min-h-[44px] min-w-[44px] flex items-center justify-center opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity"
                                aria-label="Borrar">
                            <i data-lucide="trash-2" class="w-4 h-4 text-gray-400 hover:text-rose-500"></i>
                        </button>
                    </li>
                </template>
            </ul>
        </div>
    </div>
</div>

<script>
function copilotApp(puedeVerHistorial) {
    return {
        abierto: false,
        vista: 'chat',
        conversacionId: null,
        mensajes: [],
        conversaciones: [],
        mensajeEnCurso: '',
        enviando: false,
        error: '',
        cargandoHistorial: false,
        puedeVerHistorial: puedeVerHistorial,

        init() {
            const guardado = localStorage.getItem('copilot_conversacion_activa');
            this.conversacionId = guardado ? parseInt(guardado, 10) || null : null;
        },

        toggle() {
            this.abierto ? this.cerrar() : this.abrir();
        },

        async abrir() {
            this.abierto = true;
            this.error = '';
            if (this.conversacionId !== null && this.mensajes.length === 0) {
                await this.cargarConversacion(this.conversacionId, false);
            }
            this.$nextTick(() => {
                if (window.lucide) lucide.createIcons();
                this.scrollAlFinal();
            });
        },

        cerrar() {
            this.abierto = false;
        },

        irAHistorial() {
            this.vista = 'historial';
            this.cargarHistorial();
            this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
        },

        irAChat() {
            this.vista = 'chat';
            this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
        },

        nuevaConversacion() {
            this.conversacionId = null;
            this.mensajes = [];
            this.error = '';
            localStorage.removeItem('copilot_conversacion_activa');
            this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
        },

        async enviar() {
            const texto = this.mensajeEnCurso.trim();
            if (texto === '' || this.enviando) return;
            this.error = '';
            this.mensajeEnCurso = '';
            this.mensajes.push({ rol: 'user', contenido: texto });
            this.enviando = true;
            this.$nextTick(() => this.scrollAlFinal());

            try {
                const payload = { mensaje: texto };
                if (this.conversacionId !== null) payload.conversacion_id = this.conversacionId;
                const res = await apiPost('/api/copilot/mensaje', payload);
                if (!res.ok) {
                    this.error = res.error?.mensaje || 'No pudimos procesar tu mensaje.';
                    return;
                }
                const nuevaId = res.data.conversacion_id;
                if (nuevaId && nuevaId !== this.conversacionId) {
                    this.conversacionId = nuevaId;
                    localStorage.setItem('copilot_conversacion_activa', String(nuevaId));
                }
                // Recargar mensajes desde el backend para incluir tool uses ejecutados
                if (this.conversacionId) {
                    await this.cargarConversacion(this.conversacionId, true);
                } else {
                    this.mensajes.push({ rol: 'assistant', contenido: res.data.respuesta || '' });
                }
            } catch (e) {
                this.error = 'No pudimos conectar con el servidor.';
            } finally {
                this.enviando = false;
                this.$nextTick(() => {
                    if (window.lucide) lucide.createIcons();
                    this.scrollAlFinal();
                });
            }
        },

        async cargarConversacion(id, silencioso) {
            try {
                if (!silencioso) this.mensajes = [];
                const res = await apiFetch('/api/copilot/conversaciones/' + id);
                if (!res.ok) {
                    if (!silencioso) this.nuevaConversacion();
                    return;
                }
                this.conversacionId = id;
                localStorage.setItem('copilot_conversacion_activa', String(id));
                this.mensajes = (res.data.mensajes || [])
                    .filter(m => m.rol !== 'tool' || m.tool_name) // ocultar tool_result vacíos
                    .map(m => ({
                        rol: m.rol,
                        contenido: m.contenido || '',
                        tool_name: m.tool_name || null,
                    }))
                    .filter(m => !(m.rol === 'assistant' && m.contenido === '' && !m.tool_name));
                this.vista = 'chat';
            } catch (e) {
                this.error = 'No pudimos cargar la conversación.';
            }
        },

        async cargarHistorial() {
            this.cargandoHistorial = true;
            try {
                const res = await apiFetch('/api/copilot/conversaciones');
                this.conversaciones = (res.ok && res.data.conversaciones) || [];
            } catch (e) {
                this.conversaciones = [];
            } finally {
                this.cargandoHistorial = false;
                this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
            }
        },

        async abrirConversacion(id) {
            await this.cargarConversacion(id, false);
            this.$nextTick(() => {
                if (window.lucide) lucide.createIcons();
                this.scrollAlFinal();
            });
        },

        async confirmarBorrar(c) {
            if (!confirm('¿Borrar la conversación "' + (c.titulo || 'sin título') + '"?')) return;
            try {
                const res = await apiFetch('/api/copilot/conversaciones/' + c.id, { method: 'DELETE' });
                if (!res.ok) return;
                this.conversaciones = this.conversaciones.filter(x => x.id !== c.id);
                if (this.conversacionId === c.id) {
                    this.nuevaConversacion();
                }
            } catch (e) { /* noop */ }
        },

        scrollAlFinal() {
            const el = this.$refs.mensajes;
            if (el) el.scrollTop = el.scrollHeight;
        },

        formatearFecha(iso) {
            if (!iso) return '';
            try {
                const d = new Date(iso);
                if (isNaN(d.getTime())) return iso;
                return d.toLocaleString('es-CL', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
            } catch (e) { return iso; }
        },
    };
}
</script>
