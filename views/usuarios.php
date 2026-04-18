<?php
/**
 * Gestión de usuarios — CRUD completo.
 * Spec: docs/usuarios.md
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

require_once __DIR__ . '/componentes/avatar.php';
?>

<div x-data="usuariosApp()"
     x-init="cargar()"
     @usuario-creado.window="alUsuarioCreado($event.detail)"
     @usuario-actualizado.window="alUsuarioActualizado($event.detail)">

    <!-- Header sticky -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between max-w-5xl mx-auto gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <i data-lucide="user-cog" class="w-6 h-6 text-gray-700 dark:text-gray-300 flex-shrink-0"></i>
                <div class="min-w-0">
                    <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Usuarios</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400" x-text="total === null ? 'Cargando...' : (total + (total === 1 ? ' usuario' : ' usuarios'))"></p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <?php if ($usuario->tienePermiso('usuarios.crear')): ?>
                <button type="button"
                        @click="abrirCrear()"
                        class="min-h-[44px] inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium transition">
                    <i data-lucide="user-plus" class="w-4 h-4"></i>
                    <span class="hidden sm:inline">Nuevo usuario</span>
                    <span class="sm:hidden">Nuevo</span>
                </button>
                <?php endif; ?>
                <button type="button" @click="cargar()" :disabled="cargando"
                        class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50"
                        aria-label="Refrescar">
                    <i data-lucide="refresh-cw" class="w-5 h-5 text-gray-600 dark:text-gray-400" :class="cargando ? 'animate-spin' : ''"></i>
                </button>
            </div>
        </div>
    </header>

    <!-- Banner sin conexión -->
    <div x-show="sinConexion" x-cloak
         class="bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200 px-4 py-2 text-sm text-center">
        Sin conexión a internet.
    </div>

    <main class="pb-24 md:pb-8 max-w-5xl mx-auto px-4 pt-4">

        <!-- Filtros -->
        <div class="space-y-3 mb-4">
            <!-- Búsqueda -->
            <div class="relative">
                <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                <input type="text"
                       x-model="busqueda"
                       @input.debounce.300ms="cargar()"
                       placeholder="Buscar por nombre o RUT..."
                       class="w-full pl-10 pr-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px]">
            </div>

            <!-- Rol -->
            <div class="flex items-center gap-2 overflow-x-auto pb-1">
                <span class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 flex-shrink-0">Rol:</span>
                <template x-for="opt in filtrosRol" :key="opt.valor">
                    <button type="button"
                            @click="rol = opt.valor; cargar()"
                            :class="rol === opt.valor ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'"
                            class="flex-shrink-0 min-h-[36px] px-3 py-1.5 text-xs font-medium rounded-full border transition"
                            x-text="opt.label"></button>
                </template>
            </div>

            <!-- Activo -->
            <div class="flex items-center gap-2">
                <span class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Estado:</span>
                <template x-for="opt in filtrosActivo" :key="opt.valor">
                    <button type="button"
                            @click="activo = opt.valor; cargar()"
                            :class="activo === opt.valor ? 'bg-gray-800 dark:bg-gray-200 text-white dark:text-gray-900 border-gray-800 dark:border-gray-200' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'"
                            class="min-h-[36px] px-3 py-1.5 text-xs font-medium rounded-full border transition"
                            x-text="opt.label"></button>
                </template>
            </div>
        </div>

        <!-- Estado de carga inicial -->
        <template x-if="cargando && usuarios.length === 0">
            <div class="min-h-[40vh] flex items-center justify-center">
                <div class="flex flex-col items-center gap-3">
                    <svg class="animate-spin h-8 w-8 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <p class="text-gray-600 dark:text-gray-400">Cargando...</p>
                </div>
            </div>
        </template>

        <!-- Estado vacío -->
        <template x-if="!cargando && usuarios.length === 0">
            <div class="text-center py-12">
                <i data-lucide="users" class="w-12 h-12 mx-auto mb-3 text-gray-400 dark:text-gray-500"></i>
                <p class="text-gray-600 dark:text-gray-400">No hay usuarios que coincidan con los filtros.</p>
            </div>
        </template>

        <!-- Lista -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3" x-show="usuarios.length > 0">
            <template x-for="u in usuarios" :key="u.id">
                <button type="button"
                        @click="abrirDetalle(u)"
                        class="text-left bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 hover:shadow-md hover:border-blue-300 dark:hover:border-blue-600 transition"
                        :class="!u.activo ? 'opacity-60' : ''">
                    <div class="flex items-start gap-3">
                        <div class="w-11 h-11 rounded-full flex items-center justify-center text-white text-sm font-bold flex-shrink-0"
                             :class="colorAvatar(u.rut)"
                             x-text="inicial(u.nombre)"></div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 mb-0.5">
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate" x-text="u.nombre"></p>
                                <template x-if="!u.activo">
                                    <span class="flex-shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300">Inactivo</span>
                                </template>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400" x-text="u.rut"></p>
                            <div class="flex flex-wrap gap-1 mt-2">
                                <template x-for="r in (u.roles || [])" :key="r">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300" x-text="r"></span>
                                </template>
                            </div>
                        </div>
                    </div>
                </button>
            </template>
        </div>

    </main>

    <!-- Toast -->
    <div x-show="toast" x-cloak x-transition
         class="fixed bottom-24 md:bottom-8 left-1/2 -translate-x-1/2 z-50 bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 px-4 py-2 rounded-full text-sm shadow-lg"
         x-text="toast"></div>
</div>

<script>
function usuariosApp() {
    return {
        usuarios: [],
        total: null,
        cargando: false,
        sinConexion: !navigator.onLine,
        busqueda: localStorage.getItem('usuarios_busqueda') || '',
        rol: localStorage.getItem('usuarios_rol') || '',
        activo: localStorage.getItem('usuarios_activo') || '',
        toast: '',
        _toastTimer: null,

        filtrosRol: [
            { valor: '', label: 'Todos' },
            { valor: 'Trabajador', label: 'Trabajadores' },
            { valor: 'Supervisora', label: 'Supervisoras' },
            { valor: 'Recepción', label: 'Recepción' },
            { valor: 'Admin', label: 'Admins' },
        ],
        filtrosActivo: [
            { valor: '', label: 'Todos' },
            { valor: '1', label: 'Activos' },
            { valor: '0', label: 'Inactivos' },
        ],

        async cargar() {
            this.cargando = true;
            localStorage.setItem('usuarios_busqueda', this.busqueda || '');
            localStorage.setItem('usuarios_rol', this.rol || '');
            localStorage.setItem('usuarios_activo', this.activo || '');

            var qs = new URLSearchParams();
            if (this.busqueda.trim() !== '') qs.set('busqueda', this.busqueda.trim());
            if (this.rol !== '') qs.set('rol', this.rol);
            if (this.activo !== '') qs.set('activo', this.activo);

            try {
                var r = await apiFetch('/api/usuarios?' + qs.toString());
                if (r && r.ok) {
                    this.usuarios = r.data.usuarios || [];
                    this.total = r.data.total || 0;
                } else if (r) {
                    this.mostrarToast((r.error && r.error.mensaje) || 'No pudimos cargar los usuarios.');
                }
            } catch (e) {
                this.mostrarToast('No pudimos conectar con el servidor.');
            } finally {
                this.cargando = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },

        abrirCrear() {
            window.dispatchEvent(new CustomEvent('abrir-modal-usuario-nuevo'));
        },

        abrirDetalle(u) {
            window.dispatchEvent(new CustomEvent('abrir-modal-usuario-detalle', { detail: { usuario: u } }));
        },

        alUsuarioCreado(detail) {
            this.mostrarToast('Usuario creado correctamente.');
            this.cargar();
        },

        alUsuarioActualizado(detail) {
            this.mostrarToast('Cambios guardados.');
            this.cargar();
        },

        mostrarToast(mensaje) {
            this.toast = mensaje;
            if (this._toastTimer) clearTimeout(this._toastTimer);
            this._toastTimer = setTimeout(() => { this.toast = ''; }, 3000);
        },

        inicial(nombre) {
            return ((nombre || '').trim().charAt(0) || '?').toUpperCase();
        },

        colorAvatar(rut) {
            var colores = ['bg-blue-500', 'bg-emerald-500', 'bg-violet-500', 'bg-amber-500', 'bg-rose-500', 'bg-cyan-500', 'bg-indigo-500', 'bg-teal-500'];
            var s = (rut || '').toString();
            var h = 0;
            for (var i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) | 0; }
            return colores[Math.abs(h) % colores.length];
        }
    };
}
</script>
