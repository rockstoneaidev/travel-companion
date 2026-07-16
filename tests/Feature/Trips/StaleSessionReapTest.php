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
| A session whose budget elapsed and which nobody ended must become `expired`.
| Left un-reaped it stays `active` for ever — a three-hour session opened last
| night is still "live" the next afternoon, which is what puts the feed on a dead
| clock and blanks "everything around me".
|
*/

function staleSession(): ExploreSession
{
    $session = ExploreSession::factory()->create(['time_budget_minutes' => 180]);
    // Its whole budget elapsed hours ago.
    $session->forceFill(['started_at' => now()->subHours(6), 'expires_at' => now()->subHours(3)])->save();

    return $session->fresh();
}

it('expires an active session whose budget has elapsed', function () {
    $session = staleSession();

    expect(app(ExpireStaleSessions::class)())->toBe(1);

    $session->refresh();
    expect($session->status)->toBe(ExploreSessionStatus::Expired)
        ->and($session->ended_at)->not->toBeNull();
});

it('leaves a session still within its budget alone', function () {
    $session = ExploreSession::factory()->create();   // expires_at is in the future

    expect(app(ExpireStaleSessions::class)())->toBe(0)
        ->and($session->fresh()->status)->toBe(ExploreSessionStatus::Active);
});

it('does not resurrect or re-end a session that already ended', function () {
    $session = ExploreSession::factory()->ended()->create();
    $session->forceFill(['expires_at' => now()->subHour()])->save();

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
        'served_at' => now()->subHours(5),
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
