<?php

use App\Http\Controllers\PwaManifestController;
use App\Http\Controllers\Web\ExploreSessionController;
use App\Http\Controllers\Web\ExploreSessionEndController;
use App\Http\Controllers\Web\OpportunityController;
use App\Http\Controllers\Web\RecommendationFeedbackController;
use App\Http\Controllers\Web\TripController;
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

    /*
    | The Inertia delivery surface for explore sessions and trips. Every route
    | here calls the same domain action as its /api/v1 twin (CLAUDE.md).
    */
    Route::get('explore', [ExploreSessionController::class, 'index'])->name('explore.index');
    Route::post('explore', [ExploreSessionController::class, 'store'])->name('explore.store');

    Route::get('explore/{exploreSession}', [ExploreSessionController::class, 'show'])
        ->can('view', 'exploreSession')
        ->name('explore.show');

    Route::post('explore/{exploreSession}/end', [ExploreSessionEndController::class, 'store'])
        ->can('update', 'exploreSession')
        ->name('explore.end');

    Route::get('trips', [TripController::class, 'index'])->name('trips.index');

    Route::get('trips/{trip}', [TripController::class, 'show'])
        ->can('view', 'trip')
        ->name('trips.show');

    Route::patch('trips/{trip}', [TripController::class, 'update'])->name('trips.update');

    Route::get('opportunities/{opportunity}', [OpportunityController::class, 'show'])
        ->name('opportunities.show');

    Route::post('recommendations/{recommendation}/feedback', [RecommendationFeedbackController::class, 'store'])
        ->name('recommendations.feedback.store');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
require __DIR__.'/auth.php';
