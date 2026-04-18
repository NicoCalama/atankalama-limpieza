<?php
/**
 * Matriz RBAC — permisos × roles editable.
 * Spec: docs/roles-permisos.md
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

$usuarioActualId = $usuario->id;
?>

<div x-data="rbacApp()"
     x-init="cargar()"
     @rol-creado.window="alRolCreado($event.detail)"
     @rol-actualizado.window="alRolActualizado($event.detail)">

    <!-- Header sticky -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between max-w-7xl mx-auto gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <a href="/ajustes" class="md:hidden min-h-[44px] min-w-[44px] flex items-center justify-center -ml-2" aria-label="Volver">
                    <i data-lucide="arrow-left" class="w-5 h-5 text-gray-700 dark:text-gray-300"></i>
                </a>
                <i data-lucide="shield" class="w-6 h-6 text-gray-700 dark:text-gray-300 flex-shrink-0 hidden md:block"></i>
                <div class="min-w-0">
                    <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Roles y permisos</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        <span x-text="roles.length"></span> roles · <span x-text="permisos.length"></span> permisos
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button"
                        @click="abrirCrearRol()"
                        class="min-h-[44px] inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium transition">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                    <span class="hidden sm:inline">Nuevo rol</span>
                    <span class="sm:hidden">Rol</span>
                </button>
                <button type="button" @click="cargar()" :disabled="cargando"
                        class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50"
                        aria-label="Refrescar">
                    <i data-lucide="refresh-cw" class="w-5 h-5 text-gray-600 dark:text-gray-400" :class="cargando ? 'animate-spin' : ''"></i>
                </button>
            </div>
        </div>
    </header>

    <!-- Banner cambios pendientes -->
    <div x-show="totalCambios() > 0" x-cloak
         class="sticky top-[60px] z-30 bg-amber-50 dark:bg-amber-900/30 border-b border-amber-200 dark:border-amber-800 px-4 py-3">
        <div class="max-w-7xl mx-auto flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center gap-2 min-w-0">
                <i data-lucide="alert-circle" class="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0"></i>
                <p class="text-sm text-amber-900 dark:text-amber-100">
                    <strong x-text="totalCambios()"></strong>
                    <span x-text="totalCambios() === 1 ? ' cambio sin guardar' : ' cambios sin guardar'"></span>
                    <span x-show="autoLockWarning()" x-cloak class="block text-xs mt-0.5">
                        ⚠ Perderás acceso a esta pantalla al guardar (quitaste <code>permisos.asignar_a_rol</code> a tu propio rol).
                    </span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" @click="descartar()"
                        class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    Descartar
                </button>
                <button type="button" @click="guardar()" :disabled="guardando"
                        class="min-h-[40px] inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white transition">
                    <template x-if="guardando">
                        <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                    </template>
                    <span x-text="guardando ? 'Guardando...' : 'Guardar cambios'"></span>
                </button>
            </div>
        </div>
    </div>

    <main class="pb-24 md:pb-8 max-w-7xl mx-auto px-4 pt-4">

        <!-- Estado de carga inicial -->
        <template x-if="cargando && roles.length === 0">
            <div class="min-h-[40vh] flex items-center justify-center">
                <div class="flex flex-col items-center gap-3">
                    <svg class="animate-spin h-8 w-8 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <p class="text-gray-600 dark:text-gray-400">Cargando matriz...</p>
                </div>
            </div>
        </template>

        <!-- Matriz -->
        <div x-show="roles.length > 0 && permisos.length > 0" class="overflow-x-auto -mx-4 md:mx-0 md:rounded-xl md:border md:border-gray-200 md:dark:border-gray-700">
            <table class="min-w-full border-collapse bg-white dark:bg-gray-800">
                <thead>
                    <tr>
                        <th class="sticky left-0 z-20 bg-gray-50 dark:bg-gray-900 px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide border-b border-gray-200 dark:border-gray-700 min-w-[260px]">
                            Permiso
                        </th>
                        <template x-for="r in roles" :key="r.id">
                            <th class="bg-gray-50 dark:bg-gray-900 px-3 py-3 text-center border-b border-l border-gray-200 dark:border-gray-700 min-w-[130px]">
                                <div class="flex flex-col items-center gap-1">
                                    <div class="flex items-center gap-1">
                                        <span class="text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="r.nombre"></span>
                                        <template x-if="r.es_sistema === 1">
                                            <span class="text-[9px] uppercase tracking-wide px-1 py-0.5 rounded bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-400" title="Rol de sistema">sys</span>
                                        </template>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <button type="button" @click="abrirEditarRol(r)"
                                                class="min-h-[28px] min-w-[28px] flex items-center justify-center rounded hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-400"
                                                :title="r.es_sistema === 1 ? 'Editar descripción' : 'Editar rol'"
                                                aria-label="Editar">
                                            <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                                        </button>
                                        <template x-if="r.es_sistema !== 1">
                                            <button type="button" @click="eliminarRol(r)"
                                                    class="min-h-[28px] min-w-[28px] flex items-center justify-center rounded hover:bg-red-100 dark:hover:bg-red-900/40 text-red-600 dark:text-red-400"
                                                    title="Eliminar rol"
                                                    aria-label="Eliminar">
                                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </th>
                        </template>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(grupo, cat) in permisosPorCategoria()" :key="cat">
                        <template x-for="(p, idx) in grupo" :key="p.codigo">
                            <tr :class="idx === 0 ? 'border-t-4 border-gray-100 dark:border-gray-900' : ''">
                                <td class="sticky left-0 z-10 bg-white dark:bg-gray-800 px-4 py-3 border-b border-gray-100 dark:border-gray-700 min-w-[260px]">
                                    <template x-if="idx === 0">
                                        <p class="text-[10px] uppercase tracking-wider font-semibold text-blue-600 dark:text-blue-400 mb-1" x-text="cat"></p>
                                    </template>
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <code class="text-xs font-mono text-gray-900 dark:text-gray-100 block truncate" x-text="p.codigo"></code>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5" x-text="p.descripcion"></p>
                                        </div>
                                        <span class="flex-shrink-0 text-[9px] uppercase tracking-wide px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400"
                                              x-text="p.scope"></span>
                                    </div>
                                </td>
                                <template x-for="r in roles" :key="r.id">
                                    <td class="px-3 py-3 text-center border-b border-l border-gray-100 dark:border-gray-700">
                                        <label class="inline-flex items-center justify-center cursor-pointer group">
                                            <input type="checkbox"
                                                   :checked="tienePermisoEditado(r.id, p.codigo)"
                                                   @change="togglePermiso(r.id, p.codigo)"
                                                   class="sr-only peer">
                                            <span class="w-6 h-6 flex items-center justify-center rounded border-2 transition"
                                                  :class="claseCheckbox(r.id, p.codigo)">
                                                <i x-show="tienePermisoEditado(r.id, p.codigo)"
                                                   data-lucide="check"
                                                   class="w-4 h-4"></i>
                                            </span>
                                        </label>
                                    </td>
                                </template>
                            </tr>
                        </template>
                    </template>
                </tbody>
            </table>
        </div>

    </main>

    <!-- Toast -->
    <div x-show="toast" x-cloak x-transition
         class="fixed bottom-24 md:bottom-8 left-1/2 -translate-x-1/2 z-50 px-4 py-2 rounded-full text-sm shadow-lg"
         :class="toastTipo === 'error' ? 'bg-red-600 text-white' : 'bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900'"
         x-text="toast"></div>
</div>

<script>
function rbacApp() {
    return {
        usuarioActualId: <?= (int) $usuarioActualId ?>,
        roles: [],
        permisos: [],
        cambios: {},
        cargando: false,
        guardando: false,
        toast: '',
        toastTipo: 'exito',
        _toastTimer: null,

        async cargar() {
            this.cargando = true;
            try {
                var rRoles = await apiFetch('/api/roles');
                var rPerm = await apiFetch('/api/permisos');
                if (rRoles && rRoles.ok) this.roles = rRoles.data.roles || [];
                if (rPerm && rPerm.ok) this.permisos = rPerm.data.permisos || [];
                this.cambios = {};
            } catch (e) {
                this.mostrarToast('No pudimos conectar con el servidor.', 'error');
            } finally {
                this.cargando = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },

        permisosPorCategoria() {
            var grupos = {};
            this.permisos.forEach(function (p) {
                var cat = p.categoria || 'Otros';
                if (!grupos[cat]) grupos[cat] = [];
                grupos[cat].push(p);
            });
            return grupos;
        },

        rolPorId(rolId) {
            return this.roles.find(r => r.id === rolId);
        },

        tienePermisoOriginal(rolId, codigo) {
            var r = this.rolPorId(rolId);
            if (!r) return false;
            return (r.permisos || []).indexOf(codigo) !== -1;
        },

        tienePermisoEditado(rolId, codigo) {
            var key = rolId + ':' + codigo;
            if (Object.prototype.hasOwnProperty.call(this.cambios, key)) {
                return this.cambios[key];
            }
            return this.tienePermisoOriginal(rolId, codigo);
        },

        togglePermiso(rolId, codigo) {
            var key = rolId + ':' + codigo;
            var original = this.tienePermisoOriginal(rolId, codigo);
            var actual = this.tienePermisoEditado(rolId, codigo);
            var nuevo = !actual;
            if (nuevo === original) {
                delete this.cambios[key];
            } else {
                this.cambios[key] = nuevo;
            }
            this.$nextTick(function () { lucide.createIcons(); });
        },

        claseCheckbox(rolId, codigo) {
            var cambiado = this.tieneCambioPendiente(rolId, codigo);
            var marcado = this.tienePermisoEditado(rolId, codigo);
            var base = 'group-hover:border-blue-500 ';
            if (marcado) {
                base += 'bg-blue-600 border-blue-600 text-white';
            } else {
                base += 'bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-600';
            }
            if (cambiado) {
                base += ' ring-2 ring-amber-400 ring-offset-2 ring-offset-white dark:ring-offset-gray-800';
            }
            return base;
        },

        tieneCambioPendiente(rolId, codigo) {
            return Object.prototype.hasOwnProperty.call(this.cambios, rolId + ':' + codigo);
        },

        totalCambios() {
            return Object.keys(this.cambios).length;
        },

        rolesModificados() {
            var set = {};
            Object.keys(this.cambios).forEach(function (k) {
                var rolId = parseInt(k.split(':')[0], 10);
                set[rolId] = true;
            });
            return Object.keys(set).map(x => parseInt(x, 10));
        },

        permisosFinalesDeRol(rolId) {
            var final = [];
            var self = this;
            this.permisos.forEach(function (p) {
                if (self.tienePermisoEditado(rolId, p.codigo)) final.push(p.codigo);
            });
            return final;
        },

        autoLockWarning() {
            var misRoles = <?= json_encode($usuario->roles) ?>;
            var tendrasPermiso = false;
            var self = this;
            this.roles.forEach(function (r) {
                if (misRoles.indexOf(r.nombre) !== -1) {
                    if (self.tienePermisoEditado(r.id, 'permisos.asignar_a_rol')) {
                        tendrasPermiso = true;
                    }
                }
            });
            return !tendrasPermiso;
        },

        descartar() {
            if (this.totalCambios() === 0) return;
            if (!confirm('¿Descartar ' + this.totalCambios() + ' cambios?')) return;
            this.cambios = {};
            this.$nextTick(function () { lucide.createIcons(); });
        },

        async guardar() {
            if (this.totalCambios() === 0 || this.guardando) return;
            if (this.autoLockWarning()) {
                if (!confirm('⚠ Vas a perder acceso a esta pantalla porque estás quitando "permisos.asignar_a_rol" a tu propio rol.\n\n¿Continuar de todos modos?')) return;
            }
            this.guardando = true;
            var errores = [];
            var rolesModificados = this.rolesModificados();
            for (var i = 0; i < rolesModificados.length; i++) {
                var rolId = rolesModificados[i];
                var permisosFinales = this.permisosFinalesDeRol(rolId);
                try {
                    var r = await apiPut('/api/roles/' + rolId, { permisos: permisosFinales });
                    if (!r || !r.ok) {
                        var rol = this.rolPorId(rolId);
                        errores.push((rol ? rol.nombre : 'Rol ' + rolId) + ': ' + ((r && r.error && r.error.mensaje) || 'error'));
                    }
                } catch (e) {
                    var rol2 = this.rolPorId(rolId);
                    errores.push((rol2 ? rol2.nombre : 'Rol ' + rolId) + ': sin conexión');
                }
            }
            this.guardando = false;
            if (errores.length > 0) {
                this.mostrarToast('Algunos cambios no se guardaron: ' + errores.join('; '), 'error');
                await this.cargar();
            } else {
                this.mostrarToast('Cambios guardados correctamente.', 'exito');
                await this.cargar();
            }
        },

        abrirCrearRol() {
            window.dispatchEvent(new CustomEvent('abrir-modal-rol-nuevo'));
        },

        abrirEditarRol(rol) {
            window.dispatchEvent(new CustomEvent('abrir-modal-rol-editar', { detail: { rol: rol } }));
        },

        async eliminarRol(rol) {
            if (rol.es_sistema === 1) return;
            if (!confirm('¿Eliminar el rol "' + rol.nombre + '"?\n\nSi tiene usuarios asignados, la operación fallará.')) return;
            try {
                var r = await apiFetch('/api/roles/' + rol.id, { method: 'DELETE' });
                if (r && r.ok) {
                    this.mostrarToast('Rol eliminado.', 'exito');
                    await this.cargar();
                } else {
                    this.mostrarToast((r && r.error && r.error.mensaje) || 'No pudimos eliminar el rol.', 'error');
                }
            } catch (e) {
                this.mostrarToast('No pudimos conectar con el servidor.', 'error');
            }
        },

        alRolCreado(detail) {
            this.mostrarToast('Rol creado.', 'exito');
            this.cargar();
        },

        alRolActualizado(detail) {
            this.mostrarToast('Rol actualizado.', 'exito');
            this.cargar();
        },

        mostrarToast(texto, tipo) {
            this.toast = texto;
            this.toastTipo = tipo || 'exito';
            if (this._toastTimer) clearTimeout(this._toastTimer);
            this._toastTimer = setTimeout(() => { this.toast = ''; }, 4000);
        }
    };
}
</script>
