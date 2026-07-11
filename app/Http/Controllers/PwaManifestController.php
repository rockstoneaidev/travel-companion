<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class PwaManifestController extends Controller
{
    /**
     * The web app manifest, served dynamically so the app name always comes from
     * config('app.name') — the market name is provisional (docs/design/DESIGN.md §1).
     * theme_color holds the light value only; per-scheme theming is done with the
     * paired <meta name="theme-color"> tags in app.blade.php.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'name' => config('app.name'),
            'short_name' => config('app.name'),
            'start_url' => '/',
            'display' => 'standalone',
            'background_color' => '#F6F0E4',
            'theme_color' => '#F6F0E4',
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/icons/icon-512-maskable.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }
}
