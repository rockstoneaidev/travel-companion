<?php

use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\CorridorPayloadController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\ExploreSessionBrowseController;
use App\Http\Controllers\Api\V1\ExploreSessionContextEventController;
use App\Http\Controllers\Api\V1\ExploreSessionController;
use App\Http\Controllers\Api\V1\ExploreSessionEndController;
use App\Http\Controllers\Api\V1\ExploreSessionMoreController;
use App\Http\Controllers\Api\V1\ExploreSessionOpportunityController;
use App\Http\Controllers\Api\V1\ExploreSessionRefreshController;
use App\Http\Controllers\Api\V1\NotificationReceiptController;
use App\Http\Controllers\Api\V1\PlaceSearchController;
use App\Http\Controllers\Api\V1\RecommendationFeedbackController;
use App\Http\Controllers\Api\V1\TripContextEventController;
use App\Http\Controllers\Api\V1\TripController;
use App\Http\Controllers\Api\V1\TripLocationHistoryController;
use App\Http\Controllers\Api\V1\TripModeController;
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

/*
 * The one unauthenticated endpoint (E33): mobile trades credentials for a bearer token.
 * Throttled hard — a token mint with no rate limit is an open door to credential stuffing.
 */
Route::post('v1/auth/token', [AuthTokenController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('api.v1.auth.token');

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

    // "Show me everything around me" (E51/E33) — the same data the web browse screen reads.
    Route::get('explore-sessions/{exploreSession}/browse', [ExploreSessionBrowseController::class, 'index'])
        ->can('view', 'exploreSession')
        ->name('explore-sessions.browse');

    Route::post('explore-sessions/{exploreSession}/context-events', [ExploreSessionContextEventController::class, 'store'])
        ->middleware('throttle:context-events')
        ->name('explore-sessions.context-events.store');

    // "Fresh picks from here" (E46). Throttled like the feed, because that is exactly
    // what it is: a re-rank, with the same fan-out to paid APIs behind it.
    Route::post('explore-sessions/{exploreSession}/more', [ExploreSessionMoreController::class, 'store'])
        ->can('view', 'exploreSession')
        ->name('explore-sessions.more');

    Route::post('explore-sessions/{exploreSession}/refresh', [ExploreSessionRefreshController::class, 'store'])
        ->middleware('throttle:explore-feed')
        ->can('update', 'exploreSession')
        ->name('explore-sessions.refresh.store');

    Route::post('explore-sessions/{exploreSession}/end', [ExploreSessionEndController::class, 'store'])
        ->can('update', 'exploreSession')
        ->name('explore-sessions.end.store');

    /*
    | Trips — implicit container: read / rename / mark ended, plus the OPTIONAL
    | planner create. There is deliberately no `POST /trips/{trip}/start`: in
    | pull-only Phase 1 the first session IS the start.
    */
    // Places — geo-core typeahead, backing the manual start point (SCREENS S2).
    Route::get('places/search', [PlaceSearchController::class, 'index'])
        ->name('places.search');

    // Recommendations — the feedback stream is the moat (PRD §14.5).
    Route::post('recommendations/{recommendation}/feedback', [RecommendationFeedbackController::class, 'store'])
        ->name('recommendations.feedback.store');

    Route::get('trips', [TripController::class, 'index'])->name('trips.index');
    Route::post('trips', [TripController::class, 'store'])->name('trips.store');

    Route::get('trips/{trip}', [TripController::class, 'show'])
        ->can('view', 'trip')
        ->name('trips.show');

    Route::patch('trips/{trip}', [TripController::class, 'update'])->name('trips.update');

    /*
    | TRIP MODE (E29; PRD §8.2, §14.5) — the switch that turns a pull-based app into a
    | companion. Everything Phase 2 may do (background location, geofences, interrupting
    | somebody who is not looking at their phone) is downstream of `start` returning 200.
    |
    | `stop` is gated on ownership and NOTHING else — no status check, no throttle. An
    | off-switch that can fail is an off-switch nobody trusts, and it is the control the
    | whole consent story rests on (PRD §16).
    */
    Route::post('trips/{trip}/trip-mode/start', [TripModeController::class, 'start'])
        ->can('update', 'trip')
        ->name('trips.trip-mode.start');

    Route::post('trips/{trip}/trip-mode/stop', [TripModeController::class, 'stop'])
        ->can('update', 'trip')
        ->name('trips.trip-mode.stop');

    /*
    | The background stream. Authorization lives in the Form Request (it already resolves
    | the trip), and the throttle is the same one the session stream uses — this is the
    | same firehose arriving through a different door, and PRD §13.4 is emphatic that it
    | must never become a raw GPS feed.
    */
    Route::post('trips/{trip}/context-events', [TripContextEventController::class, 'store'])
        ->middleware('throttle:context-events')
        ->name('trips.context-events.store');

    /*
    | Notification receipts (E31). The moat closing: an opened push and an ignored one are
    | the only signals that measure whether an INTERRUPTION was worth it, as opposed to
    | whether the place was any good — which the feed already knows.
    */
    Route::post('notifications/{notification}/opened', [NotificationReceiptController::class, 'opened'])
        ->name('notifications.opened');
    Route::post('notifications/{notification}/dismissed', [NotificationReceiptController::class, 'dismissed'])
        ->name('notifications.dismissed');

    // The push-token registry — the address of somebody's pocket.
    Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
    Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->name('devices.destroy');

    // Sign this device out — revoke the presented token (E33).
    Route::post('auth/revoke', [AuthTokenController::class, 'destroy'])->name('auth.revoke');

    // The offline geofence bundle (E36): the phone grabs it before it loses signal.
    Route::get('trips/{trip}/corridor-payload', [CorridorPayloadController::class, 'show'])
        ->can('view', 'trip')
        ->name('trips.corridor-payload');

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
