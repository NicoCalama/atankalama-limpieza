<?php
/**
 * Modal reutilizable "Nuevo usuario".
 * Incluir en layout.php (si el usuario tiene permiso usuarios.crear).
 *
 * Para abrirlo desde cualquier parte:
 *   window.dispatchEvent(new CustomEvent('abrir-modal-usuario-nuevo'))
 *
 * Al crearse exitosamente, emite 'usuario-creado' con { usuario, password_temporal }.
 */
?>
<div x-data="modalUsuarioNuevo()"
     @abrir-modal-usuario-nuevo.window="abrir()">

    <div x-show="abierto" x-cloak
         class="fixed inset-0 z-[60] flex items-end md:items-center justify-center p-4 bg-black/50"
         @click.self="cerrar()">
        <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-5 shadow-xl max-h-[90vh] overflow-y-auto">

            <!-- Vista form -->
            <template x-if="!passwordTemporal">
                <div>
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Nuevo usuario</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Se generará una contraseña temporal.</p>
                        </div>
                        <button @click="cerrar()" class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                            <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                        </button>
                    </div>

                    <form @submit.prevent="crear()" class="space-y-3">
                        <!-- RUT -->
                        <div>
                            <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">RUT *</label>
                            <input type="text"
                                   x-model="form.rut"
                                   @input="formatearRut()"
                                   placeholder="12.345.678-9"
                                   maxlength="12"
                                   required
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px]">
                            <template x-if="errores.rut">
                                <p class="text-xs text-red-600 dark:text-red-400 mt-1" x-text="errores.rut"></p>
                            </template>
                        </div>

                        <!-- Nombre -->
                        <div>
                            <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Nombre completo *</label>
                            <input type="text" x-model="form.nombre" maxlength="80" required
                                   placeholder="Ej: Carmen Silva"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px]">
                        </div>

                        <!-- Email -->
                        <div>
                            <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Email (opcional)</label>
                            <input type="email" x-model="form.email" maxlength="120"
                                   placeholder="carmen@ejemplo.cl"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px]">
                        </div>

                        <!-- Hotel default -->
                        <div>
                            <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Hotel por defecto</label>
                            <div class="grid grid-cols-3 gap-2">
                                <template x-for="opt in hoteles" :key="opt.valor">
                                    <button type="button" @click="form.hotel_default = opt.valor"
                                            :class="form.hotel_default === opt.valor ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'"
                                            class="min-h-[44px] px-2 py-1 text-xs font-medium rounded-lg border transition"
                                            x-text="opt.label"></button>
                                </template>
                            </div>
                        </div>

                        <!-- Roles -->
                        <div>
                            <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Roles *</label>
                            <template x-if="rolesDisponibles.length === 0">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Cargando roles...</p>
                            </template>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="r in rolesDisponibles" :key="r.id">
                                    <button type="button" @click="toggleRol(r.nombre)"
                                            :class="form.roles.includes(r.nombre) ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'"
                                            class="min-h-[36px] px-3 py-1.5 text-xs font-medium rounded-full border transition"
                                            x-text="r.nombre"></button>
                                </template>
                            </div>
                        </div>

                        <!-- Error inline -->
                        <template x-if="error">
                            <div class="p-3 bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-sm rounded-lg" x-text="error"></div>
                        </template>

                        <!-- Acciones -->
                        <div class="flex gap-2 pt-2">
                            <button type="button" @click="cerrar()"
                                    class="flex-1 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition">
                                Cancelar
                            </button>
                            <button type="submit" :disabled="enviando || !formValido()"
                                    class="flex-1 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white transition inline-flex items-center justify-center gap-2">
                                <template x-if="enviando">
                                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                </template>
                                <span x-text="enviando ? 'Creando...' : 'Crear usuario'"></span>
                            </button>
                        </div>
                    </form>
                </div>
            </template>

            <!-- Vista password temporal -->
            <template x-if="passwordTemporal">
                <div>
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-semibold text-green-700 dark:text-green-400">Usuario creado</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400" x-text="'Para: ' + (usuarioCreado ? usuarioCreado.nombre : '')"></p>
                        </div>
                        <button @click="cerrar()" class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                            <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                        </button>
                    </div>

                    <div class="bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 rounded-lg p-3 mb-4">
                        <div class="flex gap-2">
                            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0"></i>
                            <p class="text-sm text-amber-800 dark:text-amber-200">
                                Anota esta contraseña temporal. <strong>No volverá a mostrarse.</strong> Entrégala al usuario — tendrá que cambiarla al iniciar sesión.
                            </p>
                        </div>
                    </div>

                    <div class="bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg p-4 mb-4">
                        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">Contraseña temporal</p>
                        <div class="flex items-center gap-2">
                            <code class="flex-1 text-lg font-mono font-bold text-gray-900 dark:text-gray-100 select-all" x-text="passwordTemporal"></code>
                            <button type="button" @click="copiarPassword()"
                                    class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg bg-blue-600 hover:bg-blue-700 text-white transition"
                                    aria-label="Copiar">
                                <i :data-lucide="copiado ? 'check' : 'copy'" class="w-5 h-5"></i>
                            </button>
                        </div>
                        <p x-show="copiado" x-cloak class="text-xs text-green-600 dark:text-green-400 mt-2">¡Copiado!</p>
                    </div>

                    <button type="button" @click="cerrar()"
                            class="w-full min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 text-white transition">
                        Entendido
                    </button>
                </div>
            </template>

        </div>
    </div>
</div>

<script>
function modalUsuarioNuevo() {
    return {
        abierto: false,
        enviando: false,
        error: null,
        errores: {},
        rolesDisponibles: [],
        _rolesCargados: false,
        passwordTemporal: null,
        usuarioCreado: null,
        copiado: false,
        form: {
            rut: '',
            nombre: '',
            email: '',
            hotel_default: '',
            roles: [],
        },
        hoteles: [
            { valor: '', label: 'Ninguno' },
            { valor: '1_sur', label: '1 Sur' },
            { valor: 'inn', label: 'Inn' },
        ],

        async abrir() {
            this.reset();
            this.abierto = true;
            await this.cargarRoles();
            this.$nextTick(function () { lucide.createIcons(); });
        },

        cerrar() {
            this.abierto = false;
        },

        reset() {
            this.form = { rut: '', nombre: '', email: '', hotel_default: '', roles: [] };
            this.error = null;
            this.errores = {};
            this.enviando = false;
            this.passwordTemporal = null;
            this.usuarioCreado = null;
            this.copiado = false;
        },

        async cargarRoles() {
            if (this._rolesCargados) return;
            try {
                var r = await apiFetch('/api/roles');
                if (r && r.ok) {
                    this.rolesDisponibles = r.data.roles || [];
                    this._rolesCargados = true;
                }
            } catch (e) {
                // Si falla, el form muestra "Cargando..."
            }
        },

        toggleRol(nombre) {
            var i = this.form.roles.indexOf(nombre);
            if (i === -1) {
                this.form.roles.push(nombre);
            } else {
                this.form.roles.splice(i, 1);
            }
        },

        formatearRut() {
            var limpio = (this.form.rut || '').replace(/[^0-9kK]/g, '').toUpperCase();
            if (limpio.length <= 1) { this.form.rut = limpio; return; }
            var cuerpo = limpio.slice(0, -1);
            var dv = limpio.slice(-1);
            var formateado = '';
            for (var i = cuerpo.length - 1, c = 0; i >= 0; i--, c++) {
                if (c > 0 && c % 3 === 0) formateado = '.' + formateado;
                formateado = cuerpo[i] + formateado;
            }
            this.form.rut = formateado + '-' + dv;
        },

        rutNormalizado() {
            return (this.form.rut || '').replace(/\./g, '').trim();
        },

        validarRut() {
            var rut = this.rutNormalizado();
            var m = rut.match(/^(\d{7,8})-([0-9K])$/);
            if (!m) return 'Formato de RUT inválido.';
            var cuerpo = m[1];
            var dv = m[2];
            var suma = 0, mul = 2;
            for (var i = cuerpo.length - 1; i >= 0; i--) {
                suma += parseInt(cuerpo.charAt(i), 10) * mul;
                mul = mul === 7 ? 2 : mul + 1;
            }
            var resto = 11 - (suma % 11);
            var dvEsperado = resto === 11 ? '0' : (resto === 10 ? 'K' : String(resto));
            if (dvEsperado !== dv) return 'Dígito verificador inválido.';
            return null;
        },

        formValido() {
            return this.rutNormalizado().length >= 9
                && (this.form.nombre || '').trim().length >= 3
                && this.form.roles.length > 0;
        },

        async crear() {
            if (!this.formValido() || this.enviando) return;
            this.errores = {};
            var errRut = this.validarRut();
            if (errRut) { this.errores.rut = errRut; return; }

            this.enviando = true;
            this.error = null;
            try {
                var payload = {
                    rut: this.rutNormalizado(),
                    nombre: (this.form.nombre || '').trim(),
                    email: (this.form.email || '').trim() || null,
                    hotel_default: this.form.hotel_default || null,
                    roles: this.form.roles.slice(),
                };
                var r = await apiPost('/api/usuarios', payload);
                if (r && r.ok) {
                    this.usuarioCreado = r.data.usuario;
                    this.passwordTemporal = r.data.password_temporal;
                    window.dispatchEvent(new CustomEvent('usuario-creado', { detail: r.data }));
                    this.$nextTick(function () { lucide.createIcons(); });
                } else {
                    this.error = (r && r.error && r.error.mensaje) || 'No pudimos crear el usuario.';
                }
            } catch (e) {
                this.error = 'No pudimos conectar con el servidor.';
            } finally {
                this.enviando = false;
            }
        },

        async copiarPassword() {
            if (!this.passwordTemporal) return;
            try {
                await navigator.clipboard.writeText(this.passwordTemporal);
                this.copiado = true;
                this.$nextTick(function () { lucide.createIcons(); });
                setTimeout(() => {
                    this.copiado = false;
                    this.$nextTick(function () { lucide.createIcons(); });
                }, 2000);
            } catch (e) {
                // Fallback: seleccionar el <code> no es crítico
            }
        }
    };
}
</script>
