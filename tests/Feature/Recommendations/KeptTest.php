<?php

declare(strict_types=1);

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Opportunities\Enums\OpportunityKind;
use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Profiles\Models\UserTasteProfile;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Recommendations\Queries\ListKeptForUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| S6 — KEPT (SCREENS.md)
|--------------------------------------------------------------------------
|
| Two questions, and they are different: did you keep this, and can you still
| do it. The screen exists because the second answer changes while you aren't
| looking.
|
*/

/** A served recommendation for $user, pointing at an opportunity of $kind with $windowEndsAt. */
function keptFixture(
    User $user,
    ?string $windowEndsAt,
    string $name = 'Färgfabriken',
    OpportunityKind $kind = OpportunityKind::Evergreen,
): string {
    $opportunity = Opportunity::factory()->create([
        'status' => OpportunityStatus::Served,
        'title' => $name,
        'summary' => 'An exhibition hall in an old paint factory.',
        'kind' => $kind,
        'window_ends_at' => $windowEndsAt,
        'expires_at' => now()->addDay(),
    ]);

    $recommendation = Recommendation::query()->create([
        'user_id' => $user->id,
        'opportunity_id' => $opportunity->id,
        'position' => 1,
        'scores' => [],
        'score_inputs' => [
            'candidate' => ['name' => $name, 'lat' => 59.31, 'lng' => 18.02, 'facets' => ['history']],
            // Mirror what RankSession::persist() records durably, so the row can answer
            // "evergreen place or dated moment?" after the opportunity has been reaped.
            'opportunity' => ['kind' => $kind->value, 'window_ends_at' => $windowEndsAt],
        ],
        'scoring_model_version' => 'v1',
        'taxonomy_version' => 1,
        'served_at' => now(),
    ]);

    return $recommendation->id;
}

it('splits what you can still do from what has quietly passed', function () {
    $this->actingAs($user = profilingConsent(User::factory()->create()));

    $open = keptFixture($user, now()->addHours(3)->toDateTimeString(), 'Färgfabriken', OpportunityKind::Event);
    $closed = keptFixture($user, now()->subHour()->toDateTimeString(), 'Midsummer concert', OpportunityKind::Event);
    $timeless = keptFixture($user, null, 'Vitabergsparken', OpportunityKind::Evergreen);

    foreach ([$open, $closed, $timeless] as $id) {
        $this->post("/recommendations/{$id}/feedback", ['event' => 'saved']);
    }

    $this->get('/kept')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('kept')
            ->has('kept.data', 3));

    $kept = collect(app(ListKeptForUser::class)->forUser($user->id))->keyBy('title');

    // The window was open when it was kept, and it is not open now. The keep does
    // not get to vouch for it.
    expect($kept['Färgfabriken']->stillPossible)->toBeTrue()
        ->and($kept['Midsummer concert']->stillPossible)->toBeFalse()
        // No window at all is not the same as a closed one — a park is always open.
        ->and($kept['Vitabergsparken']->stillPossible)->toBeTrue();
});

it('retracts a keep without deleting it — the ledger is append-only', function () {
    $this->actingAs($user = profilingConsent(User::factory()->create()));

    $id = keptFixture($user, null);

    $this->post("/recommendations/{$id}/feedback", ['event' => 'saved']);
    expect(app(ListKeptForUser::class)->forUser($user->id))->toHaveCount(1);

    $this->post("/recommendations/{$id}/feedback", ['event' => 'unsaved']);
    expect(app(ListKeptForUser::class)->forUser($user->id))->toHaveCount(0);

    // The keep is gone from the screen, not from the moat (PRD §14.5).
    expect(DB::table('recommendation_feedback')->where('recommendation_id', $id)->count())->toBe(2);
});

it('keeps it again after a removal — latest event wins', function () {
    $this->actingAs($user = profilingConsent(User::factory()->create()));

    $id = keptFixture($user, null);

    $this->post("/recommendations/{$id}/feedback", ['event' => 'saved']);
    $this->post("/recommendations/{$id}/feedback", ['event' => 'unsaved']);
    $this->post("/recommendations/{$id}/feedback", ['event' => 'saved']);

    expect(app(ListKeptForUser::class)->forUser($user->id))->toHaveCount(1);
});

it('does not teach the taste profile anything when you tidy the list', function () {
    $this->actingAs($user = profilingConsent(User::factory()->create()));

    $id = keptFixture($user, null);

    $this->post("/recommendations/{$id}/feedback", ['event' => 'saved']);
    $afterKeep = UserTasteProfile::query()->where('user_id', $user->id)->sole()->facet_weights;

    $this->post("/recommendations/{$id}/feedback", ['event' => 'unsaved']);
    $afterRemove = UserTasteProfile::query()->where('user_id', $user->id)->sole()->facet_weights;

    // The commonest reason to remove a kept item is that you already went. Teaching
    // "fewer like this" from that would punish the item for being acted on.
    expect($afterRemove)->toBe($afterKeep)
        ->and(FeedbackEvent::Unsaved->teachesTaste())->toBeFalse();
});

it('keeps an evergreen place takeable after its opportunity is reaped', function () {
    $this->actingAs($user = profilingConsent(User::factory()->create()));

    $id = keptFixture($user, null, 'Färgfabriken', OpportunityKind::Evergreen);
    $this->post("/recommendations/{$id}/feedback", ['event' => 'saved']);

    // Opportunities are ephemeral and TTL'd (PRD §14). Recommendations are the permanent
    // decision trace (§15) and feedback is the moat (§14.5) — reaping the first must not
    // take the other two with it (it used to: the FK cascaded), AND it must not turn a
    // place you can walk to any day for years into a greyed-out "window gone". A keep is
    // a keep of a PLACE; the opportunity row disappearing is housekeeping, not closure.
    DB::table('opportunities')->delete();

    expect(DB::table('recommendations')->where('id', $id)->count())->toBe(1)
        ->and(DB::table('recommendation_feedback')->where('recommendation_id', $id)->count())->toBe(1);

    $kept = app(ListKeptForUser::class)->forUser($user->id);

    // The row renders from the recommendation's own snapshot, and it is STILL takeable —
    // an evergreen place does not expire because its ephemeral opportunity did.
    expect($kept)->toHaveCount(1)
        ->and($kept[0]->title)->toBe('Färgfabriken')
        ->and($kept[0]->stillPossible)->toBeTrue()
        ->and($kept[0]->windowEndsAt)->toBeNull();
});

it('still marks a dated keep gone once its window has passed, even after reaping', function () {
    $this->actingAs($user = profilingConsent(User::factory()->create()));

    // A genuinely dated moment — an event whose window closed an hour ago.
    $id = keptFixture($user, now()->subHour()->toDateTimeString(), 'Midsummer concert', OpportunityKind::Event);
    $this->post("/recommendations/{$id}/feedback", ['event' => 'saved']);

    DB::table('opportunities')->delete();

    $kept = app(ListKeptForUser::class)->forUser($user->id);

    // The durable snapshot remembers this was an EVENT with a past window, so — unlike an
    // evergreen place — it really is gone, and the screen says so honestly.
    expect($kept)->toHaveCount(1)
        ->and($kept[0]->stillPossible)->toBeFalse();
});

it('shows one traveller nothing of another traveller\'s keeps', function () {
    $this->actingAs($mine = profilingConsent(User::factory()->create()));
    $id = keptFixture($mine, null);
    $this->post("/recommendations/{$id}/feedback", ['event' => 'saved']);

    $this->actingAs(profilingConsent(User::factory()->create()));

    $this->get('/kept')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('kept.data', 0));
});
