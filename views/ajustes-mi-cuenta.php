<?php
/**
 * Mi cuenta — datos personales, tema y seguridad.
 * Spec: docs/ajustes.md §6
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

$puedeEditarSe = $usuario->tienePermiso('usuarios.editar');
?>

<div x-data="miCuentaApp()" x-init="inicializar()">

    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center gap-3 max-w-3xl mx-auto">
            <a href="/ajustes" class="min-h-[44px] min-w-[44px] flex items-center justify-center -ml-2" aria-label="Volver">
                <i data-lucide="arrow-left" class="w-5 h-5 text-gray-700 dark:text-gray-300"></i>
            </a>
            <div class="min-w-0">
                <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Mi cuenta</h1>
                <p class="text-xs text-gray-500 dark:text-gray-400 truncate"><?= htmlspecialchars($usuario->nombre) ?></p>
            </div>
        </div>
    </header>

    <main class="max-w-3xl mx-auto p-4 pb-24 md:pb-6 space-y-6">

        <!-- Datos personales -->
        <section class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 md:p-6">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center gap-2">
                <i data-lucide="user" class="w-4 h-4 text-gray-500 dark:text-gray-400"></i>
                Datos personales
            </h2>

            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">RUT</label>
                    <input type="text" value="<?= htmlspecialchars($usuario->rut) ?>" readonly
                           class="w-full min-h-[44px] px-3 py-2 rounded-lg bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 text-sm cursor-not-allowed">
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Nombre</label>
                    <input type="text" x-model="form.nombre" <?= $puedeEditarSe ? '' : 'readonly' ?>
                           class="w-full min-h-[44px] px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 <?= $puedeEditarSe ? '' : 'cursor-not-allowed opacity-70' ?>">
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
                    <input type="email" x-model="form.email" <?= $puedeEditarSe ? '' : 'readonly' ?>
                           placeholder="opcional"
                           class="w-full min-h-[44px] px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 <?= $puedeEditarSe ? '' : 'cursor-not-allowed opacity-70' ?>">
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Hotel por defecto</label>
                    <select x-model="form.hotel_default" <?= $puedeEditarSe ? '' : 'disabled' ?>
                            class="w-full min-h-[44px] px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 <?= $puedeEditarSe ? '' : 'cursor-not-allowed opacity-70' ?>">
                        <option value="">Ambos</option>
                        <option value="1sur">1 Sur</option>
                        <option value="inn">Inn</option>
                    </select>
                </div>

                <?php if (!empty($usuario->roles)): ?>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">Roles</label>
                    <div class="flex flex-wrap gap-1.5">
                        <?php foreach ($usuario->roles as $r): ?>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
                                <?= htmlspecialchars($r) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($puedeEditarSe): ?>
                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button type="button" @click="descartarCambios()" x-show="datosCambiaron()" x-cloak
                                class="min-h-[44px] px-4 py-2 rounded-lg bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 text-sm font-medium transition">
                            Descartar
                        </button>
                        <button type="button" @click="guardarDatos()" :disabled="!datosCambiaron() || guardando"
                                class="min-h-[44px] inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium transition">
                            <template x-if="guardando">
                                <svg class="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none">
                                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle>
                                    <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" class="opacity-75"></path>
                                </svg>
                            </template>
                            <span x-text="guardando ? 'Guardando...' : 'Guardar cambios'"></span>
                        </button>
                    </div>
                <?php else: ?>
                    <p class="text-xs text-gray-500 dark:text-gray-400 italic">No tienes permisos para editar tus datos. Pide a un administrador.</p>
                <?php endif; ?>
            </div>
        </section>

        <!-- Tema -->
        <section class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 md:p-6">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center gap-2">
                <i data-lucide="palette" class="w-4 h-4 text-gray-500 dark:text-gray-400"></i>
                Tema
            </h2>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                <template x-for="opt in temas" :key="opt.valor">
                    <button type="button" @click="cambiarTema(opt.valor)"
                            :class="temaActual === opt.valor
                                ? 'border-blue-500 ring-2 ring-blue-500 bg-blue-50 dark:bg-blue-900/30'
                                : 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-700'"
                            class="min-h-[60px] flex items-center gap-3 px-4 py-3 rounded-lg border transition text-left">
                        <i :data-lucide="opt.icono" class="w-5 h-5 text-gray-700 dark:text-gray-300 flex-shrink-0"></i>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100" x-text="opt.label"></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400" x-text="opt.descripcion"></p>
                        </div>
                    </button>
                </template>
            </div>
        </section>

        <!-- Seguridad -->
        <section class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 md:p-6">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center gap-2">
                <i data-lucide="lock" class="w-4 h-4 text-gray-500 dark:text-gray-400"></i>
                Seguridad
            </h2>

            <button type="button" @click="window.dispatchEvent(new CustomEvent('abrir-modal-cambiar-password'))"
                    class="w-full sm:w-auto min-h-[44px] inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 text-sm font-medium transition">
                <i data-lucide="key" class="w-4 h-4"></i>
                Cambiar contraseña
            </button>
        </section>

        <!-- Sesión -->
        <section class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 md:p-6">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center gap-2">
                <i data-lucide="log-out" class="w-4 h-4 text-gray-500 dark:text-gray-400"></i>
                Sesión
            </h2>

            <button type="button" @click="cerrarSesion()" :disabled="cerrandoSesion"
                    class="w-full sm:w-auto min-h-[44px] inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-rose-50 dark:bg-rose-900/20 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800 hover:bg-rose-100 dark:hover:bg-rose-900/40 disabled:opacity-50 text-sm font-medium transition">
                <i data-lucide="log-out" class="w-4 h-4"></i>
                <span x-text="cerrandoSesion ? 'Cerrando...' : 'Cerrar sesión'"></span>
            </button>
        </section>

        <!-- Toast -->
        <div x-show="toast.visible" x-cloak x-transition
             :class="toast.tipo === 'error' ? 'bg-rose-600' : 'bg-emerald-600'"
             class="fixed bottom-20 left-1/2 -translate-x-1/2 text-white px-4 py-2 rounded-lg shadow-lg text-sm z-50"
             x-text="toast.mensaje"></div>
    </main>
</div>

<script>
function miCuentaApp() {
    return {
        usuarioId: <?= (int) $usuario->id ?>,
        puedeEditar: <?= $puedeEditarSe ? 'true' : 'false' ?>,
        form: {
            nombre: <?= json_encode($usuario->nombre) ?>,
            email: <?= json_encode($usuario->email ?? '') ?>,
            hotel_default: <?= json_encode($usuario->hotelDefault ?? '') ?>,
        },
        original: null,
        guardando: false,
        cerrandoSesion: false,
        temaActual: 'auto',
        temas: [
            { valor: 'auto', label: 'Automático', descripcion: 'Según tu sistema', icono: 'monitor' },
            { valor: 'light', label: 'Claro', descripcion: 'Siempre claro', icono: 'sun' },
            { valor: 'dark', label: 'Oscuro', descripcion: 'Siempre oscuro', icono: 'moon' },
        ],
        toast: { visible: false, mensaje: '', tipo: 'ok' },

        inicializar() {
            this.original = JSON.stringify(this.form);
            const guardado = localStorage.getItem('tema');
            this.temaActual = (guardado === 'dark' || guardado === 'light') ? guardado : 'auto';
        },

        datosCambiaron() {
            return this.puedeEditar && this.original !== null && JSON.stringify(this.form) !== this.original;
        },

        descartarCambios() {
            if (this.original !== null) {
                const orig = JSON.parse(this.original);
                this.form.nombre = orig.nombre;
                this.form.email = orig.email;
                this.form.hotel_default = orig.hotel_default;
            }
        },

        async guardarDatos() {
            if (!this.puedeEditar || !this.datosCambiaron() || this.guardando) return;
            this.guardando = true;
            try {
                const payload = {
                    nombre: this.form.nombre.trim(),
                    email: this.form.email.trim() === '' ? null : this.form.email.trim(),
                    hotel_default: this.form.hotel_default === '' ? null : this.form.hotel_default,
                };
                const res = await apiPut('/api/usuarios/' + this.usuarioId, payload);
                if (!res.ok) {
                    this.mostrarToast(res.error?.mensaje || 'No se pudo guardar.', 'error');
                    return;
                }
                this.original = JSON.stringify(this.form);
                this.mostrarToast('Datos guardados.');
                setTimeout(() => window.location.reload(), 800);
            } catch (e) {
                this.mostrarToast('Error de red.', 'error');
            } finally {
                this.guardando = false;
            }
        },

        cambiarTema(valor) {
            this.temaActual = valor;
            if (valor === 'auto') {
                localStorage.removeItem('tema');
                const prefiereOscuro = window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.classList.toggle('dark', prefiereOscuro);
            } else {
                localStorage.setItem('tema', valor);
                document.documentElement.classList.toggle('dark', valor === 'dark');
            }
            if (window.Alpine && Alpine.store('tema')) {
                Alpine.store('tema').actual = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
            }
        },

        async cerrarSesion() {
            if (this.cerrandoSesion) return;
            if (!confirm('¿Cerrar sesión?')) return;
            this.cerrandoSesion = true;
            try {
                await apiPost('/api/auth/logout', {});
            } catch (e) {
                // ignora
            }
            window.location.href = '/login';
        },

        mostrarToast(mensaje, tipo = 'ok') {
            this.toast = { visible: true, mensaje, tipo };
            setTimeout(() => { this.toast.visible = false; }, 2500);
        },
    };
}
</script>
