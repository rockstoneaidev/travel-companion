<?php

declare(strict_types=1);

use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Profiles\Models\UserTasteProfile;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Recommendations\Queries\ListDismissedForUser;
use App\Domain\Recommendations\Queries\ListKeptForUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Keeping and dismissing are opposite verdicts (SCREENS S6)
|--------------------------------------------------------------------------
|
| The ledger holds keep and dismiss as two independent latest-wins pairs, and for a
| while nothing reconciled them. Dismiss something you had kept and BOTH stayed live:
| the KEPT screen then listed the same place under "Still possible" AND under "Not for
| me", which is the product saying two contradictory things about what it thinks you
| want, on one screen, at the same time. It shipped, and it was found on a phone.
|
| The rule is that the later verdict retracts the earlier one — by APPENDING the
| retraction, never by deleting anything. The stream is the moat (PRD §14.5), and
| "changed their mind" is itself worth keeping.
|
*/

/** A served recommendation for $user, with facets the learner can actually move. */
function verdictFixture(User $user, string $name = 'Lumières Kinematograf'): string
{
    $opportunity = Opportunity::factory()->create([
        'status' => OpportunityStatus::Served,
        'title' => $name,
        'expires_at' => now()->addDay(),
        'window_ends_at' => null,
    ]);

    return Recommendation::query()->create([
        'user_id' => $user->id,
        'opportunity_id' => $opportunity->id,
        'position' => 1,
        'scores' => [],
        'score_inputs' => ['candidate' => ['name' => $name, 'lat' => 59.31, 'lng' => 18.02, 'facets' => ['history']]],
        'scoring_model_version' => 'v1',
        'taxonomy_version' => 1,
        'served_at' => now(),
    ])->id;
}

it('takes a dismissed item out of KEPT — it cannot be both still possible and not for me', function () {
    $this->actingAs($user = profilingConsent(User::factory()->create()));

    $id = verdictFixture($user);

    $this->post("/recommendations/{$id}/feedback", ['event' => 'saved']);
    $this->post("/recommendations/{$id}/feedback", ['event' => 'dismissed']);

    expect(app(ListKeptForUser::class)->forUser($user->id))->toHaveCount(0)
        ->and(app(ListDismissedForUser::class)->forUser($user->id))->toHaveCount(1);

    // The screen itself — this is the bug as the user met it.
    $this->get('/kept')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('kept.data', 0)
            ->has('dismissed.data', 1));
});

it('takes a kept item out of "Not for me" — the same rule, the other way round', function () {
    $this->actingAs($user = profilingConsent(User::factory()->create()));

    $id = verdictFixture($user);

    $this->post("/recommendations/{$id}/feedback", ['event' => 'dismissed']);
    $this->post("/recommendations/{$id}/feedback", ['event' => 'saved']);

    expect(app(ListDismissedForUser::class)->forUser($user->id))->toHaveCount(0)
        ->and(app(ListKeptForUser::class)->forUser($user->id))->toHaveCount(1);
});

it('retracts by appending, never by deleting — the keep and the dismissal both survive', function () {
    $this->actingAs($user = profilingConsent(User::factory()->create()));

    $id = verdictFixture($user);

    $this->post("/recommendations/{$id}/feedback", ['event' => 'saved']);
    $this->post("/recommendations/{$id}/feedback", ['event' => 'dismissed']);

    $events = DB::table('recommendation_feedback')
        ->where('recommendation_id', $id)
        ->orderBy('occurred_at')
        ->orderBy('id')
        ->pluck('event')
        ->all();

    // The retraction is written BEFORE the event that caused it, so latest-wins reads
    // `unsaved` as the live keep-state and `dismissed` as the live dismiss-state.
    expect($events)->toBe(['saved', 'unsaved', 'dismissed']);
});

it('un-teaches what the dismissal taught when you change your mind and keep it', function () {
    $this->actingAs($user = profilingConsent(User::factory()->create()));

    $id = verdictFixture($user);

    $before = UserTasteProfile::for($user->id)->facet_weights['history'] ?? 0.5;

    $this->post("/recommendations/{$id}/feedback", ['event' => 'dismissed']);
    $afterDismiss = UserTasteProfile::query()->where('user_id', $user->id)->sole()->facet_weights['history'];

    // "fewer like this" moved the weight down. Keeping it now says the opposite.
    expect($afterDismiss)->toBeLessThan($before);

    $this->post("/recommendations/{$id}/feedback", ['event' => 'saved']);
    $afterKeep = UserTasteProfile::query()->where('user_id', $user->id)->sole()->facet_weights['history'];

    // The keep routes an `undismissed` through the learner first, which runs it BACKWARDS
    // (FacetWeightLearner::retract) — so the dismissal's lesson is undone rather than
    // merely out-voted, and the keep's own lesson then lands on a clean weight.
    expect($afterKeep)->toBeGreaterThan($afterDismiss);
});

it('leaves an ordinary dismissal alone — nothing to retract, nothing appended', function () {
    $this->actingAs($user = profilingConsent(User::factory()->create()));

    $id = verdictFixture($user);

    $this->post("/recommendations/{$id}/feedback", ['event' => 'dismissed']);

    expect(DB::table('recommendation_feedback')->where('recommendation_id', $id)->pluck('event')->all())
        ->toBe(['dismissed']);
});

it('repairs the contradictions already written to the ledger', function () {
    $this->actingAs($user = profilingConsent(User::factory()->create()));

    $id = verdictFixture($user);

    // Exactly the state the old code left behind: a live keep and a live dismissal,
    // written straight to the table so RecordFeedback's new rule cannot pre-empt it.
    DB::table('recommendation_feedback')->insert([
        ['recommendation_id' => $id, 'event' => 'saved', 'metadata' => '{}', 'occurred_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()],
        ['recommendation_id' => $id, 'event' => 'dismissed', 'metadata' => '{}', 'occurred_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()],
    ]);

    expect(app(ListKeptForUser::class)->forUser($user->id))->toHaveCount(1)
        ->and(app(ListDismissedForUser::class)->forUser($user->id))->toHaveCount(1);

    $this->artisan('migrate:refresh', ['--path' => 'database/migrations/2026_07_15_040000_retract_contradictory_keeps_and_dismissals.php']);

    // The dismissal came last, so the keep is the verdict that gets retracted.
    expect(app(ListKeptForUser::class)->forUser($user->id))->toHaveCount(0)
        ->and(app(ListDismissedForUser::class)->forUser($user->id))->toHaveCount(1);
});
