<?php

use App\Http\Controllers\PwaManifestController;
use App\Http\Controllers\Web\CalibrationController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DigestController;
use App\Http\Controllers\Web\ExploreSessionController;
use App\Http\Controllers\Web\ExploreSessionEndController;
use App\Http\Controllers\Web\JournalController;
use App\Http\Controllers\Web\KeptController;
use App\Http\Controllers\Web\OpportunityController;
use App\Http\Controllers\Web\PlaceSearchController;
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

// Design-system demo (E8 done-condition): every design-system component, both themes.
Route::get('design', function () {
    return Inertia::render('design');
})->name('design');

Route::middleware(['auth'])->group(function () {
    // Home — "today": the digest, where you left off, and what you kept. NOT a second
    // Explore, and not a map of every place we know (DashboardController).
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    /*
    | Onboarding taste calibration (SCREENS S9). Content comes from the backend,
    | never the client — the pair set is versioned under calibration_version.
    | Interruptible: each choice posts as it is made and the flow resumes at the
    | next unanswered pair.
    */
    Route::get('welcome', [CalibrationController::class, 'welcome'])->name('calibrate.welcome');
    Route::get('calibrate/practical', [CalibrationController::class, 'practical'])->name('calibrate.practical');
    Route::post('calibrate/practical', [CalibrationController::class, 'complete'])->name('calibrate.complete');
    Route::get('calibrate/{number}', [CalibrationController::class, 'pair'])->whereNumber('number')->name('calibrate.pair');
    Route::post('calibrate/{number}', [CalibrationController::class, 'choose'])->whereNumber('number')->name('calibrate.choose');

    /*
    | The Inertia delivery surface for explore sessions and trips. Every route
    | here calls the same domain action as its /api/v1 twin (CLAUDE.md).
    */
    Route::get('explore', [ExploreSessionController::class, 'index'])->name('explore.index');
    Route::post('explore', [ExploreSessionController::class, 'store'])->name('explore.store');

    // Typeahead for the manual start point on S2 — JSON, not a page visit.
    Route::get('places/search', [PlaceSearchController::class, 'index'])->name('places.search');

    Route::get('explore/{exploreSession}', [ExploreSessionController::class, 'show'])
        ->can('view', 'exploreSession')
        ->name('explore.show');

    // S3 — the feed as geography. `/map` is the bookmarkable entry point; the real
    // screen is session-scoped, so ownership gates it like every other session route.
    Route::get('map', [ExploreSessionController::class, 'activeMap'])->name('explore.active-map');

    Route::get('explore/{exploreSession}/map', [ExploreSessionController::class, 'map'])
        ->can('view', 'exploreSession')
        ->name('explore.map');

    Route::post('explore/{exploreSession}/end', [ExploreSessionEndController::class, 'store'])
        ->can('update', 'exploreSession')
        ->name('explore.end');

    // S6 — KEPT. Windows are re-checked on every open, so this is a GET with no cache.
    Route::get('kept', [KeptController::class, 'index'])->name('kept.index');

    // S8 — the digest release valve (PRD §12.4). A screen you find; no push in Phase 1.
    Route::get('digest/today', [DigestController::class, 'today'])->name('digest.today');

    // S7 — JOURNAL. The seed of "your travel memory belongs to you".
    Route::get('journal', [JournalController::class, 'index'])->name('journal.index');

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
