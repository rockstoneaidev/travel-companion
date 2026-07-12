/**
 * App shell service worker (E8): cache-first for immutable build assets,
 * network-first for navigations with an honest cached fallback.
 * Offline *data* (last feed, KEPT, journal — SCREENS S11) arrives with E15;
 * this worker only makes the shell installable and resilient.
 */
// Bump the suffix whenever the caching contract changes: the activate handler
// below deletes every cache whose key is not this one, so an old shell is evicted
// on the next activation rather than left to serve stale code forever.
// v2: Inertia page fetches are cached too, not just full navigations (S11).
const SHELL_CACHE = 'app-shell-v2';

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

    /*
     * Navigations AND Inertia page fetches: network-first, falling back to the last
     * good copy (SCREENS S11 — KEPT is the screen you actually need in a dead zone).
     *
     * The Inertia case is not an optional extra. A full-page load is `mode:
     * 'navigate'`, but tapping a link *inside* the running app is an XHR carrying
     * `X-Inertia`, and it is the only kind of navigation a user does once the app
     * is open. Caching only `navigate` would mean the app worked offline exactly
     * once — on a cold reload — and failed the moment anyone tapped "Kept".
     *
     * Cached under a key that keeps the two apart: the same URL answers with HTML
     * for one and JSON for the other, and serving the wrong one is a white screen.
     */
    const isInertia = request.headers.get('X-Inertia') === 'true';

    if (request.mode === 'navigate' || isInertia) {
        const key = isInertia ? new Request(`${url.href}${url.search ? '&' : '?'}__inertia=1`, { method: 'GET' }) : request;

        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(SHELL_CACHE).then((cache) => cache.put(key, copy));
                    }
                    return response;
                })
                .catch(async () => {
                    const cached = await caches.match(key);
                    if (cached) return cached;

                    // Nothing cached for this screen: an honest failure beats a white
                    // page. Inertia needs a well-formed response or it throws.
                    return Response.error();
                }),
        );
    }
});
