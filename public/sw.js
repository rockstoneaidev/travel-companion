/*
 * Passo service worker — app-shell caching (DESIGN.md §4, SCREENS.md S11).
 *
 * France-corridor dead zones and roaming data are the normal condition, not an edge
 * case (PRD risk #10). This worker keeps the shell reachable offline. It deliberately
 * does NOT cache API responses: the offline *data* set (last feed, KEPT, journal — S11)
 * is a separate concern owned by the screens that hold that data, and a stale feed
 * served silently from an HTTP cache would let a dead GO NOW keep shouting.
 *
 * Bump CACHE_VERSION to evict everything; old caches are dropped on activate.
 */
const CACHE_VERSION = 'passo-shell-v1';
const OFFLINE_URL = '/offline';

/* Cached on install: the things needed to render *something* honest with no network. */
const PRECACHE_URLS = [
    OFFLINE_URL,
    '/manifest.webmanifest',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/icon-maskable-192.png',
    '/icons/icon-maskable-512.png',
    '/icons/apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE_VERSION)
            // Individually, so one 404 cannot fail the whole install.
            .then((cache) => Promise.all(PRECACHE_URLS.map((url) => cache.add(url).catch(() => undefined))))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE_VERSION).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

/** Immutable, content-hashed build output and the icon set: safe to serve from cache first. */
function isCacheableAsset(url) {
    return url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/');
}

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Third-party requests are none of our business (and there should be none: fonts
    // are self-hosted precisely so the app owes nothing to a CDN at runtime).
    if (url.origin !== self.location.origin) {
        return;
    }

    // Never cache the API or anything session-shaped. Freshness is correctness here.
    if (url.pathname.startsWith('/api/')) {
        return;
    }

    // Hard navigations: network-first, then the last good copy of this page, then the
    // offline page. Never a browser error page.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    const copy = response.clone();
                    caches.open(CACHE_VERSION).then((cache) => cache.put(request, copy));

                    return response;
                })
                .catch(() => caches.match(request).then((cached) => cached ?? caches.match(OFFLINE_URL))),
        );

        return;
    }

    // Hashed assets and icons: cache-first, and fill the cache on the way past.
    if (isCacheableAsset(url)) {
        event.respondWith(
            caches.match(request).then(
                (cached) =>
                    cached ??
                    fetch(request).then((response) => {
                        if (response.ok) {
                            const copy = response.clone();
                            caches.open(CACHE_VERSION).then((cache) => cache.put(request, copy));
                        }

                        return response;
                    }),
            ),
        );
    }
});
