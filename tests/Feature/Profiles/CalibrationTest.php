<?php

declare(strict_types=1);

use App\Domain\Profiles\Models\ProfileSignal;
use App\Domain\Profiles\Models\UserTasteProfile;
use App\Domain\Profiles\Queries\CalibrationProgress;
use App\Domain\Profiles\Services\CalibrationContent;
use App\Domain\Recommendations\Data\ScoringModel;
use App\Domain\Recommendations\Services\CompositeScorer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * Calibration is gated on explicit consent now (Art. 9(2)(a), DPIA §3.2) — the nine
 * pairs are the most concentrated profiling this product does, and one of the facets
 * they separate is `spiritual`. So these tests consent first, exactly as a real user
 * must. ProfilingConsentTest is the one that proves what happens when they don't.
 */
function consenting(): User
{
    $user = User::factory()->create();

    test()->actingAs($user);
    test()->post('/calibrate/consent');

    return $user;
}

/*
|--------------------------------------------------------------------------
| S9 — onboarding taste calibration (ONBOARDING.md, PRD §13.2)
|--------------------------------------------------------------------------
|
| This is the step that changes what a person is SHOWN. Until it lands, α is 0
| and everyone gets the pure cold vector — uniqueness weighted .35, personal_fit
| weighted .06. Afterwards their own answers carry a third of the ranking.
|
*/

it('serves the pairs from the backend, and never the answer key', function () {
    $user = consenting();

    $response = $this->actingAs($user)->get('/calibrate/1');

    $response->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('calibrate/pair')
            ->where('pair.number', 1)
            ->where('total', 9)
            ->has('pair.a.caption')
            ->has('pair.b.caption'),
    );

    // The facet vectors are the answer key. A user who can see that one card is
    // "offbeat" stops telling us their taste and starts telling us what they want
    // us to think.
    $props = $response->viewData('page')['props'];

    expect(json_encode($props))->not->toContain('offbeat')
        ->and(json_encode($props))->not->toContain('facets');
});

it('moves the chosen facets up and the rejected ones down', function () {
    $user = consenting();

    // Pair 1: the chapel (spiritual/architecture/history/offbeat) against the
    // grand museum (art/educational).
    $this->actingAs($user)->post('/calibrate/1', ['side' => 'a'])->assertRedirect('/calibrate/2');

    $profile = UserTasteProfile::query()->where('user_id', $user->id)->sole();

    // Chosen: 0.5 + 0.20 × (1 − 0.5) = 0.60. Rejected: 0.5 + 0.10 × (0 − 0.5) = 0.45.
    expect($profile->facet_weights['spiritual'])->toBe(0.6)
        ->and($profile->facet_weights['offbeat'])->toBe(0.6)
        ->and($profile->facet_weights['art'])->toBe(0.45)
        ->and($profile->facet_weights['educational'])->toBe(0.45);
});

it('records a skip without teaching anything from it', function () {
    $user = consenting();

    $this->actingAs($user)->post('/calibrate/1', ['side' => null])->assertRedirect('/calibrate/2');

    // The row exists — "shown it and declined" is a different fact from "never
    // shown it", and only the second means the flow is unfinished.
    $signal = ProfileSignal::query()->where('user_id', $user->id)->sole();

    expect($signal->pair_number)->toBe(1)
        ->and($signal->chosen_side)->toBeNull()
        ->and($signal->chosen_facets)->toBe([]);

    // ...but no weight moved.
    $profile = UserTasteProfile::query()->where('user_id', $user->id)->first();
    expect($profile?->facet_weights ?? [])->toBe([]);
});

it('resumes at the next unanswered pair after the app is killed mid-flow', function () {
    $user = consenting();

    $this->actingAs($user)->post('/calibrate/1', ['side' => 'a']);
    $this->actingAs($user)->post('/calibrate/2', ['side' => 'b']);
    $this->actingAs($user)->post('/calibrate/3', ['side' => null]);   // a skip still counts as answered

    $progress = app(CalibrationProgress::class);

    expect($progress->nextPairNumber($user->id))->toBe(4);
});

it('sends you to the practicals after the last pair', function () {
    $user = consenting();
    $last = app(CalibrationContent::class)->count();

    $this->actingAs($user)
        ->post("/calibrate/{$last}", ['side' => 'a'])
        ->assertRedirect('/calibrate/practical');
});

it('seeds friction from the practicals — never taste', function () {
    $user = consenting();

    $this->actingAs($user)->post('/calibrate/1', ['side' => 'a']);
    $this->actingAs($user)
        ->post('/calibrate/practical', ['walk_minutes' => 40, 'price_band' => 3])
        ->assertRedirect('/explore');

    $profile = UserTasteProfile::query()->where('user_id', $user->id)->sole();

    expect($profile->walk_tolerance_minutes)->toBe(40)
        ->and($profile->price_band)->toBe(3)
        // How far you will walk is not something you LIKE. If this leaked into the
        // facet weights it would quietly corrupt every recommendation.
        ->and(array_keys($profile->facet_weights))->not->toContain('walk_tolerance_minutes');
});

it('lifts a calibrated user out of cold start — this is the whole point', function () {
    $scorer = new CompositeScorer(ScoringModel::v1());

    // A brand-new user with no history at all.
    $cold = $scorer->alpha([], calibrated: false);
    $calibrated = $scorer->alpha([], calibrated: true);

    // α = 0 means the COLD weights: uniqueness .35, personal_fit .06. The user's
    // own taste barely counts, which is correct when we know nothing about them.
    expect($cold)->toBe(0.0)
        // α₀ = 0.4 after calibration (SCORING §6): their answers now carry weight.
        ->and($calibrated)->toBe(0.4);
});

it('does not grant α₀ to someone who skipped every pair', function () {
    $user = consenting();

    foreach (range(1, app(CalibrationContent::class)->count()) as $number) {
        $this->actingAs($user)->post("/calibrate/{$number}", ['side' => null]);
    }

    $this->actingAs($user)->post('/calibrate/practical', ['walk_minutes' => 20, 'price_band' => 2]);

    $profile = UserTasteProfile::query()->where('user_id', $user->id)->sole();

    // They told us nothing. Pretending otherwise would weight a taste vector that
    // is still entirely the 0.5 default — a confident guess built on air.
    expect($profile->calibration_completed_at)->toBeNull()
        ->and($profile->isCalibrated())->toBeFalse()
        // The practicals still stand: they are not taste, and they were answered.
        ->and($profile->walk_tolerance_minutes)->toBe(20);
});

it('does not make a calibrated user sit through it again', function () {
    $user = consenting();

    $this->actingAs($user)->post('/calibrate/1', ['side' => 'a']);
    $this->actingAs($user)->post('/calibrate/practical', ['walk_minutes' => 20, 'price_band' => 2]);

    $this->actingAs($user)->get('/welcome')->assertRedirect('/explore');
});
