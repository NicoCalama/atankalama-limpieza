<?php
/**
 * Modal reutilizable "Detalle de usuario".
 * Incluir en layout.php (si el usuario tiene permiso usuarios.ver).
 *
 * Para abrirlo desde cualquier parte:
 *   window.dispatchEvent(new CustomEvent('abrir-modal-usuario-detalle', { detail: { usuario: {...} } }))
 *
 * Al actualizar/activar/desactivar/cambiar roles emite 'usuario-actualizado'.
 *
 * Variables PHP requeridas: $usuario (para permisos del usuario actual)
 */

$puedeEditar = $usuario->tienePermiso('usuarios.editar');
$puedeActivar = $usuario->tienePermiso('usuarios.activar_desactivar');
$puedeResetPwd = $usuario->tienePermiso('usuarios.resetear_password');
$puedeAsignarRoles = $usuario->tieneAlgunPermiso(['usuarios.editar', 'permisos.asignar_a_rol']);
$usuarioActualId = $usuario->id;
?>
<div x-data="modalUsuarioDetalle()"
     @abrir-modal-usuario-detalle.window="abrir($event.detail || {})">

    <div x-show="abierto" x-cloak
         class="fixed inset-0 z-[60] flex items-end md:items-center justify-center p-4 bg-black/50"
         @click.self="cerrar()">
        <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-5 shadow-xl max-h-[90vh] overflow-y-auto">

            <template x-if="usuario">
                <div>
                    <!-- Header -->
                    <div class="flex items-start justify-between mb-4">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-12 h-12 rounded-full flex items-center justify-center text-white text-base font-bold flex-shrink-0"
                                 :class="colorAvatar(usuario.rut)"
                                 x-text="inicial(usuario.nombre)"></div>
                            <div class="min-w-0">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 truncate" x-text="usuario.nombre"></h3>
                                <p class="text-xs text-gray-500 dark:text-gray-400" x-text="usuario.rut"></p>
                            </div>
                        </div>
                        <button @click="cerrar()" class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                            <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                        </button>
                    </div>

                    <!-- Badge inactivo -->
                    <template x-if="!usuario.activo">
                        <div class="mb-4 p-3 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm flex items-center gap-2">
                            <i data-lucide="user-x" class="w-4 h-4"></i>
                            Usuario inactivo — no puede iniciar sesión.
                        </div>
                    </template>

                    <!-- Toast inline éxito/error -->
                    <template x-if="mensaje">
                        <div class="mb-4 p-3 rounded-lg text-sm"
                             :class="mensajeTipo === 'error' ? 'bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300' : 'bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-300'"
                             x-text="mensaje"></div>
                    </template>

                    <!-- Password reseteada -->
                    <template x-if="passwordReseteada">
                        <div class="mb-4">
                            <div class="bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 rounded-lg p-3 mb-2">
                                <div class="flex gap-2">
                                    <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0"></i>
                                    <p class="text-sm text-amber-800 dark:text-amber-200">
                                        Anota esta contraseña temporal. <strong>No volverá a mostrarse.</strong>
                                    </p>
                                </div>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg p-3">
                                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Nueva contraseña temporal</p>
                                <div class="flex items-center gap-2">
                                    <code class="flex-1 text-base font-mono font-bold text-gray-900 dark:text-gray-100 select-all" x-text="passwordReseteada"></code>
                                    <button type="button" @click="copiarPassword()"
                                            class="min-h-[40px] min-w-[40px] flex items-center justify-center rounded-lg bg-blue-600 hover:bg-blue-700 text-white transition"
                                            aria-label="Copiar">
                                        <i :data-lucide="copiado ? 'check' : 'copy'" class="w-4 h-4"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- Datos editables -->
                    <div class="space-y-3 mb-4">
                        <div>
                            <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Nombre</label>
                            <input type="text" x-model="form.nombre" maxlength="80"
                                   :disabled="!puedeEditar"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px] disabled:opacity-60 disabled:cursor-not-allowed">
                        </div>

                        <div>
                            <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Email</label>
                            <input type="email" x-model="form.email" maxlength="120"
                                   :disabled="!puedeEditar"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px] disabled:opacity-60 disabled:cursor-not-allowed">
                        </div>

                        <div>
                            <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Hotel por defecto</label>
                            <div class="grid grid-cols-3 gap-2">
                                <template x-for="opt in hoteles" :key="opt.valor">
                                    <button type="button" @click="puedeEditar && (form.hotel_default = opt.valor)"
                                            :disabled="!puedeEditar"
                                            :class="form.hotel_default === opt.valor ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'"
                                            class="min-h-[44px] px-2 py-1 text-xs font-medium rounded-lg border transition disabled:opacity-60 disabled:cursor-not-allowed"
                                            x-text="opt.label"></button>
                                </template>
                            </div>
                        </div>

                        <template x-if="puedeEditar && datosCambiaron()">
                            <button type="button" @click="guardarDatos()"
                                    :disabled="guardandoDatos"
                                    class="w-full min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white transition">
                                <span x-text="guardandoDatos ? 'Guardando...' : 'Guardar cambios'"></span>
                            </button>
                        </template>
                    </div>

                    <!-- Roles -->
                    <div class="mb-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">Roles</label>
                        <div class="flex flex-wrap gap-2 mb-2">
                            <template x-for="nombreRol in (usuario.roles || [])" :key="nombreRol">
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300">
                                    <span x-text="nombreRol"></span>
                                    <template x-if="puedeAsignarRoles">
                                        <button type="button" @click="quitarRol(nombreRol)"
                                                class="hover:bg-blue-100 dark:hover:bg-blue-900/50 rounded-full p-0.5"
                                                :disabled="procesandoRol"
                                                aria-label="Quitar rol">
                                            <i data-lucide="x" class="w-3 h-3"></i>
                                        </button>
                                    </template>
                                </span>
                            </template>
                            <template x-if="(usuario.roles || []).length === 0">
                                <p class="text-xs text-gray-500 dark:text-gray-400 italic">Sin roles asignados.</p>
                            </template>
                        </div>
                        <template x-if="puedeAsignarRoles && rolesParaAgregar().length > 0">
                            <div class="flex items-center gap-2">
                                <select x-model="rolAAgregar"
                                        class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[40px]">
                                    <option value="">Agregar rol...</option>
                                    <template x-for="r in rolesParaAgregar()" :key="r.id">
                                        <option :value="r.id" x-text="r.nombre"></option>
                                    </template>
                                </select>
                                <button type="button" @click="agregarRol()"
                                        :disabled="!rolAAgregar || procesandoRol"
                                        class="min-h-[40px] px-3 py-1.5 text-xs font-medium rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white transition">
                                    Agregar
                                </button>
                            </div>
                        </template>
                    </div>

                    <!-- Acciones administrativas -->
                    <div class="space-y-2 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <template x-if="puedeResetPwd">
                            <button type="button" @click="confirmarResetPwd()"
                                    :disabled="procesandoPwd"
                                    class="w-full min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-100 transition inline-flex items-center justify-center gap-2">
                                <i data-lucide="key-round" class="w-4 h-4"></i>
                                <span x-text="procesandoPwd ? 'Generando...' : 'Resetear contraseña'"></span>
                            </button>
                        </template>

                        <template x-if="puedeActivar && usuario.id !== usuarioActualId">
                            <button type="button" @click="toggleActivo()"
                                    :disabled="procesandoActivo"
                                    :class="usuario.activo ? 'border-red-300 dark:border-red-800 bg-white dark:bg-gray-800 hover:bg-red-50 dark:hover:bg-red-900/20 text-red-700 dark:text-red-400' : 'border-green-300 dark:border-green-800 bg-white dark:bg-gray-800 hover:bg-green-50 dark:hover:bg-green-900/20 text-green-700 dark:text-green-400'"
                                    class="w-full min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg border disabled:opacity-50 transition inline-flex items-center justify-center gap-2">
                                <i :data-lucide="usuario.activo ? 'user-x' : 'user-check'" class="w-4 h-4"></i>
                                <span x-text="procesandoActivo ? 'Procesando...' : (usuario.activo ? 'Desactivar usuario' : 'Activar usuario')"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </template>

        </div>
    </div>
</div>

<script>
function modalUsuarioDetalle() {
    return {
        abierto: false,
        usuario: null,
        usuarioOriginal: null,
        puedeEditar: <?= $puedeEditar ? 'true' : 'false' ?>,
        puedeActivar: <?= $puedeActivar ? 'true' : 'false' ?>,
        puedeResetPwd: <?= $puedeResetPwd ? 'true' : 'false' ?>,
        puedeAsignarRoles: <?= $puedeAsignarRoles ? 'true' : 'false' ?>,
        usuarioActualId: <?= (int) $usuarioActualId ?>,
        rolesDisponibles: [],
        _rolesCargados: false,
        rolAAgregar: '',
        form: { nombre: '', email: '', hotel_default: '' },
        mensaje: '',
        mensajeTipo: 'exito',
        _mensajeTimer: null,
        guardandoDatos: false,
        procesandoRol: false,
        procesandoActivo: false,
        procesandoPwd: false,
        passwordReseteada: null,
        copiado: false,
        hoteles: [
            { valor: '', label: 'Ninguno' },
            { valor: '1_sur', label: '1 Sur' },
            { valor: 'inn', label: 'Inn' },
        ],

        async abrir(detail) {
            if (!detail || !detail.usuario) return;
            this.usuario = JSON.parse(JSON.stringify(detail.usuario));
            this.usuarioOriginal = JSON.parse(JSON.stringify(detail.usuario));
            this.form = {
                nombre: this.usuario.nombre || '',
                email: this.usuario.email || '',
                hotel_default: this.usuario.hotel_default || '',
            };
            this.mensaje = '';
            this.passwordReseteada = null;
            this.copiado = false;
            this.rolAAgregar = '';
            this.abierto = true;
            if (this.puedeAsignarRoles) await this.cargarRoles();
            this.$nextTick(function () { lucide.createIcons(); });
        },

        cerrar() {
            this.abierto = false;
        },

        async cargarRoles() {
            if (this._rolesCargados) return;
            try {
                var r = await apiFetch('/api/roles');
                if (r && r.ok) {
                    this.rolesDisponibles = r.data.roles || [];
                    this._rolesCargados = true;
                }
            } catch (e) { /* no bloqueante */ }
        },

        rolesParaAgregar() {
            var actuales = this.usuario && this.usuario.roles ? this.usuario.roles : [];
            return this.rolesDisponibles.filter(function (r) {
                return actuales.indexOf(r.nombre) === -1;
            });
        },

        datosCambiaron() {
            if (!this.usuarioOriginal) return false;
            return (this.form.nombre || '') !== (this.usuarioOriginal.nombre || '')
                || (this.form.email || '') !== (this.usuarioOriginal.email || '')
                || (this.form.hotel_default || '') !== (this.usuarioOriginal.hotel_default || '');
        },

        async guardarDatos() {
            if (this.guardandoDatos) return;
            this.guardandoDatos = true;
            this.mensaje = '';
            try {
                var payload = {
                    nombre: (this.form.nombre || '').trim(),
                    email: (this.form.email || '').trim() || null,
                    hotel_default: this.form.hotel_default || null,
                };
                var r = await apiPut('/api/usuarios/' + this.usuario.id, payload);
                if (r && r.ok) {
                    this.usuario = r.data.usuario;
                    this.usuarioOriginal = JSON.parse(JSON.stringify(r.data.usuario));
                    this.mostrarMensaje('Datos actualizados.', 'exito');
                    window.dispatchEvent(new CustomEvent('usuario-actualizado', { detail: r.data }));
                } else {
                    this.mostrarMensaje((r && r.error && r.error.mensaje) || 'No pudimos guardar.', 'error');
                }
            } catch (e) {
                this.mostrarMensaje('No pudimos conectar con el servidor.', 'error');
            } finally {
                this.guardandoDatos = false;
            }
        },

        async agregarRol() {
            if (!this.rolAAgregar || this.procesandoRol) return;
            this.procesandoRol = true;
            this.mensaje = '';
            try {
                var r = await apiPost('/api/usuarios/' + this.usuario.id + '/roles', { rol_id: parseInt(this.rolAAgregar, 10) });
                if (r && r.ok) {
                    var rolNombre = (this.rolesDisponibles.find(x => x.id === parseInt(this.rolAAgregar, 10)) || {}).nombre;
                    if (rolNombre && this.usuario.roles.indexOf(rolNombre) === -1) {
                        this.usuario.roles.push(rolNombre);
                    }
                    this.rolAAgregar = '';
                    this.mostrarMensaje('Rol agregado.', 'exito');
                    window.dispatchEvent(new CustomEvent('usuario-actualizado', { detail: { usuario: this.usuario } }));
                    this.$nextTick(function () { lucide.createIcons(); });
                } else {
                    this.mostrarMensaje((r && r.error && r.error.mensaje) || 'No pudimos agregar el rol.', 'error');
                }
            } catch (e) {
                this.mostrarMensaje('No pudimos conectar con el servidor.', 'error');
            } finally {
                this.procesandoRol = false;
            }
        },

        async quitarRol(nombreRol) {
            if (this.procesandoRol) return;
            var rol = this.rolesDisponibles.find(r => r.nombre === nombreRol);
            if (!rol) {
                this.mostrarMensaje('No encontramos el rol.', 'error');
                return;
            }
            this.procesandoRol = true;
            this.mensaje = '';
            try {
                var r = await apiFetch('/api/usuarios/' + this.usuario.id + '/roles/' + rol.id, { method: 'DELETE' });
                if (r && r.ok) {
                    this.usuario.roles = this.usuario.roles.filter(x => x !== nombreRol);
                    this.mostrarMensaje('Rol quitado.', 'exito');
                    window.dispatchEvent(new CustomEvent('usuario-actualizado', { detail: { usuario: this.usuario } }));
                    this.$nextTick(function () { lucide.createIcons(); });
                } else {
                    this.mostrarMensaje((r && r.error && r.error.mensaje) || 'No pudimos quitar el rol.', 'error');
                }
            } catch (e) {
                this.mostrarMensaje('No pudimos conectar con el servidor.', 'error');
            } finally {
                this.procesandoRol = false;
            }
        },

        async toggleActivo() {
            if (this.procesandoActivo) return;
            var nuevoEstado = !this.usuario.activo;
            var accion = nuevoEstado ? 'activar' : 'desactivar';
            if (!nuevoEstado && !confirm('¿Desactivar a ' + this.usuario.nombre + '?\n\nNo podrá iniciar sesión hasta que lo reactives.')) return;
            this.procesandoActivo = true;
            this.mensaje = '';
            try {
                var r = await apiPost('/api/usuarios/' + this.usuario.id + '/' + accion, {});
                if (r && r.ok) {
                    this.usuario = r.data.usuario;
                    this.mostrarMensaje(nuevoEstado ? 'Usuario activado.' : 'Usuario desactivado.', 'exito');
                    window.dispatchEvent(new CustomEvent('usuario-actualizado', { detail: r.data }));
                    this.$nextTick(function () { lucide.createIcons(); });
                } else {
                    this.mostrarMensaje((r && r.error && r.error.mensaje) || 'No pudimos procesar.', 'error');
                }
            } catch (e) {
                this.mostrarMensaje('No pudimos conectar con el servidor.', 'error');
            } finally {
                this.procesandoActivo = false;
            }
        },

        async confirmarResetPwd() {
            if (this.procesandoPwd) return;
            if (!confirm('¿Resetear la contraseña de ' + this.usuario.nombre + '?\n\nSe generará una nueva contraseña temporal.')) return;
            this.procesandoPwd = true;
            this.mensaje = '';
            this.passwordReseteada = null;
            try {
                var r = await apiPost('/api/auth/reset-temporal', { usuario_id: this.usuario.id });
                if (r && r.ok) {
                    this.passwordReseteada = r.data.password_temporal;
                    window.dispatchEvent(new CustomEvent('usuario-actualizado', { detail: { usuario: this.usuario } }));
                    this.$nextTick(function () { lucide.createIcons(); });
                } else {
                    this.mostrarMensaje((r && r.error && r.error.mensaje) || 'No pudimos resetear la contraseña.', 'error');
                }
            } catch (e) {
                this.mostrarMensaje('No pudimos conectar con el servidor.', 'error');
            } finally {
                this.procesandoPwd = false;
            }
        },

        async copiarPassword() {
            if (!this.passwordReseteada) return;
            try {
                await navigator.clipboard.writeText(this.passwordReseteada);
                this.copiado = true;
                this.$nextTick(function () { lucide.createIcons(); });
                setTimeout(() => {
                    this.copiado = false;
                    this.$nextTick(function () { lucide.createIcons(); });
                }, 2000);
            } catch (e) { /* fallback no crítico */ }
        },

        mostrarMensaje(texto, tipo) {
            this.mensaje = texto;
            this.mensajeTipo = tipo || 'exito';
            if (this._mensajeTimer) clearTimeout(this._mensajeTimer);
            this._mensajeTimer = setTimeout(() => { this.mensaje = ''; }, 4000);
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
