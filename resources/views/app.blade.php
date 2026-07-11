<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        {{-- viewport-fit=cover is what makes env(safe-area-inset-*) resolve — the floating
             tab bar clears the home indicator because of it (DESIGN §4). --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        <title inertia>{{ config('app.name') }}</title>

        {{-- Set the theme class before the first paint, from localStorage or the OS
             preference — a dark-mode traveller opening this at dusk never gets a white
             flash. React's initializeTheme() runs too late to prevent it. --}}
        <script>
            (function () {
                try {
                    var appearance = localStorage.getItem('appearance') || 'system';
                    var dark = appearance === 'dark'
                        || (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.classList.toggle('dark', dark);
                } catch (e) {
                    // localStorage throws in some private modes; the OS preference is a fine default.
                }
            })();
        </script>

        {{-- A manifest carries only one theme_color, so per-scheme chrome comes from these
             paired metas (DESIGN §4): light "paper", dark "night paper". --}}
        <meta name="theme-color" content="#F6F0E4" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#221B13" media="(prefers-color-scheme: dark)">

        <link rel="manifest" href="{{ route('pwa.manifest') }}">
        <link rel="icon" href="/icons/icon-192.png" type="image/png">
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">

        {{-- Fonts are self-hosted via Fontsource through Vite. No font CDN: the installed
             app must work offline, and EU/GDPR forbids leaking visitor IPs to a font host. --}}

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
