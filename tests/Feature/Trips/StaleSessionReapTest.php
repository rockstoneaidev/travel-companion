<?php

declare(strict_types=1);

use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Trips\Actions\ExpireStaleSessions;
use App\Domain\Trips\Enums\ExploreSessionStatus;
use App\Domain\Trips\Models\ExploreSession;
use App\Jobs\Trips\ReapStaleSessionsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The session reaper (ExploreSessionStatus::Expired)
|--------------------------------------------------------------------------
|
| A session is reaped when it has been ABANDONED — no feed served for the idle
| window — NOT when its time budget elapsed. The budget is a reach envelope, not a
| deadline: a "3-hour" session explored all afternoon must stay live. Only silence
| for a whole night marks a session as walked away from.
|
*/

/** A session started long enough ago to be past the idle window, with no recent activity. */
function staleSession(): ExploreSession
{
    $session = ExploreSession::factory()->create(['time_budget_minutes' => 180]);
    $session->forceFill(['started_at' => now()->subHours(20)])->save();

    return $session->fresh();
}

it('expires a session abandoned past the idle window', function () {
    $session = staleSession();

    expect(app(ExpireStaleSessions::class)())->toBe(1);

    $session->refresh();
    expect($session->status)->toBe(ExploreSessionStatus::Expired)
        ->and($session->ended_at)->not->toBeNull();
});

it('leaves a long-open session alone while it is still being served', function () {
    // Opened a full day ago — well past any time budget — but a feed was served minutes ago.
    // The budget is not a deadline; recent activity is what keeps it live.
    $session = ExploreSession::factory()->create(['time_budget_minutes' => 180]);
    $session->forceFill(['started_at' => now()->subHours(30)])->save();

    Recommendation::query()->create([
        'user_id' => $session->user_id,
        'explore_session_id' => $session->id,
        'trip_id' => $session->trip_id,
        'opportunity_id' => null,
        'position' => 1,
        'scores' => [],
        'score_inputs' => ['candidate' => ['name' => 'X', 'lat' => 59.31, 'lng' => 18.02]],
        'scoring_model_version' => 'v1',
        'taxonomy_version' => 1,
        'served_at' => now()->subMinutes(3),
    ]);

    expect(app(ExpireStaleSessions::class)())->toBe(0)
        ->and($session->fresh()->status)->toBe(ExploreSessionStatus::Active);
});

it('leaves a freshly started session alone', function () {
    $session = ExploreSession::factory()->create();   // started just now

    expect(app(ExpireStaleSessions::class)())->toBe(0)
        ->and($session->fresh()->status)->toBe(ExploreSessionStatus::Active);
});

it('does not resurrect or re-end a session that already ended', function () {
    $session = ExploreSession::factory()->ended()->create();
    $session->forceFill(['started_at' => now()->subHours(20)])->save();

    expect(app(ExpireStaleSessions::class)())->toBe(0)
        ->and($session->fresh()->status)->toBe(ExploreSessionStatus::Ended);
});

it('closes served-but-untouched cards as ignored when it expires a session', function () {
    $session = staleSession();

    $rec = Recommendation::query()->create([
        'user_id' => $session->user_id,
        'explore_session_id' => $session->id,
        'trip_id' => $session->trip_id,
        'opportunity_id' => null,
        'position' => 1,
        'scores' => [],
        'score_inputs' => ['candidate' => ['name' => 'Somewhere', 'lat' => 59.31, 'lng' => 18.02]],
        'scoring_model_version' => 'v1',
        'taxonomy_version' => 1,
        // Served long ago — before the idle window — so the session still counts as abandoned.
        'served_at' => now()->subHours(20),
    ]);

    // Expiry is a session end, so the card that was served and never touched is closed as
    // `ignored` — the same batched-on-session-end signal a manual end records (SCREENS.md).
    app(ExpireStaleSessions::class)();

    expect(
        DB::table('recommendation_feedback')
            ->where('recommendation_id', $rec->id)
            ->where('event', 'ignored')
            ->count(),
    )->toBe(1);
});

it('runs from the scheduled job wrapper', function () {
    $session = staleSession();

    app(ReapStaleSessionsJob::class)->handle(app(ExpireStaleSessions::class));

    expect($session->fresh()->status)->toBe(ExploreSessionStatus::Expired);
});
