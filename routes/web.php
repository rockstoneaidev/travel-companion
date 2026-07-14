<?php

use App\Http\Controllers\PwaManifestController;
use App\Http\Controllers\Web\BrowseController;
use App\Http\Controllers\Web\CalibrationController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DigestController;
use App\Http\Controllers\Web\ExploreSessionContextEventController;
use App\Http\Controllers\Web\ExploreSessionController;
use App\Http\Controllers\Web\ExploreSessionEndController;
use App\Http\Controllers\Web\ExploreSessionRefreshController;
use App\Http\Controllers\Web\JournalController;
use App\Http\Controllers\Web\KeptController;
use App\Http\Controllers\Web\LegalController;
use App\Http\Controllers\Web\OpportunityController;
use App\Http\Controllers\Web\PlaceSearchController;
use App\Http\Controllers\Web\RecommendationFeedbackController;
use App\Http\Controllers\Web\TripController;
use App\Http\Middleware\AskForProfilingConsent;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::get('manifest.webmanifest', PwaManifestController::class)->name('pwa.manifest');

Route::get('licenses', function () {
    return Inertia::render('licenses');
})->name('licenses');

/*
| The legal pages — PUBLIC, and outside the auth group on purpose. Art. 13 wants the
| privacy notice available "at the time when personal data are obtained", which is the
| sign-up form: a notice you can only reach once you have already signed up arrives
| after the decision it exists to inform. The terms page is what makes Art. 6(1)(b)
| ("performance of a contract") a true statement about the location processing.
*/
Route::get('privacy-policy', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('terms-of-service', [LegalController::class, 'terms'])->name('legal.terms');

// Design-system demo (E8 done-condition): every design-system component, both themes.
Route::get('design', function () {
    return Inertia::render('design');
})->name('design');

Route::middleware(['auth'])->group(function () {
    /*
    | THE TWO ENTRY SCREENS, behind "have we ever asked about profiling?" (DPIA §3.2).
    |
    | Existing accounts predate consent, so they have never been asked — and until they
    | are, their taste profile silently stops learning. They deserve the question.
    |
    | ONCE, and only on the way in. The middleware stops asking the moment they answer,
    | either way. Sending someone to the consent screen on every page load until they
    | agree is not consent, it is attrition — and consent extracted that way is not
    | freely given (Art. 4(11)), which makes it no consent at all.
    */
    Route::middleware(AskForProfilingConsent::class)->group(function (): void {
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('explore', [ExploreSessionController::class, 'index'])->name('explore.index');
    });

    /*
    | Onboarding taste calibration (SCREENS S9). Content comes from the backend,
    | never the client — the pair set is versioned under calibration_version.
    | Interruptible: each choice posts as it is made and the flow resumes at the
    | next unanswered pair.
    */
    Route::get('welcome', [CalibrationController::class, 'welcome'])->name('calibrate.welcome');

    // Explicit consent to be profiled (Art. 9(2)(a), DPIA §3.2). A separate,
    // affirmative act — never a side effect of pressing "start".
    Route::post('calibrate/consent', [CalibrationController::class, 'consent'])->name('calibrate.consent');
    Route::post('calibrate/decline', [CalibrationController::class, 'decline'])->name('calibrate.decline');
    Route::get('calibrate/practical', [CalibrationController::class, 'practical'])->name('calibrate.practical');
    Route::post('calibrate/practical', [CalibrationController::class, 'complete'])->name('calibrate.complete');
    Route::get('calibrate/{number}', [CalibrationController::class, 'pair'])->whereNumber('number')->name('calibrate.pair');
    Route::post('calibrate/{number}', [CalibrationController::class, 'choose'])->whereNumber('number')->name('calibrate.choose');

    /*
    | The Inertia delivery surface for explore sessions and trips. Every route
    | here calls the same domain action as its /api/v1 twin (CLAUDE.md).
    */
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

    /*
     * "Show me everything" (E51).
     *
     * The feed is five because five is the INTERRUPTION budget — how much is worth putting
     * in front of somebody unasked. It is not a limit on what a person may look at, and
     * treating it as one makes the product an authority it has not earned.
     *
     * Costs nothing extra: the pipeline already scored every reachable candidate and threw
     * all but five away. `open` is where money is spent — on the one they actually chose.
     */
    Route::get('explore/{exploreSession}/browse', [BrowseController::class, 'index'])
        ->can('view', 'exploreSession')
        ->name('explore.browse');

    Route::post('explore/{exploreSession}/browse/{placeId}', [BrowseController::class, 'open'])
        ->can('view', 'exploreSession')
        ->name('explore.browse.open');

    /*
     * The living feed (E46).
     *
     * `context-events` is how the client finally tells the server where the user is —
     * the endpoint has existed since E4 and nothing ever called it, which is precisely
     * why the feed you got in Liljeholmen was still the feed you had in Hornstull.
     * Authorization lives in the Form Request (it is the API route's twin), so no
     * ->can() here; the throttle is the API's, because it is the same firehose.
     */
    Route::post('explore/{exploreSession}/context-events', [ExploreSessionContextEventController::class, 'store'])
        ->middleware('throttle:context-events')
        ->name('explore.context-events.store');

    Route::post('explore/{exploreSession}/refresh', [ExploreSessionRefreshController::class, 'store'])
        ->can('update', 'exploreSession')
        ->name('explore.refresh');

    // S6 — KEPT. Windows are re-checked on every open, so this is a GET with no cache.
    Route::get('kept', [KeptController::class, 'index'])->name('kept.index');

    // S8 — the digest release valve (PRD §12.4). A screen you find; no push in Phase 1.
    Route::get('digest/today', [DigestController::class, 'today'])->name('digest.today');

    // The digest, drawn as geography. NOT the session map: the digest is a screen you read
    // over breakfast, and `/map` resolves an active session you do not have — so "Open map"
    // used to drop you on the session START FORM, which reads as "it started a session".
    Route::get('digest/today/map', [DigestController::class, 'map'])->name('digest.map');

    // S7 — JOURNAL. The seed of "your travel memory belongs to you".
    Route::get('journal', [JournalController::class, 'index'])->name('journal.index');

    Route::get('trips', [TripController::class, 'index'])->name('trips.index');

    // The planner path (PRD §6.6). It opens a `planned` trip — never an `active` one:
    // "active" begins at the first session there.
    Route::post('trips', [TripController::class, 'store'])->name('trips.store');

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
