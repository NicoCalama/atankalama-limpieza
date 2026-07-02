/**
 * Service Worker — Atankalama Limpieza
 *
 * Estrategia:
 *  - Assets estáticos (JS, CSS, fuentes): Cache First (sirve rápido, actualiza en background)
 *  - Páginas HTML (/home, /habitaciones, etc.): Network First con fallback a cache offline
 *  - API (/api/*): Network Only — nunca cachear datos de la API
 */

const CACHE_VERSION = 'v3';
const CACHE_STATIC  = 'atankalama-static-' + CACHE_VERSION;
const CACHE_PAGES   = 'atankalama-pages-' + CACHE_VERSION;

const STATIC_ASSETS = [
    '/assets/js/app.js',
    '/assets/css/custom.css',
    '/offline.html',
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
    if (url.pathname.startsWith('/api/')) return;

    // Assets estáticos: Cache First
    if (url.pathname.startsWith('/assets/')) {
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
async function cacheFirst(request, cacheName) {
    var cached = await caches.match(request);

    var fetchPromise = fetch(new Request(request.url, { cache: 'reload' })).then(function(response) {
        if (response && response.ok) {
            caches.open(cacheName).then(function(cache) {
                cache.put(request, response.clone());
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

        var offline = await caches.match('/offline.html');
        return offline || new Response('Sin conexión', { status: 503 });
    }
}

// ─── Push notifications ───────────────────────────────────────────────────
self.addEventListener('push', function(event) {
    if (!event.data) return;

    var data = event.data.json();
    var options = {
        body: data.body || '',
        icon: '/assets/img/icon-192.png',
        badge: '/assets/img/icon-192.png',
        vibrate: [200, 100, 200],
        data: { url: data.url || '/home' },
        actions: data.actions || [],
        requireInteraction: data.requireInteraction || false,
    };

    event.waitUntil(
        self.registration.showNotification(data.title || 'Atankalama', options)
    );
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    var targetUrl = (event.notification.data && event.notification.data.url) ? event.notification.data.url : '/home';

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
