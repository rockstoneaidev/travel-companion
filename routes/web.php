<?php

use App\Http\Controllers\PwaManifestController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::get('manifest.webmanifest', PwaManifestController::class)->name('pwa.manifest');

Route::get('licenses', function () {
    return Inertia::render('licenses');
})->name('licenses');

// Design-system demo (E8 done-condition): every passo component, both themes.
Route::get('design', function () {
    return Inertia::render('design');
})->name('design');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
require __DIR__.'/auth.php';
