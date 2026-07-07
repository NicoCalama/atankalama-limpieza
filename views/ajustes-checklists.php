<?php
/**
 * Ajustes → Checklists. Editor de los templates de checklist por TIPO de habitación,
 * con peso de créditos por ítem. Ver docs/checklist.md §2.3 y docs/creditos-rework.md.
 *
 * Endpoints:
 *  - GET /api/checklists/templates              { templates: [{id, tipo_nombre, nombre, items_count, creditos_total}] }
 *  - GET /api/checklists/templates/{id}/items   { items: [{id, orden, descripcion, obligatorio, creditos, es_cambio_sabanas}] }
 *  - PUT /api/checklists/templates/{id}         { nombre?, items: [{id?, descripcion, obligatorio, creditos, es_cambio_sabanas?}] }
 *
 * Los templates de espacio (áreas comunes) NO aparecen acá: se editan desde /espacios.
 * Requiere permiso checklists.editar (gateado en PaginasController y en el endpoint PUT).
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */
?>

<div x-data="checklistsApp()" x-init="cargar()">

    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center gap-3 max-w-5xl mx-auto">
            <a href="<?= u('/ajustes') ?>" aria-label="Volver"
               class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                <i data-lucide="arrow-left" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
            </a>
            <div class="min-w-0 flex-1">
                <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Checklists</h1>
                <p class="text-xs text-gray-500 dark:text-gray-400">Ítems y créditos por tipo de habitación</p>
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

    <!-- Carga inicial -->
    <template x-if="cargando && !templates">
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
    <template x-if="error && !templates">
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

    <!-- Lista de templates -->
    <template x-if="templates">
        <main class="pb-32 md:pb-8 px-4 py-4 max-w-5xl mx-auto space-y-4">
            <p class="text-xs text-gray-500 dark:text-gray-400 bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-900/40 rounded-lg px-3 py-2">
                Cada ítem obligatorio otorga sus créditos cuando el trabajador lo completa. Los ítems opcionales no dan créditos.
            </p>

            <template x-if="templates.length === 0">
                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-6 text-center">
                    <i data-lucide="list-checks" class="w-10 h-10 text-gray-400 mx-auto mb-2"></i>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Aún no hay checklists de tipo.</p>
                </div>
            </template>

            <div class="grid gap-3 sm:grid-cols-2">
                <template x-for="t in templates" :key="t.id">
                    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 flex flex-col gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-gray-900 dark:text-gray-100 truncate" x-text="t.tipo_nombre"></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate" x-text="t.nombre"></p>
                        </div>
                        <div class="flex items-center gap-2 text-xs">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200">
                                <i data-lucide="list" class="w-3 h-3"></i>
                                <span x-text="t.items_count + ' ítem' + (Number(t.items_count) === 1 ? '' : 's')"></span>
                            </span>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200">
                                <i data-lucide="coins" class="w-3 h-3"></i>
                                <span x-text="t.creditos_total + ' crédito' + (Number(t.creditos_total) === 1 ? '' : 's')"></span>
                            </span>
                        </div>
                        <button @click="abrirEditor(t)"
                                class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 text-white transition inline-flex items-center justify-center gap-1.5">
                            <i data-lucide="pencil" class="w-4 h-4"></i> Editar checklist
                        </button>
                    </div>
                </template>
            </div>
        </main>
    </template>

    <!-- Modal editor -->
    <div x-show="editor.abierto" x-cloak
         class="fixed inset-0 z-50 flex items-end md:items-center justify-center p-0 md:p-4 bg-black/50"
         @click.self="cerrarEditor()">
        <div class="bg-white dark:bg-gray-800 rounded-t-2xl md:rounded-xl max-w-lg w-full shadow-xl max-h-[92vh] md:max-h-[88vh] flex flex-col">
            <!-- Cabecera del modal -->
            <div class="flex items-start justify-between gap-2 p-5 border-b border-gray-200 dark:border-gray-700">
                <div class="min-w-0">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 truncate" x-text="editor.tipoNombre"></h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        <span x-text="itemsActivos().length"></span> ítem(s) ·
                        <span class="font-medium text-amber-600 dark:text-amber-400" x-text="totalCreditos() + ' créditos'"></span>
                    </p>
                </div>
                <button @click="cerrarEditor()" class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                    <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                </button>
            </div>

            <!-- Cuerpo scrolleable -->
            <div class="flex-1 overflow-y-auto p-5 space-y-3">
                <template x-if="editor.cargandoItems">
                    <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-6">Cargando ítems...</p>
                </template>

                <template x-if="!editor.cargandoItems">
                    <div class="space-y-3">
                        <template x-for="(item, idx) in editor.items" :key="item._key">
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3 space-y-2"
                                 :class="item.obligatorio ? '' : 'opacity-90'">
                                <!-- Fila 1: reordenar + descripción + quitar -->
                                <div class="flex items-start gap-2">
                                    <div class="flex flex-col flex-shrink-0">
                                        <button @click="moverItem(idx, -1)" :disabled="idx === 0" aria-label="Subir"
                                                class="min-h-[22px] min-w-[32px] flex items-center justify-center rounded text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 disabled:opacity-30">
                                            <i data-lucide="chevron-up" class="w-4 h-4"></i>
                                        </button>
                                        <button @click="moverItem(idx, 1)" :disabled="idx === editor.items.length - 1" aria-label="Bajar"
                                                class="min-h-[22px] min-w-[32px] flex items-center justify-center rounded text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 disabled:opacity-30">
                                            <i data-lucide="chevron-down" class="w-4 h-4"></i>
                                        </button>
                                    </div>
                                    <input x-model="item.descripcion" type="text" maxlength="255"
                                           :placeholder="'Ítem ' + (idx + 1) + ' — ej: limpiar y desinfectar baño'"
                                           class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px]">
                                    <button @click="quitarItem(idx)" aria-label="Quitar ítem"
                                            class="min-h-[40px] min-w-[40px] flex items-center justify-center rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 transition flex-shrink-0">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </div>
                                <!-- Fila 2: obligatorio + créditos + sábanas -->
                                <div class="flex items-center gap-3 flex-wrap pl-9">
                                    <label class="inline-flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-200 cursor-pointer select-none">
                                        <input type="checkbox" x-model="item.obligatorio" @change="alCambiarObligatorio(item)"
                                               class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        Obligatorio
                                    </label>
                                    <div x-show="item.obligatorio" class="inline-flex items-center gap-1.5">
                                        <label class="text-xs text-gray-500 dark:text-gray-400">Créditos</label>
                                        <input type="number" x-model.number="item.creditos" min="0" max="100"
                                               class="w-16 px-2 py-1 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm text-center min-h-[36px]">
                                    </div>
                                    <label class="inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400 cursor-pointer select-none ml-auto">
                                        <input type="checkbox" x-model="item.es_cambio_sabanas"
                                               class="w-4 h-4 rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                        Sábanas
                                    </label>
                                </div>
                            </div>
                        </template>

                        <button @click="agregarItem()"
                                class="w-full min-h-[44px] border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-300 hover:border-blue-400 hover:text-blue-600 dark:hover:text-blue-400 transition inline-flex items-center justify-center gap-1.5">
                            <i data-lucide="plus" class="w-4 h-4"></i> Agregar ítem
                        </button>
                    </div>
                </template>
            </div>

            <!-- Pie con acciones -->
            <div class="flex gap-2 p-5 border-t border-gray-200 dark:border-gray-700">
                <button @click="cerrarEditor()"
                        class="flex-1 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition">
                    Cancelar
                </button>
                <button @click="guardar()" :disabled="editor.enviando || editor.cargandoItems"
                        class="flex-1 min-h-[44px] px-4 py-2 text-sm font-semibold rounded-lg bg-blue-600 hover:bg-blue-700 text-white transition disabled:opacity-50">
                    <span x-text="editor.enviando ? 'Guardando...' : 'Guardar cambios'"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function checklistsApp() {
    return {
        templates: null,
        cargando: false,
        error: null,
        toast: { visible: false, tipo: 'exito', mensaje: '' },
        _seq: 0,
        editor: { abierto: false, cargandoItems: false, enviando: false, templateId: null, tipoNombre: '', items: [] },

        async cargar() {
            this.cargando = true;
            this.error = null;
            try {
                var r = await apiFetch('/api/checklists/templates');
                if (!r || !r.ok) {
                    this.error = (r && r.error && r.error.mensaje) || 'Error al cargar.';
                    return;
                }
                this.templates = r.data.templates || [];
            } catch (e) {
                this.error = 'No pudimos conectar con el servidor.';
            } finally {
                this.cargando = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },

        async abrirEditor(t) {
            this.editor = { abierto: true, cargandoItems: true, enviando: false, templateId: t.id, tipoNombre: t.tipo_nombre, items: [] };
            this.$nextTick(function () { lucide.createIcons(); });
            try {
                var r = await apiFetch('/api/checklists/templates/' + t.id + '/items');
                if (r && r.ok) {
                    var self = this;
                    this.editor.items = (r.data.items || []).map(function (i) {
                        return {
                            _key: 'i' + (self._seq++),
                            id: Number(i.id),
                            descripcion: i.descripcion || '',
                            obligatorio: Number(i.obligatorio) === 1,
                            creditos: Number(i.creditos),
                            es_cambio_sabanas: Number(i.es_cambio_sabanas) === 1
                        };
                    });
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos cargar los ítems.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.editor.cargandoItems = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },
        cerrarEditor() {
            this.editor.abierto = false;
        },

        itemsActivos() {
            return this.editor.items.filter(function (i) { return (i.descripcion || '').trim() !== ''; });
        },
        totalCreditos() {
            return this.editor.items.reduce(function (acc, i) {
                if (i.obligatorio && (i.descripcion || '').trim() !== '') {
                    acc += Number(i.creditos) || 0;
                }
                return acc;
            }, 0);
        },

        agregarItem() {
            this.editor.items.push({ _key: 'i' + (this._seq++), descripcion: '', obligatorio: true, creditos: 1, es_cambio_sabanas: false });
            this.$nextTick(function () { lucide.createIcons(); });
        },
        quitarItem(idx) {
            this.editor.items.splice(idx, 1);
            this.$nextTick(function () { lucide.createIcons(); });
        },
        moverItem(idx, dir) {
            var nueva = idx + dir;
            if (nueva < 0 || nueva >= this.editor.items.length) return;
            var item = this.editor.items.splice(idx, 1)[0];
            this.editor.items.splice(nueva, 0, item);
            this.$nextTick(function () { lucide.createIcons(); });
        },
        alCambiarObligatorio(item) {
            // Al volver obligatorio un ítem sin créditos, arranca en 1 (los opcionales no dan créditos).
            if (item.obligatorio && (!item.creditos || item.creditos < 1)) {
                item.creditos = 1;
            }
        },

        async guardar() {
            if (this.editor.enviando) return;
            var items = [];
            for (var i = 0; i < this.editor.items.length; i++) {
                var it = this.editor.items[i];
                var desc = (it.descripcion || '').trim();
                if (desc === '') continue; // fila vacía: se ignora
                var creditos = it.obligatorio ? Math.max(0, Math.min(100, Number(it.creditos) || 0)) : 0;
                var payload = {
                    descripcion: desc,
                    obligatorio: !!it.obligatorio,
                    creditos: creditos,
                    es_cambio_sabanas: !!it.es_cambio_sabanas
                };
                if (it.id) payload.id = it.id;
                items.push(payload);
            }
            if (items.length === 0) {
                this.mostrarToast('error', 'El checklist debe tener al menos un ítem.');
                return;
            }

            this.editor.enviando = true;
            try {
                var r = await apiPut('/api/checklists/templates/' + this.editor.templateId, { items: items });
                if (r && r.ok) {
                    this.mostrarToast('exito', 'Checklist actualizado.');
                    this.cerrarEditor();
                    this.cargar();
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos guardar.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.editor.enviando = false;
            }
        },

        mostrarToast(tipo, mensaje) {
            this.toast = { visible: true, tipo: tipo, mensaje: mensaje };
            var self = this;
            setTimeout(function () { self.toast.visible = false; }, 2500);
        }
    };
}
</script>
