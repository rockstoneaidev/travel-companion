import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { initializeTheme } from './hooks/use-appearance';
import { registerFeedbackFlush } from './lib/feedback';

declare global {
    const route: typeof routeFn;
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#C0603A',
    },
});

// This will set light / dark mode on load...
initializeTheme();

// Carry home anything the last dead zone stranded, and keep watching for the
// next reconnect (SCREENS S11). Runs in dev too — an offline bug that only
// exists in production is a bug you find in France.
registerFeedbackFlush();

// PWA shell worker — production only, so it never interferes with Vite HMR.
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        void navigator.serviceWorker.register('/sw.js');
    });

    /*
     * When a new worker takes over, THIS page is still running the old shell — the
     * one whose cache the new worker just deleted. Left alone it limps: assets it
     * asks for are gone from the cache it expects, and in-app navigation can wedge
     * while a full page load looks fine. That is the bug we shipped, and the only
     * cure anyone found was a hard refresh.
     *
     * So reload once, automatically — but ONLY if this page was already controlled
     * when it loaded. `clients.claim()` makes `controllerchange` fire on the very
     * first install too, and a page that has just been claimed for the first time was
     * never stale: reloading there would spin every first-time visitor through a
     * pointless extra load.
     */
    const wasControlled = navigator.serviceWorker.controller !== null;
    let reloading = false;

    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (!wasControlled || reloading) return;

        reloading = true;
        window.location.reload();
    });
}
