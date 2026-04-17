/**
 * app.js — Alpine.js stores y helpers globales para Atankalama Limpieza.
 *
 * Cargado después de Alpine CDN. Define:
 * - Store 'tema': modo día/noche persistido en localStorage
 * - Store 'auth': estado de sesión del usuario
 * - Helper 'apiFetch': wrapper para fetch con JSON y manejo de errores
 * - Helper 'copilotInput': Alpine component para el input del copilot FAB
 */

// --- Store: Tema (día/noche) ---
document.addEventListener('alpine:init', function () {

    Alpine.store('tema', {
        oscuro: document.documentElement.classList.contains('dark'),

        toggle() {
            this.oscuro = !this.oscuro;
            document.documentElement.classList.toggle('dark', this.oscuro);
            localStorage.setItem('tema', this.oscuro ? 'dark' : 'light');
        },

        init() {
            this.oscuro = document.documentElement.classList.contains('dark');
        }
    });

    // --- Store: Auth (usuario autenticado) ---
    Alpine.store('auth', {
        usuario: null,
        permisos: [],
        cargado: false,

        tienePermiso(codigo) {
            return this.permisos.indexOf(codigo) !== -1;
        },

        async cargar() {
            try {
                var data = await apiFetch('/api/auth/yo');
                if (data && data.ok) {
                    this.usuario = data.data.usuario;
                    this.permisos = data.data.permisos || [];
                }
            } catch (e) {
                // No autenticado — no es un error
            }
            this.cargado = true;
        },

        async cerrarSesion() {
            try {
                await apiFetch('/api/auth/logout', { method: 'POST' });
            } catch (e) {
                // Ignorar errores al cerrar sesión
            }
            this.usuario = null;
            this.permisos = [];
            window.location.href = '/login';
        }
    });
});

// --- Helper: apiFetch ---
async function apiFetch(url, opciones) {
    var config = Object.assign({
        headers: { 'Content-Type': 'application/json' },
    }, opciones || {});

    // No enviar Content-Type para GET sin body
    if (!config.body && config.method !== 'POST' && config.method !== 'PUT' && config.method !== 'PATCH') {
        delete config.headers['Content-Type'];
    }

    var resp = await fetch(url, config);

    if (resp.status === 401) {
        // Sesión expirada — redirigir al login
        if (window.location.pathname !== '/login') {
            window.location.href = '/login';
            return null;
        }
    }

    var data = await resp.json();
    return data;
}

// --- Helper: POST con JSON ---
async function apiPost(url, cuerpo) {
    return apiFetch(url, {
        method: 'POST',
        body: JSON.stringify(cuerpo)
    });
}

// --- Helper: PUT con JSON ---
async function apiPut(url, cuerpo) {
    return apiFetch(url, {
        method: 'PUT',
        body: JSON.stringify(cuerpo)
    });
}

// --- Alpine component: homeApp (placeholder, se completa en items 44-47) ---
function homeApp() {
    return {
        cargando: false
    };
}

// --- Alpine component: copilotInput (para el FAB) ---
function copilotInput() {
    return {
        mensaje: '',
        enviando: false,

        async enviar() {
            if (this.mensaje.trim() === '' || this.enviando) return;
            this.enviando = true;

            var texto = this.mensaje.trim();
            this.mensaje = '';

            var contenedor = document.getElementById('copilot-mensajes');
            if (contenedor) {
                // Agregar burbuja del usuario
                contenedor.innerHTML += '<div class="flex justify-end mb-3"><div class="bg-blue-600 text-white text-sm rounded-2xl rounded-br-md px-4 py-2 max-w-[80%]">' +
                    escapeHtml(texto) + '</div></div>';
                contenedor.scrollTop = contenedor.scrollHeight;
            }

            try {
                var data = await apiPost('/api/copilot/mensaje', {
                    mensaje: texto
                });

                if (contenedor) {
                    var respuesta = (data && data.ok && data.data.respuesta) || 'No pude procesar tu mensaje.';
                    contenedor.innerHTML += '<div class="flex justify-start mb-3"><div class="bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm rounded-2xl rounded-bl-md px-4 py-2 max-w-[80%]">' +
                        escapeHtml(respuesta) + '</div></div>';
                    contenedor.scrollTop = contenedor.scrollHeight;
                }
            } catch (e) {
                if (contenedor) {
                    contenedor.innerHTML += '<div class="flex justify-start mb-3"><div class="bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm rounded-2xl rounded-bl-md px-4 py-2 max-w-[80%]">No pudimos conectar con el servidor.</div></div>';
                    contenedor.scrollTop = contenedor.scrollHeight;
                }
            } finally {
                this.enviando = false;
                lucide.createIcons();
            }
        }
    };
}

// --- Util: escape HTML para prevenir XSS ---
function escapeHtml(text) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}
