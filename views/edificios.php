<div x-data="edificiosApp(<?= htmlspecialchars(json_encode($usuario->id)) ?>)" x-init="init()" class="max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Edificios y Mapeo</h1>
            <p class="text-gray-600 dark:text-gray-400">Gestiona los edificios y arrastra las habitaciones a los pisos.</p>
        </div>
        <button @click="abrirModalEdificio()" data-tour="edif.nuevo" class="px-4 py-2 bg-blue-600 text-white font-medium rounded-xl hover:bg-blue-700 transition inline-flex items-center gap-2">
            <i data-lucide="plus" class="w-4 h-4"></i> Nuevo Edificio
        </button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- Lista de Edificios (Izquierda) -->
        <div class="lg:col-span-4 flex flex-col gap-4">
            <div data-tour="edif.lista" class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4">
                <h2 class="font-bold text-lg mb-4 text-gray-900 dark:text-white">Edificios</h2>
                <div class="space-y-4 max-h-[60vh] overflow-y-auto pr-2">
                    <template x-if="edificios.length === 0">
                        <p class="text-sm text-gray-500">No hay edificios registrados.</p>
                    </template>
                    <template x-for="grupo in edificiosPorHotel" :key="grupo.nombre">
                        <div>
                            <h3 class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2" x-text="grupo.nombre"></h3>
                            <div class="space-y-2">
                                <template x-for="ed in grupo.lista" :key="ed.id">
                                    <div @click="seleccionarEdificio(ed)"
                                         class="p-3 rounded-lg border cursor-pointer transition flex items-center justify-between"
                                         :class="edificioSeleccionado && edificioSeleccionado.id === ed.id ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50'">
                                        <div>
                                            <p class="font-semibold text-gray-900 dark:text-white" x-text="ed.nombre"></p>
                                            <p class="text-xs text-gray-500" x-text="ed.pisos + ' pisos'"></p>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <button @click.stop="abrirModalEdificio(ed)" class="p-1.5 text-gray-400 hover:text-blue-600 rounded">
                                                <i data-lucide="edit-3" class="w-4 h-4"></i>
                                            </button>
                                            <button @click.stop="eliminarEdificio(ed.id)" class="p-1.5 text-gray-400 hover:text-red-600 rounded">
                                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Habitaciones sin asignar (Pool) -->
            <div data-tour="edif.pool" class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 flex-1 flex flex-col">
                <h2 class="font-bold text-lg mb-2 text-gray-900 dark:text-white">Habitaciones Sin Edificio</h2>
                <p class="text-xs text-gray-500 mb-4">Arrastra estas habitaciones hacia los pisos del edificio seleccionado.</p>
                <div class="flex-1 overflow-y-auto pr-2"
                     @dragover.prevent
                     @drop.prevent="soltarEnPool($event)">
                    <div class="flex flex-wrap gap-2 min-h-[100px] content-start p-2 border-2 border-dashed border-gray-200 dark:border-gray-700 rounded-lg"
                         :class="draggedHabId ? 'bg-gray-50 dark:bg-gray-700/30' : ''">
                        <template x-if="habitacionesSinAsignar.length === 0">
                            <p class="text-sm text-gray-400 w-full text-center py-4">Todas están asignadas.</p>
                        </template>
                        <template x-for="hab in habitacionesSinAsignar" :key="hab.id">
                            <div class="px-3 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm cursor-grab select-none active:cursor-grabbing font-bold text-gray-800 dark:text-white inline-flex flex-col"
                                 draggable="true"
                                 @dragstart="iniciarDrag($event, hab.id)">
                                <span x-text="hab.numero"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mapeo (Derecha) -->
        <div data-tour="edif.mapa" class="lg:col-span-8">
            <template x-if="!edificioSeleccionado">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-12 text-center h-full flex flex-col items-center justify-center">
                    <i data-lucide="building-2" class="w-12 h-12 text-gray-300 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-1">Ningún edificio seleccionado</h3>
                    <p class="text-gray-500 text-sm">Selecciona o crea un edificio a la izquierda para mapear sus habitaciones.</p>
                </div>
            </template>
            
            <template x-if="edificioSeleccionado">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="font-bold text-xl text-gray-900 dark:text-white">
                            Mapeo: <span class="text-blue-600" x-text="edificioSeleccionado.nombre"></span>
                        </h2>
                    </div>

                    <div class="space-y-6">
                        <template x-for="piso in edificioSeleccionado.pisos" :key="piso">
                            <div>
                                <h3 class="font-semibold text-gray-700 dark:text-gray-300 mb-2 border-b border-gray-200 dark:border-gray-700 pb-1" x-text="'Piso ' + piso"></h3>
                                <div class="min-h-[80px] p-3 rounded-xl border-2 border-dashed border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/50 transition-colors flex flex-wrap gap-2 content-start"
                                     :class="draggedHabId ? 'border-blue-300 dark:border-blue-700 bg-blue-50/30 dark:bg-blue-900/10' : ''"
                                     @dragover.prevent
                                     @drop.prevent="soltarEnPiso($event, piso)">
                                    
                                    <template x-if="habitacionesPorPiso(piso).length === 0">
                                        <div class="w-full h-full flex items-center justify-center text-gray-400 text-sm py-4">
                                            Arrastra habitaciones aquí
                                        </div>
                                    </template>
                                    
                                    <template x-for="hab in habitacionesPorPiso(piso)" :key="hab.id">
                                        <div class="px-3 py-2 bg-white dark:bg-gray-700 border border-blue-200 dark:border-blue-800 rounded-lg shadow-sm cursor-grab active:cursor-grabbing inline-flex items-center gap-2 font-bold text-gray-800 dark:text-white"
                                             draggable="true"
                                             @dragstart="iniciarDrag($event, hab.id)">
                                            <i data-lucide="grip-vertical" class="w-4 h-4 text-gray-400"></i>
                                            <span x-text="hab.numero"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <!-- Modal CRUD Edificio -->
    <template x-if="modalEdificio.abierta">
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="cerrarModalEdificio()">
            <div class="bg-white dark:bg-gray-800 rounded-xl max-w-sm w-full p-6 shadow-xl relative">
                <button @click="cerrarModalEdificio()" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4" x-text="modalEdificio.form.id ? 'Editar Edificio' : 'Nuevo Edificio'"></h3>
                
                <form @submit.prevent="guardarEdificio()">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Hotel</label>
                            <select x-model="modalEdificio.form.hotel_id" class="w-full px-3 py-2 border rounded-lg dark:bg-gray-900 dark:border-gray-600 dark:text-white" required>
                                <option value="">Selecciona...</option>
                                <template x-for="h in hoteles" :key="h.id">
                                    <option :value="h.id" x-text="h.nombre"></option>
                                </template>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nombre del Edificio</label>
                            <input type="text" x-model="modalEdificio.form.nombre" placeholder="Ej. Ala Norte" class="w-full px-3 py-2 border rounded-lg dark:bg-gray-900 dark:border-gray-600 dark:text-white" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Cantidad de Pisos</label>
                            <input type="number" x-model="modalEdificio.form.pisos" min="1" max="20" class="w-full px-3 py-2 border rounded-lg dark:bg-gray-900 dark:border-gray-600 dark:text-white" required>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <button type="button" @click="cerrarModalEdificio()" class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Cancelar</button>
                        <button type="submit" :disabled="modalEdificio.guardando" class="px-4 py-2 text-sm text-white bg-blue-600 hover:bg-blue-700 rounded-lg">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </template>
</div>

<script>
function edificiosApp(usuarioId) {
    return {
        edificios: [],
        habitaciones: [],
        hoteles: [],
        edificioSeleccionado: null,
        draggedHabId: null,

        modalEdificio: {
            abierta: false,
            guardando: false,
            form: { id: null, hotel_id: '', nombre: '', pisos: 1 }
        },

        async init() {
            await Promise.all([this.cargarEdificios(), this.cargarHabitaciones(), this.cargarHoteles()]);
            this.$nextTick(() => lucide.createIcons());
        },

        async cargarEdificios() {
            var r = await apiFetch('/api/edificios');
            if (r.ok) {
                this.edificios = r.data.edificios;
                // Actualizar selección si existe
                if (this.edificioSeleccionado) {
                    this.edificioSeleccionado = this.edificios.find(e => e.id === this.edificioSeleccionado.id) || null;
                }
            }
        },

        async cargarHabitaciones() {
            var r = await apiFetch('/api/habitaciones');
            if (r.ok) {
                this.habitaciones = r.data.habitaciones;
            }
        },

        async cargarHoteles() {
            var r = await apiFetch('/api/hoteles');
            if (r.ok) this.hoteles = r.data.hoteles;
        },

        get edificiosPorHotel() {
            var agrupado = {};
            this.edificios.forEach(e => {
                var hotelNombre = this.hoteles.find(h => h.id === e.hotel_id)?.nombre || 'Hotel ' + e.hotel_id;
                if (!agrupado[hotelNombre]) agrupado[hotelNombre] = [];
                agrupado[hotelNombre].push(e);
            });
            // Convertir a array [{nombre: 'Atankalama', lista: [...]}]
            return Object.keys(agrupado).map(nombre => ({ nombre: nombre, lista: agrupado[nombre] }));
        },

        seleccionarEdificio(ed) {
            this.edificioSeleccionado = ed;
            this.$nextTick(() => lucide.createIcons());
        },

        get habitacionesSinAsignar() {
            return this.habitaciones.filter(h => !h.edificio_id);
        },

        habitacionesPorPiso(piso) {
            if (!this.edificioSeleccionado) return [];
            // == (no ===): edificio_id/piso pueden llegar tipados distinto entre
            // /api/habitaciones y /api/edificios según el driver de BD.
            return this.habitaciones.filter(h => h.edificio_id == this.edificioSeleccionado.id && h.piso == piso);
        },

        // --- Drag & Drop ---
        iniciarDrag(e, habId) {
            this.draggedHabId = habId;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', habId);
        },

        async soltarEnPiso(e, piso) {
            var habId = this.draggedHabId || parseInt(e.dataTransfer.getData('text/plain'), 10);
            this.draggedHabId = null;
            if (!habId || !this.edificioSeleccionado) return;

            var hab = this.habitaciones.find(h => h.id === habId);
            if (!hab || (hab.edificio_id == this.edificioSeleccionado.id && hab.piso == piso)) return;

            // Optimistic update
            var oldEdificioId = hab.edificio_id;
            var oldPiso = hab.piso;
            var oldEdificioStr = hab.edificio;
            
            hab.edificio_id = this.edificioSeleccionado.id;
            hab.edificio = this.edificioSeleccionado.nombre;
            hab.piso = piso;

            var r = await apiPut('/api/habitaciones/' + hab.id + '/estructura', {
                edificio_id: this.edificioSeleccionado.id,
                edificio: this.edificioSeleccionado.nombre,
                piso: piso
            });

            if (!r.ok) {
                // Revert
                hab.edificio_id = oldEdificioId;
                hab.piso = oldPiso;
                hab.edificio = oldEdificioStr;
                alert((r.error && r.error.mensaje) || 'Error al mapear.');
            }
            this.$nextTick(() => lucide.createIcons());
        },

        async soltarEnPool(e) {
            var habId = this.draggedHabId || parseInt(e.dataTransfer.getData('text/plain'), 10);
            this.draggedHabId = null;
            if (!habId) return;

            var hab = this.habitaciones.find(h => h.id === habId);
            if (!hab || !hab.edificio_id) return; // ya está en el pool

            // Optimistic update
            var oldEdificioId = hab.edificio_id;
            var oldPiso = hab.piso;
            var oldEdificioStr = hab.edificio;
            
            hab.edificio_id = null;
            hab.edificio = null;
            hab.piso = null;

            var r = await apiPut('/api/habitaciones/' + hab.id + '/estructura', {
                edificio_id: null,
                edificio: null,
                piso: null
            });

            if (!r.ok) {
                // Revert
                hab.edificio_id = oldEdificioId;
                hab.piso = oldPiso;
                hab.edificio = oldEdificioStr;
                alert((r.error && r.error.mensaje) || 'Error al quitar del mapeo.');
            }
        },

        // --- CRUD Modal ---
        abrirModalEdificio(ed = null) {
            if (ed) {
                this.modalEdificio.form = { id: ed.id, hotel_id: ed.hotel_id, nombre: ed.nombre, pisos: ed.pisos };
            } else {
                this.modalEdificio.form = { id: null, hotel_id: '', nombre: '', pisos: 1 };
            }
            this.modalEdificio.abierta = true;
            this.$nextTick(() => lucide.createIcons());
        },

        cerrarModalEdificio() {
            this.modalEdificio.abierta = false;
        },

        async guardarEdificio() {
            this.modalEdificio.guardando = true;
            try {
                var payload = { ...this.modalEdificio.form };
                var r;
                if (payload.id) {
                    r = await apiPut('/api/edificios/' + payload.id, payload);
                } else {
                    r = await apiPost('/api/edificios', payload);
                }

                if (r.ok) {
                    await this.cargarEdificios();
                    this.cerrarModalEdificio();
                } else {
                    alert((r.error && r.error.mensaje) || 'Error al guardar edificio.');
                }
            } catch (e) {
                alert('No pudimos conectar con el servidor.');
            } finally {
                this.modalEdificio.guardando = false;
            }
        },

        async eliminarEdificio(id) {
            if (!confirm('¿Seguro que deseas eliminar este edificio?')) return;
            var r = await apiFetch('/api/edificios/' + id, { method: 'DELETE' });
            if (r.ok) {
                if (this.edificioSeleccionado && this.edificioSeleccionado.id === id) {
                    this.edificioSeleccionado = null;
                }
                await this.cargarEdificios();
            } else {
                alert((r.error && r.error.mensaje) || 'Error al eliminar edificio.');
            }
        }
    }
}
</script>
