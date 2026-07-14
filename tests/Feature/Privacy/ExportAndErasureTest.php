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

    // Calibration, taste, a served recommendation, and feedback on it. Consent first —
    // the nine pairs are gated on it now (Art. 9(2)(a), DPIA §3.2).
    test()->actingAs($user)->post('/calibrate/consent');
    test()->post('/calibrate/1', ['side' => 'a']);
    test()->post('/calibrate/practical', ['travel_minutes' => 40, 'price_band' => 3]);

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

/*
|--------------------------------------------------------------------------
| The tables the enumeration above cannot see (ROPA §7.2, finding B7)
|--------------------------------------------------------------------------
|
| `userScopedTables()` reads information_schema for columns literally named
| `user_id`. That is a good test, and it was blind in exactly the place the
| bug was: `activity_log` keys users through a polymorphic morph, and Pulse
| stores the user id inside a string `key`. Neither has a `user_id` column,
| so neither was ever in the enumeration — and neither was reached by any FK
| cascade. Admin audit rows and per-user telemetry survived erasure.
|
| These assertions are explicit because they HAVE to be: there is no schema
| signal to enumerate them by. That is precisely why they were missed.
|
*/

it('detaches the person from the cost ledger without erasing the money', function () {
    // The one table in this schema that deliberately does NOT cascade from `users`
    // (COST.md §10). A cascade here would mean an erasure request could delete the
    // accounting — "forget me" must not be able to rewrite the P&L. So the person is
    // nulled out and the money stays exactly where it is.
    //
    // Note this is the OPPOSITE resolution to finding B7, and the difference is the
    // point: `activity_log` had no user column and no lawful basis to keep the row, so
    // the row goes. Here the row has a lawful basis of its own (billing integrity,
    // legitimate interest) and a `user_id` column that erasure can empty. Same
    // principle — keep no more of the person than you can justify — opposite mechanics.
    $user = userWithHistory();

    DB::table('cost_events')->insert([
        'occurred_at' => now(),
        'actor_kind' => 'user',
        'category' => 'llm',
        'vendor' => 'gemini',
        'resource' => 'gemini-3.1-flash-lite',
        'user_id' => $user->id,
        'h3_cell' => '881f1d4881fffff',
        'input_tokens' => 1_500,
        'output_tokens' => 150,
        'billed_usd_micros' => 600,
        'would_have_billed_usd_micros' => 600,
        'price_version' => '2026-07',
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->delete('/settings/privacy/account', ['password' => 'password'])
        ->assertRedirect('/');

    // The person is gone from the row...
    expect(DB::table('cost_events')->whereNotNull('user_id')->count())
        ->toBe(0, 'The cost ledger still names a user who asked to be forgotten.');

    expect(DB::table('cost_events')->whereNotNull('h3_cell')->count())
        ->toBe(0, 'A location survived erasure in the cost ledger.');

    // ...and the money is still there. Both halves matter; either one alone is a bug.
    expect((int) DB::table('cost_events')->sum('billed_usd_micros'))
        ->toBe(600, 'Erasure destroyed the accounting.');
});

it('erases admin audit rows naming the user — on both ends of the morph', function () {
    $admin = User::factory()->create();
    $target = userWithHistory();

    // The user as SUBJECT: "a role was granted to this person."
    activity()->causedBy($admin)->performedOn($target)->log('roles synced');

    // The user as CAUSER: "this person granted a role." Personal data about THEM,
    // and the row people forget, because the leaving account is the admin.
    activity()->causedBy($target)->performedOn($admin)->log('roles synced');

    expect(DB::table('activity_log')->count())->toBe(2);

    $this->actingAs($target)
        ->delete('/settings/privacy/account', ['password' => 'password'])
        ->assertRedirect('/');

    expect(DB::table('activity_log')->count())
        ->toBe(0, 'An audit row still names a user who asked to be forgotten.');
});

it('erases per-user telemetry, which Pulse keys by id inside a string', function () {
    $user = userWithHistory();
    $otherUser = userWithHistory();

    foreach ([$user, $otherUser] as $u) {
        DB::table('pulse_entries')->insert([
            'timestamp' => now()->timestamp,
            'type' => 'user_request',
            'key' => (string) $u->id,
            'value' => 1,
        ]);
    }

    $this->actingAs($user)
        ->delete('/settings/privacy/account', ['password' => 'password'])
        ->assertRedirect('/');

    expect(DB::table('pulse_entries')->where('key', (string) $user->id)->count())
        ->toBe(0, 'Telemetry still keyed to a deleted user.')
        // Pulse trims after 7 days anyway, so this was a lag rather than a leak —
        // but "your data is gone, apart from the bit that expires next Tuesday" is
        // not what the notice says, and the notice is the promise.
        ->and(DB::table('pulse_entries')->where('key', (string) $otherUser->id)->count())
        ->toBe(1, 'The bystander lost their telemetry too.');
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
