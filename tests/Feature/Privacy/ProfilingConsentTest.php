<?php

declare(strict_types=1);

use App\Domain\Privacy\Actions\SetProfilingConsent;
use App\Domain\Privacy\Contracts\ProfilingConsent;
use App\Domain\Profiles\Models\UserTasteProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Explicit consent to be profiled (GDPR Art. 9(2)(a), DPIA §3.2)
|--------------------------------------------------------------------------
|
| We never ASK for special-category data. But the taxonomy has a
| `religious_sacred` domain and a `spiritual` facet, and the profile learns a
| weight for them — so a person who keeps choosing chapels ends up with a vector
| that is, in substance, an inferred statement about their religious belief.
|
| The CJEU has held that data from which special-category data can be INDIRECTLY
| DEDUCED falls under Art. 9 (C-184/20, OT v Vyriausybinė). Art. 6 consent does
| not cover it; Art. 9(2)(a) explicit consent does.
|
| The gate lives in the LEARNER, which is the one place a weight can move — so a
| caller added next year cannot quietly re-open the hole.
|
*/

it('learns nothing at all without explicit consent', function () {
    $this->actingAs($user = User::factory()->create());

    // Nine forced-choice pairs, answered in full. Every one of them a facet signal.
    foreach (range(1, 9) as $number) {
        $this->post("/calibrate/{$number}", ['side' => 'a']);
    }
    $this->post('/calibrate/practical', ['walk_minutes' => 40, 'price_band' => 3]);

    $profile = UserTasteProfile::for($user->id);

    // Not one weight moved. The user is not profiled, and the product still works —
    // they get the honest cold-start ranking (α = 0, SCORING §6) instead of a
    // confident guess we had no right to make.
    expect($profile->facet_weights)->toBe([])
        ->and($profile->isCalibrated())->toBeFalse();
});

it('will not even ask the nine questions without consent', function () {
    $this->actingAs(User::factory()->create());

    // Nobody should be asked nine personal questions whose answers we would then have
    // to throw away.
    $this->get('/calibrate/1')->assertRedirect('/welcome');
});

it('learns once consent is explicitly given', function () {
    $this->actingAs($user = User::factory()->create());

    $this->post('/calibrate/consent')->assertRedirect('/calibrate/1');

    $this->post('/calibrate/1', ['side' => 'a']);

    $profile = UserTasteProfile::for($user->id);

    // Pair 1 is the chapel (spiritual/architecture/history/offbeat) against the grand
    // museum. THIS is the weight that makes it Art. 9 data — and it only exists
    // because the user affirmatively said yes.
    expect($profile->facet_weights['spiritual'])->toBe(0.6);
});

it('records WHAT was consented to, not merely that something was', function () {
    $this->actingAs($user = User::factory()->create());

    app(SetProfilingConsent::class)->grant($user->id);

    expect(app(ProfilingConsent::class)->granted($user->id))->toBeTrue();

    // The thing consented TO can change. If the profile ever infers more than it does
    // today, the old agreement does not cover the new thing — and a consent that
    // silently stretches to cover whatever we build next is not consent.
    config()->set('privacy.profiling_consent_version', 'v2');

    expect(app(ProfilingConsent::class)->granted($user->id))->toBeFalse();
});

it('deletes the profile when consent is withdrawn — not just the learning', function () {
    $this->actingAs($user = User::factory()->create());

    $this->post('/calibrate/consent');
    $this->post('/calibrate/1', ['side' => 'a']);
    $this->post('/calibrate/practical', ['walk_minutes' => 40, 'price_band' => 3]);

    expect(UserTasteProfile::for($user->id)->facet_weights)->not->toBe([]);

    $this->put('/settings/privacy/profiling-consent', ['consent' => false])->assertRedirect();

    /*
     * The conclusions go with the permission to have drawn them.
     *
     * "Stop learning but keep what you inferred" would leave us HOLDING a vector from
     * which someone's religious belief can be deduced, with no lawful basis at all —
     * and holding it is itself processing. That is a worse position than never having
     * asked.
     */
    $profile = UserTasteProfile::for($user->id);

    expect(app(ProfilingConsent::class)->granted($user->id))->toBeFalse()
        ->and($profile->facet_weights)->toBe([])
        ->and($profile->isCalibrated())->toBeFalse();
});

it('makes withdrawal exactly as easy as consent — one click, no password', function () {
    $this->actingAs($user = User::factory()->create());

    app(SetProfilingConsent::class)->grant($user->id);

    // Art. 7(3): as easy to withdraw as to give. No password, no confirmation step, no
    // "are you sure you want to lose your personalised experience".
    $this->put('/settings/privacy/profiling-consent', ['consent' => false])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(DB::table('users')->where('id', $user->id)->value('profiling_consent_at'))->toBeNull();
});

it('still serves a feed to someone who refuses — refusal is not a broken state', function () {
    $this->actingAs(User::factory()->create());

    $this->post('/calibrate/decline');

    // The whole reason the cold-start vector exists (SCORING §6). Refusing to be
    // profiled must cost you personalisation, not the product — and it must not leave
    // you bouncing off a consent screen you already answered.
    $this->get('/explore')->assertOk();
    $this->get('/dashboard')->assertOk();
    $this->get('/welcome')->assertOk();
});

it('stores none of the ANSWERS without consent, not just none of the conclusions', function () {
    $this->actingAs($user = User::factory()->create());

    foreach (range(1, 9) as $number) {
        $this->post("/calibrate/{$number}", ['side' => 'a']);
    }

    /*
     * The hole this closes: I gated the SCREEN and not the POST, so the answers were
     * still landing in `profile_signals`.
     *
     * Those rows are not metadata about the profiling — they ARE the sensitive data:
     * "this person chose the chapel over the grand museum", nine times over, which is
     * the raw material the Art. 9 inference is made of. Storing them without consent
     * is the same violation as learning from them, and a test that only checked the
     * facet weights would have sailed straight past it.
     */
    expect(DB::table('profile_signals')->where('user_id', $user->id)->count())->toBe(0);
});

it('asks an existing user once, on the way in', function () {
    $this->actingAs(User::factory()->create());

    // Accounts created before consent existed have never been asked — and until they
    // are, their profile silently stops learning. They deserve the question.
    $this->get('/dashboard')->assertRedirect('/welcome');
    $this->get('/explore')->assertRedirect('/welcome');
});

it('never asks again once they have answered — either way', function () {
    $this->actingAs($user = User::factory()->create());

    $this->post('/calibrate/decline')->assertRedirect('/explore');

    /*
     * "No" is a complete answer.
     *
     * Sending someone back to the consent screen until they agree is not consent, it
     * is attrition — and consent extracted that way is not FREELY GIVEN (Art. 4(11)),
     * which makes it no consent at all. The one thing worse than not asking is asking
     * until you get the answer you wanted.
     */
    $this->get('/dashboard')->assertOk();
    $this->get('/explore')->assertOk();

    expect(app(ProfilingConsent::class)->granted($user->id))->toBeFalse();
});

it('lets someone who declined turn it on later, on their own initiative', function () {
    $this->actingAs($user = User::factory()->create());

    $this->post('/calibrate/decline');

    // In Settings → Privacy, unprompted. That is the only version of "yes" worth
    // having, and it is why we can afford to stop asking.
    $this->put('/settings/privacy/profiling-consent', ['consent' => true])->assertRedirect();

    expect(app(ProfilingConsent::class)->granted($user->id))->toBeTrue();
});

it('does not trap a calibrated-but-never-asked user in a redirect loop', function () {
    /*
     * ERR_TOO_MANY_REDIRECTS, and it was mine.
     *
     * /welcome sent anyone with a finished calibration on to /explore, and the
     * ask-once middleware sent them straight back, because they had never been ASKED
     * about consent. Round and round.
     *
     * This is not an edge case: it is EXACTLY the existing pilot accounts, calibrated
     * long before consent existed. The bug hit the only users there are.
     *
     * "Finished calibration" and "answered the consent question" are different facts.
     * Treating them as one is what made the loop.
     */
    $this->actingAs($user = profilingConsent(User::factory()->create()));

    // Calibrate fully, then wind consent back to "never asked" — the state every
    // existing account was in the moment the gate shipped.
    foreach (range(1, 9) as $number) {
        $this->post("/calibrate/{$number}", ['side' => 'a']);
    }
    $this->post('/calibrate/practical', ['walk_minutes' => 20, 'price_band' => 2]);

    DB::table('users')->where('id', $user->id)->update([
        'profiling_consent_at' => null,
        'profiling_consent_version' => null,
        'profiling_consent_asked_at' => null,
    ]);

    // The question gets asked — and asking it does not bounce them straight out again.
    $this->get('/explore')->assertRedirect('/welcome');
    $this->get('/welcome')->assertOk();

    // Answering it lets them through.
    $this->post('/calibrate/consent')->assertRedirect('/calibrate/1');
    $this->get('/explore')->assertOk();
});
