<?php

use App\Http\Controllers\PwaController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

/*
|--------------------------------------------------------------------------
| PWA shell
|--------------------------------------------------------------------------
|
| The manifest is a route, not a static file, because the product name must come
| from APP_NAME (DESIGN.md §1). The offline page is a self-contained Blade view,
| precached by the service worker (SCREENS.md S11).
|
*/
Route::get('manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::view('offline', 'offline')->name('pwa.offline');

/*
|--------------------------------------------------------------------------
| Attributions
|--------------------------------------------------------------------------
|
| Public and unauthenticated on purpose: ODbL attribution is owed to anyone who sees
| the data, not gated behind a login (ODBL-REVIEW §6).
|
*/
Route::get('attributions', function () {
    return Inertia::render('passo/attributions');
})->name('attributions');

/*
|--------------------------------------------------------------------------
| Passo kit gallery (non-production)
|--------------------------------------------------------------------------
|
| The reviewable surface for the design system: every component, both themes, all
| three responsive frames. It is the acceptance test for E8, so it ships with the
| kit — but it is not a product screen and never reaches production.
|
| `/design/frame` is the same kit rendered as a bare page; the gallery embeds it in
| 400 / 640 / 1024px iframes, which is the only honest way to demo viewport-driven
| breakpoints on one desktop screen.
|
*/
if (! app()->isProduction()) {
    Route::get('design', fn () => Inertia::render('passo/kit'))->name('design.kit');
    Route::get('design/frame', fn () => Inertia::render('passo/kit-frame'))->name('design.frame');
}

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
require __DIR__.'/auth.php';
