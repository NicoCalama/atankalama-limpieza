/**
 * app.js — Alpine.js stores y helpers globales para Atankalama Limpieza.
 *
 * Cargado después de Alpine CDN. Define:
 * - Store 'tema': modo día/noche persistido en localStorage
 * - Store 'auth': estado de sesión del usuario
 * - Helper 'apiFetch': wrapper para fetch con JSON y manejo de errores
 * - Helper 'copilotInput': Alpine component para el input del copilot FAB
 */

// --- Toggle global de tema (día/noche), independiente de Alpine ---
// Lo usa el botón de la barra superior (views/componentes/boton-tema.php).
// Alterna la clase `dark` del <html> y persiste la preferencia en localStorage.
// Se define al cargar app.js (no dentro de alpine:init) para que esté disponible
// aunque Alpine aún no haya arrancado.
window.toggleTema = function () {
    var oscuro = document.documentElement.classList.toggle('dark');
    localStorage.setItem('tema', oscuro ? 'dark' : 'light');
    // Mantener en sync el store Alpine si ya existe (vistas que lo consulten).
    if (window.Alpine && Alpine.store && Alpine.store('tema')) {
        Alpine.store('tema').oscuro = oscuro;
    }
};

// --- Fecha "hoy" en la zona del backend (America/Santiago) ---
// Devuelve 'YYYY-MM-DD'. Usar SIEMPRE esto para la fecha de trabajo del día en
// vez de new Date().toISOString().slice(0,10): toISOString da la fecha en UTC y
// de noche en Chile (UTC ya en el día siguiente) no coincidiría con date('Y-m-d')
// del servidor, mandando asignaciones/consultas al día equivocado.
window.hoyServidor = function () {
    var partes = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/Santiago',
        year: 'numeric', month: '2-digit', day: '2-digit'
    }).formatToParts(new Date());
    var m = {};
    partes.forEach(function (p) { m[p.type] = p.value; });
    return m.year + '-' + m.month + '-' + m.day;
};

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

        // Alpine llama init() al registrar el store: cargamos la sesión/permisos en cada carga de
        // página para que los gates de UI (x-if="$store.auth.tienePermiso(...)") funcionen. Sin esto
        // el store queda vacío y los botones por permiso nunca aparecen.
        init() {
            this.cargar();
        },

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
            window.location.href = (window.BASE_PATH || '') + '/login';
        }
    });
});

// --- Helper: apiFetch ---
// Las llamadas se escriben root-relative ('/api/...') y acá se les antepone
// BASE_PATH (subpath de prod, inyectado por el layout). No pasarle URLs ya
// prefijadas con u() — quedaría el prefijo doble.
async function apiFetch(url, opciones) {
    if (url.charAt(0) === '/') {
        url = (window.BASE_PATH || '') + url;
    }

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
        var loginUrl = (window.BASE_PATH || '') + '/login';
        if (window.location.pathname !== loginUrl) {
            window.location.href = loginUrl;
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
