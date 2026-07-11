<?php

use App\Http\Controllers\Api\V1\ExploreSessionContextEventController;
use App\Http\Controllers\Api\V1\ExploreSessionController;
use App\Http\Controllers\Api\V1\ExploreSessionEndController;
use App\Http\Controllers\Api\V1\ExploreSessionOpportunityController;
use App\Http\Controllers\Api\V1\TripController;
use App\Http\Controllers\Api\V1\TripLocationHistoryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (versioned)
|--------------------------------------------------------------------------
|
| These JSON endpoints are the contract the mobile client (Phase 2) will
| consume. Per CLAUDE.md, all product logic lives in app/Domain services;
| both these API controllers and the Inertia web controllers are thin
| delivery wrappers over the same services. Keep the API versioned from
| day one so the native client is additive, never a backend rewrite.
|
| The shape is PRD §14.5, verbatim.
|
*/

Route::middleware('auth:sanctum')->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user())->name('user');

    /*
    | Explore Sessions — the Phase 1 interaction (PRD §6.6). The session is
    | top-level; the trip is resolved-or-created behind it.
    */
    Route::post('explore-sessions', [ExploreSessionController::class, 'store'])
        ->name('explore-sessions.store');

    Route::get('explore-sessions/{exploreSession}', [ExploreSessionController::class, 'show'])
        ->can('view', 'exploreSession')
        ->name('explore-sessions.show');

    // Rate-limited: today it is a database read, but this is the endpoint that
    // will fan out to paid APIs and an LLM (conventions/04).
    Route::get('explore-sessions/{exploreSession}/opportunities', [ExploreSessionOpportunityController::class, 'index'])
        ->middleware('throttle:explore-feed')
        ->can('view', 'exploreSession')
        ->name('explore-sessions.opportunities.index');

    Route::post('explore-sessions/{exploreSession}/context-events', [ExploreSessionContextEventController::class, 'store'])
        ->middleware('throttle:context-events')
        ->name('explore-sessions.context-events.store');

    Route::post('explore-sessions/{exploreSession}/end', [ExploreSessionEndController::class, 'store'])
        ->can('update', 'exploreSession')
        ->name('explore-sessions.end.store');

    /*
    | Trips — implicit container: read / rename / mark ended, plus the OPTIONAL
    | planner create. There is deliberately no `POST /trips/{trip}/start`: in
    | pull-only Phase 1 the first session IS the start.
    */
    Route::get('trips', [TripController::class, 'index'])->name('trips.index');
    Route::post('trips', [TripController::class, 'store'])->name('trips.store');

    Route::get('trips/{trip}', [TripController::class, 'show'])
        ->can('view', 'trip')
        ->name('trips.show');

    Route::patch('trips/{trip}', [TripController::class, 'update'])->name('trips.update');

    Route::delete('trips/{trip}/location-history', [TripLocationHistoryController::class, 'destroy'])
        ->can('eraseLocationHistory', 'trip')
        ->name('trips.location-history.destroy');

    /*
    | Not built yet. Named with their epic so this list stays honest:
    |   POST /recommendations/{recommendation}/feedback     (E8)
    |   GET  /recommendations/{recommendation}/explanation  (E8)
    |   GET  /trips/{trip}/digest                           (E13)
    */
});
