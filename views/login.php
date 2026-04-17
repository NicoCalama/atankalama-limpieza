<!DOCTYPE html>
<html lang="es" class="">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión — Atankalama Limpieza</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    },
                },
            },
        };
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="/assets/css/custom.css">

    <script>
        (function() {
            var tema = localStorage.getItem('tema');
            if (tema === 'dark' || (!tema && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-gray-100 font-sans min-h-screen flex items-center justify-center p-4">

<div x-data="loginApp()" class="w-full max-w-sm">

    <!-- Logo -->
    <div class="text-center mb-8">
        <div class="w-16 h-16 rounded-2xl bg-blue-600 flex items-center justify-center mx-auto mb-4">
            <i data-lucide="sparkles" class="w-8 h-8 text-white"></i>
        </div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Atankalama</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Sistema de Limpieza</p>
    </div>

    <!-- Formulario login -->
    <div x-show="!requiereCambio" class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <form @submit.prevent="iniciarSesion()">
            <div class="space-y-4">
                <!-- RUT -->
                <div>
                    <label for="rut" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">RUT</label>
                    <input type="text"
                           id="rut"
                           x-model="rut"
                           @input="formatearRut()"
                           placeholder="12.345.678-9"
                           autocomplete="username"
                           class="w-full min-h-[44px] px-3 py-2 text-base bg-gray-50 dark:bg-gray-700
                                  border border-gray-300 dark:border-gray-600 rounded-lg
                                  text-gray-900 dark:text-gray-100
                                  placeholder-gray-400 dark:placeholder-gray-500
                                  focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p x-show="errores.rut" x-text="errores.rut" class="text-xs text-red-600 dark:text-red-400 mt-1"></p>
                </div>

                <!-- Contraseña -->
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Contraseña</label>
                    <div class="relative">
                        <input :type="verPassword ? 'text' : 'password'"
                               id="password"
                               x-model="password"
                               placeholder="Tu contraseña"
                               autocomplete="current-password"
                               class="w-full min-h-[44px] px-3 py-2 pr-12 text-base bg-gray-50 dark:bg-gray-700
                                      border border-gray-300 dark:border-gray-600 rounded-lg
                                      text-gray-900 dark:text-gray-100
                                      placeholder-gray-400 dark:placeholder-gray-500
                                      focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <button type="button"
                                @click="verPassword = !verPassword"
                                class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                            <i :data-lucide="verPassword ? 'eye-off' : 'eye'" class="w-5 h-5"></i>
                        </button>
                    </div>
                </div>

                <!-- Error general -->
                <div x-show="errorGeneral" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3">
                    <p x-text="errorGeneral" class="text-sm text-red-700 dark:text-red-400"></p>
                </div>

                <!-- Botón -->
                <button type="submit"
                        :disabled="!puedeEnviar() || cargando"
                        class="w-full min-h-[44px] bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg
                               transition-colors flex items-center justify-center gap-2
                               disabled:opacity-50 disabled:cursor-not-allowed">
                    <template x-if="cargando">
                        <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                    </template>
                    <span x-text="cargando ? 'Ingresando...' : 'Ingresar'"></span>
                </button>
            </div>
        </form>

        <p class="text-center text-xs text-gray-500 dark:text-gray-400 mt-4">
            ¿Olvidaste tu contraseña? Contacta a tu supervisor.
        </p>
    </div>

    <!-- Formulario cambio de contraseña forzado -->
    <div x-show="requiereCambio" x-cloak class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="text-center mb-4">
            <div class="w-12 h-12 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center mx-auto mb-3">
                <i data-lucide="key-round" class="w-6 h-6 text-amber-600 dark:text-amber-400"></i>
            </div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Cambiar contraseña</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Debes crear una contraseña nueva antes de continuar.</p>
        </div>

        <form @submit.prevent="cambiarContrasena()">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Contraseña temporal</label>
                    <input type="password"
                           x-model="passwordActual"
                           placeholder="La que te dieron"
                           autocomplete="current-password"
                           class="w-full min-h-[44px] px-3 py-2 text-base bg-gray-50 dark:bg-gray-700
                                  border border-gray-300 dark:border-gray-600 rounded-lg
                                  text-gray-900 dark:text-gray-100
                                  focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nueva contraseña</label>
                    <input type="password"
                           x-model="passwordNueva"
                           placeholder="Mínimo 8 caracteres, 1 letra y 1 número"
                           autocomplete="new-password"
                           class="w-full min-h-[44px] px-3 py-2 text-base bg-gray-50 dark:bg-gray-700
                                  border border-gray-300 dark:border-gray-600 rounded-lg
                                  text-gray-900 dark:text-gray-100
                                  focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <p x-show="passwordNueva && !validarPasswordNueva()" class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                        Mínimo 8 caracteres, al menos 1 letra y 1 número
                    </p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Confirmar nueva contraseña</label>
                    <input type="password"
                           x-model="passwordConfirm"
                           placeholder="Repite tu nueva contraseña"
                           autocomplete="new-password"
                           class="w-full min-h-[44px] px-3 py-2 text-base bg-gray-50 dark:bg-gray-700
                                  border border-gray-300 dark:border-gray-600 rounded-lg
                                  text-gray-900 dark:text-gray-100
                                  focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <p x-show="passwordConfirm && passwordNueva !== passwordConfirm" class="text-xs text-red-600 dark:text-red-400 mt-1">
                        Las contraseñas no coinciden
                    </p>
                </div>

                <div x-show="errorGeneral" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3">
                    <p x-text="errorGeneral" class="text-sm text-red-700 dark:text-red-400"></p>
                </div>

                <button type="submit"
                        :disabled="!puedeCambiar() || cargando"
                        class="w-full min-h-[44px] bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg
                               transition-colors flex items-center justify-center gap-2
                               disabled:opacity-50 disabled:cursor-not-allowed">
                    <template x-if="cargando">
                        <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                    </template>
                    <span x-text="cargando ? 'Guardando...' : 'Cambiar contraseña'"></span>
                </button>
            </div>
        </form>
    </div>

    <!-- Toggle tema -->
    <div class="flex justify-center mt-6">
        <button @click="toggleTema()"
                class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
            <i data-lucide="sun" class="w-4 h-4 dark:hidden"></i>
            <i data-lucide="moon" class="w-4 h-4 hidden dark:inline-block"></i>
            <span class="dark:hidden">Modo oscuro</span>
            <span class="hidden dark:inline">Modo claro</span>
        </button>
    </div>
</div>

<script src="/assets/js/app.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() { lucide.createIcons(); });

function loginApp() {
    return {
        rut: '',
        password: '',
        verPassword: false,
        cargando: false,
        errorGeneral: '',
        errores: {},

        // Estado cambio de contraseña
        requiereCambio: false,
        passwordActual: '',
        passwordNueva: '',
        passwordConfirm: '',
        sessionToken: null,
        homeTarget: '/home',

        formatearRut() {
            // Limpiar a solo dígitos y K
            var limpio = this.rut.replace(/[^0-9kK]/g, '').toUpperCase();
            if (limpio.length <= 1) { this.rut = limpio; return; }

            var cuerpo = limpio.slice(0, -1);
            var dv = limpio.slice(-1);
            // Agregar puntos
            var formateado = '';
            for (var i = cuerpo.length - 1, c = 0; i >= 0; i--, c++) {
                if (c > 0 && c % 3 === 0) formateado = '.' + formateado;
                formateado = cuerpo[i] + formateado;
            }
            this.rut = formateado + '-' + dv;
        },

        normalizarRut() {
            return this.rut.replace(/\./g, '').trim();
        },

        puedeEnviar() {
            return this.normalizarRut().length >= 3 && this.password.length >= 1;
        },

        async iniciarSesion() {
            this.cargando = true;
            this.errorGeneral = '';
            this.errores = {};

            try {
                var resp = await fetch('/api/auth/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        rut: this.normalizarRut(),
                        password: this.password
                    })
                });
                var data = await resp.json();

                if (data.ok) {
                    if (data.data.requiere_cambio_pwd) {
                        this.requiereCambio = true;
                        this.passwordActual = this.password;
                        this.homeTarget = data.data.home_target || '/home';
                        this.password = '';
                        this.$nextTick(function() { lucide.createIcons(); });
                    } else {
                        window.location.href = data.data.home_target || '/home';
                    }
                } else {
                    this.errorGeneral = data.error?.mensaje || 'Error al iniciar sesión.';
                }
            } catch (e) {
                this.errorGeneral = 'No pudimos conectar con el servidor. Intenta de nuevo.';
            } finally {
                this.cargando = false;
            }
        },

        validarPasswordNueva() {
            var p = this.passwordNueva;
            return p.length >= 8 && /[a-zA-Z]/.test(p) && /[0-9]/.test(p);
        },

        puedeCambiar() {
            return this.passwordActual.length > 0
                && this.validarPasswordNueva()
                && this.passwordNueva === this.passwordConfirm;
        },

        async cambiarContrasena() {
            this.cargando = true;
            this.errorGeneral = '';

            try {
                var resp = await fetch('/api/auth/cambiar-contrasena', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        password_actual: this.passwordActual,
                        password_nueva: this.passwordNueva,
                        password_nueva_confirmacion: this.passwordConfirm
                    })
                });
                var data = await resp.json();

                if (data.ok) {
                    window.location.href = this.homeTarget;
                } else {
                    this.errorGeneral = data.error?.mensaje || 'Error al cambiar contraseña.';
                }
            } catch (e) {
                this.errorGeneral = 'No pudimos conectar con el servidor. Intenta de nuevo.';
            } finally {
                this.cargando = false;
            }
        },

        toggleTema() {
            document.documentElement.classList.toggle('dark');
            var esDark = document.documentElement.classList.contains('dark');
            localStorage.setItem('tema', esDark ? 'dark' : 'light');
        }
    };
}
</script>
</body>
</html>
