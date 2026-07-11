<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class PwaController extends Controller
{
    /**
     * The web app manifest.
     *
     * Served from a route rather than a static file for one reason: the product name is
     * provisional and must come from `APP_NAME` (DESIGN.md §1). "Passo" is the internal
     * design codename only; the market-facing wordmark is a config value, and the manifest
     * is the one place a hard-coded name would survive a rename unnoticed.
     *
     * `theme_color` / `background_color` are the light "paper" token: a manifest carries
     * only one, so per-scheme theming happens through the paired `<meta name="theme-color">`
     * tags in the root view (DESIGN.md §4).
     */
    public function manifest(): JsonResponse
    {
        $name = (string) config('app.name');

        return response()
            ->json([
                'name' => $name,
                'short_name' => $name,
                'description' => 'A quiet travel companion. It tells you what is worth going for, now — and stays silent otherwise.',
                'lang' => 'en',
                'dir' => 'ltr',
                'start_url' => '/',
                'scope' => '/',
                'id' => '/',
                'display' => 'standalone',
                'orientation' => 'portrait',
                'background_color' => '#F6F0E4',
                'theme_color' => '#F6F0E4',
                'categories' => ['travel', 'lifestyle'],
                'icons' => [
                    ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                    ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                    ['src' => '/icons/icon-maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                    ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
                ],
            ], options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->withHeaders([
                'Content-Type' => 'application/manifest+json',
                'Cache-Control' => 'public, max-age=3600',
            ]);
    }
}
