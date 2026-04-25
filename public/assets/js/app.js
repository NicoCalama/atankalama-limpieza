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

// --- Util: escape HTML para prevenir XSS ---
function escapeHtml(text) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}

// ─── Push Notifications ───────────────────────────────────────────────────────

var PushManager = {
    _registration: null,

    /** Verifica si el browser soporta push y si ya hay permiso */
    soportado() {
        return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    },

    estado() {
        if (!this.soportado()) return 'no-soportado';
        return Notification.permission; // 'default' | 'granted' | 'denied'
    },

    /** Suscribir este dispositivo. Retorna true si ok. */
    async suscribir() {
        if (!this.soportado()) return false;

        var permiso = await Notification.requestPermission();
        if (permiso !== 'granted') return false;

        try {
            var reg = await navigator.serviceWorker.ready;
            var keyResp = await apiFetch('/api/push/vapid-public-key');
            if (!keyResp || !keyResp.ok) return false;

            var publicKey = keyResp.data.publicKey;
            var sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(publicKey)
            });

            var json = sub.toJSON();
            await apiPost('/api/push/suscribir', {
                endpoint: json.endpoint,
                p256dh:   json.keys.p256dh,
                auth:     json.keys.auth
            });
            return true;
        } catch (e) {
            return false;
        }
    },

    /** Desuscribir este dispositivo. */
    async desuscribir() {
        try {
            var reg = await navigator.serviceWorker.ready;
            var sub = await reg.pushManager.getSubscription();
            if (sub) {
                await apiFetch('/api/push/suscribir', {
                    method: 'DELETE',
                    body: JSON.stringify({ endpoint: sub.endpoint }),
                    headers: { 'Content-Type': 'application/json' }
                });
                await sub.unsubscribe();
            }
        } catch (e) {}
    },

    /** Verifica si este dispositivo ya está suscrito */
    async estaSuscrito() {
        if (!this.soportado()) return false;
        try {
            var reg = await navigator.serviceWorker.ready;
            var sub = await reg.pushManager.getSubscription();
            return sub !== null;
        } catch (e) {
            return false;
        }
    }
};

function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var rawData = window.atob(base64);
    var outputArray = new Uint8Array(rawData.length);
    for (var i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}
