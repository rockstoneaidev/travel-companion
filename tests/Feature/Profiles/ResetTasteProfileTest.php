<?php

declare(strict_types=1);

use App\Domain\Feedback\Models\RecommendationFeedback;
use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Profiles\Models\ProfileSignal;
use App\Domain\Profiles\Models\UserTasteProfile;
use App\Domain\Recommendations\Models\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/** Calibration needs explicit consent now (Art. 9(2)(a)) — see ProfilingConsentTest. */
function consentedUser(): User
{
    $user = User::factory()->create();

    test()->actingAs($user);
    test()->post('/calibrate/consent');

    return $user;
}

/*
|--------------------------------------------------------------------------
| S10 — "Reset my taste profile"
|--------------------------------------------------------------------------
|
| The user is telling us we have them wrong. Taking that seriously means going
| back to knowing nothing: α to 0, the honest cold-start vector, no confident
| wrong answers.
|
| "Forget what you concluded about me" — NOT "forget what I did".
|
*/

it('shows what we think we know before offering to throw it away', function () {
    $user = consentedUser();

    $this->post('/calibrate/1', ['side' => 'a']);
    $this->post('/calibrate/practical', ['walk_minutes' => 40, 'price_band' => 3]);

    $this->get('/settings/taste')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/taste')
            ->where('taste.calibrated', true)
            ->where('taste.alpha', 0.4)               // calibrated → α₀ (SCORING §6)
            ->where('taste.walk_tolerance_minutes', 40)
            ->has('taste.leans_toward'));
});

it('takes a user back to knowing nothing — α returns to 0', function () {
    $user = consentedUser();

    $this->post('/calibrate/1', ['side' => 'a']);
    $this->post('/calibrate/practical', ['walk_minutes' => 40, 'price_band' => 3]);

    expect(UserTasteProfile::for($user->id)->isCalibrated())->toBeTrue();

    $this->delete('/settings/taste')->assertRedirect('/welcome');

    $profile = UserTasteProfile::for($user->id);

    // Back to the cold-start vector: honest "I don't know you yet" ranking, not a
    // confident wrong one.
    expect($profile->isCalibrated())->toBeFalse()
        ->and($profile->facet_weights)->toBe([])
        ->and($profile->event_counts)->toBe([])
        // ...and rebuilt with the documented defaults, not zeroed — a zeroed walk
        // tolerance maxes friction on every candidate.
        ->and($profile->walk_tolerance_minutes)->toBe(15)
        ->and($profile->price_band)->toBe(2);

    // The calibration answers go too, or re-taking it would resume at the end and
    // teach nothing.
    expect(ProfileSignal::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('forgets what it concluded about you, not what you did', function () {
    $user = consentedUser();

    $opportunity = Opportunity::factory()->create(['status' => OpportunityStatus::Served]);
    $recommendation = Recommendation::query()->create([
        'user_id' => $user->id,
        'opportunity_id' => $opportunity->id,
        'position' => 1,
        'scores' => [],
        'score_inputs' => ['candidate' => ['name' => 'Färgfabriken', 'lat' => 59.31, 'lng' => 18.02, 'facets' => ['history']]],
        'scoring_model_version' => 'v1',
        'taxonomy_version' => 1,
        'served_at' => now(),
    ]);

    $this->post("/recommendations/{$recommendation->id}/feedback", ['event' => 'visited']);

    $this->delete('/settings/taste');

    // The ledger is the moat (PRD §14.5) and a record of what actually happened.
    // They DID go there. Un-tapping history is not what they asked for — and a
    // future profile_model version can rebuild a better profile from these very
    // events, which is the whole reason the ledger is append-only.
    expect(RecommendationFeedback::query()->where('recommendation_id', $recommendation->id)->count())->toBe(1);
});

it('does not reset one traveller from another traveller\'s settings', function () {
    $mine = consentedUser();
    $this->post('/calibrate/1', ['side' => 'a']);
    $this->post('/calibrate/practical', ['walk_minutes' => 40, 'price_band' => 3]);

    $this->actingAs(User::factory()->create());
    $this->delete('/settings/taste');

    // Their reset is theirs. Mine stands.
    expect(UserTasteProfile::for($mine->id)->isCalibrated())->toBeTrue();
});
