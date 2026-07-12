/**
 * App shell service worker (E8): cache-first for immutable build assets,
 * network-first for navigations with an honest cached fallback.
 * Offline *data* (last feed, KEPT, journal — SCREENS S11) arrives with E15;
 * this worker only makes the shell installable and resilient.
 */
// Renamed from the dropped "passo" codename. The activate handler below deletes
// every cache whose key is not this one, so the old passo-shell-v1 cache is
// evicted on the next activation rather than orphaned. Bump the version suffix
// whenever the shell's caching contract changes.
const SHELL_CACHE = 'app-shell-v1';

self.addEventListener('install', (event) => {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== SHELL_CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // Immutable Vite build assets: cache-first.
    if (url.origin === self.location.origin && (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/'))) {
        event.respondWith(
            caches.open(SHELL_CACHE).then(async (cache) => {
                const hit = await cache.match(request);
                if (hit) return hit;
                const response = await fetch(request);
                if (response.ok) cache.put(request, response.clone());
                return response;
            }),
        );
        return;
    }

    // Navigations: network-first, falling back to the last cached navigation.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(SHELL_CACHE).then((cache) => cache.put(request, copy));
                    }
                    return response;
                })
                .catch(async () => {
                    const cached = await caches.match(request);
                    return cached ?? Response.error();
                }),
        );
    }
});
