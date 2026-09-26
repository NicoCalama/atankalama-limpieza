/**
 * Service Worker — Atankalama Limpieza
 *
 * Estrategia:
 *  - Assets estáticos (JS, CSS, fuentes): Cache First, revalidando en background con una
 *    petición condicional (304 sin cuerpo si no cambió)
 *  - Páginas HTML (/home, /habitaciones, etc.): Network First con fallback a cache offline
 *  - API (/api/*): Network Only — nunca cachear datos de la API
 */

const CACHE_VERSION = 'v10'; // v10: la revalidación de assets pasa a ser condicional (304) en vez de bajar el archivo entero en cada página (datos móviles); bump para limpiar las copias viejas
const CACHE_STATIC  = 'atankalama-static-' + CACHE_VERSION;
const CACHE_PAGES   = 'atankalama-pages-' + CACHE_VERSION;

// La app puede vivir bajo un subpath (prod: /limpieza). El scope del SW es la
// fuente del prefijo ('/' en dev → '', '/limpieza/' en prod → '/limpieza'),
// así el mismo sw.js funciona en ambos sin build step.
const BASE = new URL(self.registration.scope).pathname.replace(/\/$/, '');

const STATIC_ASSETS = [
    BASE + '/assets/js/app.js',
    BASE + '/assets/css/custom.css',
    BASE + '/offline.html',
];

// ─── Install: pre-cachear assets estáticos ────────────────────────────────
self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(CACHE_STATIC).then(function(cache) {
            // cache: 'reload' evita que el navegador sirva una copia rancia desde su
            // HTTP cache al precachear; el SW guarda siempre la última versión real
            // (sin esto, un app.js viejo en el HTTP cache se quedaría pegado).
            return Promise.all(STATIC_ASSETS.map(function(url) {
                return fetch(new Request(url, { cache: 'reload' })).then(function(response) {
                    if (response && response.ok) {
                        return cache.put(url, response);
                    }
                });
            }));
        }).then(function() {
            return self.skipWaiting();
        })
    );
});

// ─── Activate: limpiar caches viejos ─────────────────────────────────────
self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(keys) {
            return Promise.all(
                keys
                    .filter(function(key) {
                        return key !== CACHE_STATIC && key !== CACHE_PAGES;
                    })
                    .map(function(key) {
                        return caches.delete(key);
                    })
            );
        }).then(function() {
            return self.clients.claim();
        })
    );
});

// ─── Fetch ────────────────────────────────────────────────────────────────
self.addEventListener('fetch', function(event) {
    var url = new URL(event.request.url);

    // Ignorar requests que no son GET
    if (event.request.method !== 'GET') return;

    // Ignorar extensiones de CDN externas (Tailwind, Alpine, Lucide, Fonts)
    if (url.origin !== self.location.origin) return;

    // API: siempre red, nunca cache
    if (url.pathname.startsWith(BASE + '/api/')) return;

    // Assets estáticos: Cache First
    if (url.pathname.startsWith(BASE + '/assets/')) {
        event.respondWith(cacheFirst(event.request, CACHE_STATIC));
        return;
    }

    // Páginas HTML: Network First con fallback offline
    event.respondWith(networkFirstWithOfflineFallback(event.request));
});

// ─── Estrategia: Cache First + revalidación en background ─────────────────
// (stale-while-revalidate): sirve el cache al instante pero dispara un fetch en
// paralelo que refresca el cache para la PRÓXIMA carga. Así un asset que cambia
// (p. ej. app.js tras un deploy) deja de quedar congelado indefinidamente; se
// actualiza solo en la siguiente visita sin necesidad de subir CACHE_VERSION.
//
// Datos móviles: antes la revalidación iba con cache:'reload', que se salta el HTTP cache y
// bajaba el archivo ENTERO en cada página (~70 KB por vista con la ayuda guiada activa). Con
// cache:'no-cache' va una petición condicional (If-Modified-Since): si no cambió, el servidor
// responde 304 sin cuerpo (<1 KB). Se sigue revalidando TODO, también lo que lleva ?v=: así,
// si un deploy sube un asset sin que cambie su ?v= (p. ej. app.js solo al docroot y no a
// app_core, de donde sale el filemtime), el teléfono igual toma el archivo nuevo.
async function cacheFirst(request, cacheName) {
    var cached = await caches.match(request);

    var fetchPromise = fetch(new Request(request.url, { cache: 'no-cache' })).then(function(response) {
        if (response && response.ok) {
            // Clonar ACÁ, sincrónico, mientras el body sigue intacto. Si esto es un cache
            // miss, `response` (el original) se devuelve abajo y el respondWith empieza a
            // consumir su body en paralelo; clonar recién dentro del then async de
            // caches.open llegaría tarde → "Failed to execute 'clone' on 'Response': body
            // already used". El clon guardado en cache es una copia aparte del original.
            var copia = response.clone();
            caches.open(cacheName).then(function(cache) {
                cache.put(request, copia);
            });
        }
        return response;
    }).catch(function() {
        return null;
    });

    // Si hay copia cacheada, respondé con ella ya (la revalidación sigue en background).
    if (cached) return cached;

    // Primera vez (cache miss): esperá la red.
    return fetchPromise;
}

// ─── Estrategia: Network First con fallback ───────────────────────────────
async function networkFirstWithOfflineFallback(request) {
    try {
        var response = await fetch(request);
        if (response.ok) {
            var cache = await caches.open(CACHE_PAGES);
            cache.put(request, response.clone());
        }
        return response;
    } catch (e) {
        var cached = await caches.match(request);
        if (cached) return cached;

        var offline = await caches.match(BASE + '/offline.html');
        return offline || new Response('Sin conexión', { status: 503 });
    }
}

// ─── Push notifications ───────────────────────────────────────────────────
self.addEventListener('push', function(event) {
    if (!event.data) return;

    var data = event.data.json();
    var options = {
        body: data.body || '',
        icon: BASE + '/assets/img/icon-192.png',
        badge: BASE + '/assets/img/icon-192.png',
        vibrate: [200, 100, 200],
        // data.url llega del backend ya con prefijo (Url::a); el default lo arma el SW.
        data: { url: data.url || (BASE + '/home') },
        actions: data.actions || [],
        requireInteraction: data.requireInteraction || false,
    };

    event.waitUntil(
        self.registration.showNotification(data.title || 'Atankalama', options)
    );
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    var targetUrl = (event.notification.data && event.notification.data.url) ? event.notification.data.url : (BASE + '/home');

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
            for (var i = 0; i < clientList.length; i++) {
                var client = clientList[i];
                if (client.url.includes(self.location.origin) && 'focus' in client) {
                    client.navigate(targetUrl);
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});
