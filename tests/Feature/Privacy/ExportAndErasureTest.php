<?php

declare(strict_types=1);

use App\Domain\Privacy\Actions\ExportUserData;
use App\Domain\Privacy\Services\HomeZone;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E17 — export and erasure (GDPR Art. 20 and Art. 17, PRD §16)
|--------------------------------------------------------------------------
|
| "Your travel memory belongs to you" is a positioning claim, and it is only
| true if the export is real and the deletion actually deletes.
|
| E17's done-when says "deletion verified by test". So this file enumerates
| every table with a user_id, deletes an account, and looks.
|
*/

/** Every table that holds something of the user's. Enumerated from the SCHEMA. */
function userScopedTables(): array
{
    return array_map(
        static fn (object $r): string => $r->table_name,
        DB::select(
            "SELECT table_name FROM information_schema.columns
              WHERE column_name = 'user_id' AND table_schema = 'public'
                AND table_name NOT IN ('sessions')   -- the auth session, not user data
              ORDER BY table_name",
        ),
    );
}

function userWithHistory(): User
{
    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);

    $session = ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id,
    ]);

    // Calibration, taste, a served recommendation, and feedback on it.
    test()->actingAs($user)->post('/calibrate/1', ['side' => 'a']);
    test()->post('/calibrate/practical', ['walk_minutes' => 40, 'price_band' => 3]);

    $recommendationId = (string) Str::uuid();

    DB::table('recommendations')->insert([
        'id' => $recommendationId,
        'user_id' => $user->id,
        'explore_session_id' => $session->id,
        'trip_id' => $trip->id,
        'opportunity_id' => null,
        'position' => 1,
        'scores' => json_encode(['composite' => 0.8]),
        'score_inputs' => json_encode(['candidate' => ['name' => 'Trekanten', 'lat' => 59.31, 'lng' => 18.02]]),
        'scoring_model_version' => 'v1',
        'taxonomy_version' => 1,
        'served_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('recommendation_feedback')->insert([
        'recommendation_id' => $recommendationId,
        'event' => 'visited',
        'metadata' => json_encode([]),
        'occurred_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

it('exports everything we hold, including what we concluded', function () {
    $user = userWithHistory();

    $export = app(ExportUserData::class)($user->id);

    // Not a token gesture. The taste profile and the feedback ledger are the two
    // things a person would most want to see, because they are the two that decide
    // what they get shown.
    expect($export['account']->email)->toBe($user->email)
        ->and($export['taste_profile'])->not->toBeNull()
        ->and($export['calibration_answers'])->toHaveCount(1)
        ->and($export['trips'])->toHaveCount(1)
        ->and($export['explore_sessions'])->toHaveCount(1)
        ->and($export['recommendations'])->toHaveCount(1)
        ->and($export['feedback'])->toHaveCount(1)
        ->and($export['feedback']->first()->event)->toBe('visited')
        ->and($export['privacy_policy_version'])->toBe(config('privacy.version'));
});

it('serves the export as a file the user can keep', function () {
    $user = userWithHistory();

    $this->actingAs($user)
        ->get('/settings/privacy/export')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="travel-companion-export.json"');
});

it('deletes an account completely — every table, verified', function () {
    $user = userWithHistory();
    $otherUser = userWithHistory();   // a bystander, who must survive

    $tables = userScopedTables();
    expect($tables)->not->toBeEmpty();

    $this->actingAs($user)
        ->delete('/settings/privacy/account', ['password' => 'password'])
        ->assertRedirect('/');

    /*
     * Enumerated from the SCHEMA, not from a list I wrote by hand — so this test
     * fails the day someone adds a user-scoped table that forgets to cascade, which
     * is the day you want to know. "The FKs handle it" is true right up until it
     * isn't.
     */
    foreach ($tables as $table) {
        expect(DB::table($table)->where('user_id', $user->id)->count())
            ->toBe(0, "Table [{$table}] still holds data for a deleted user.");
    }

    expect(DB::table('users')->where('id', $user->id)->count())->toBe(0);

    // The feedback ledger goes too. It is the moat and losing it hurts — but it is
    // THEIR moat, and "delete my account" is not a negotiation.
    expect(DB::table('recommendation_feedback')->count())->toBe(1);   // only the bystander's

    // ...and the bystander is untouched. An erasure that takes someone else's data
    // with it is a different kind of incident.
    expect(DB::table('users')->where('id', $otherUser->id)->count())->toBe(1);
});

it('will not delete an account on a wrong password', function () {
    $user = userWithHistory();

    $this->actingAs($user)
        ->delete('/settings/privacy/account', ['password' => 'not-the-password'])
        ->assertSessionHasErrors('password');

    expect(DB::table('users')->where('id', $user->id)->count())->toBe(1);
});

it('lets a user declare, move and forget a home zone', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put('/settings/privacy/home-zone', ['lat' => 59.3103, 'lng' => 18.0227, 'radius_meters' => 300])
        ->assertRedirect();

    $zone = HomeZone::forUser($user->id);
    expect($zone->declared())->toBeTrue()
        ->and($zone->contains(59.3103, 18.0227))->toBeTrue();

    // Forgetting it is as easy as declaring it — a privacy control you cannot undo
    // is a trap, not a control.
    $this->actingAs($user)->delete('/settings/privacy/home-zone')->assertRedirect();

    expect(HomeZone::forUser($user->id)->declared())->toBeFalse();
});

it('refuses an absurd home-zone radius', function () {
    $user = User::factory()->create();

    // A 50 km "home zone" would suppress an entire city, and a user who did that by
    // accident would just see an empty feed forever with no idea why.
    $this->actingAs($user)
        ->put('/settings/privacy/home-zone', ['lat' => 59.3103, 'lng' => 18.0227, 'radius_meters' => 50_000])
        ->assertSessionHasErrors('radius_meters');
});
