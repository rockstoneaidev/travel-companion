<?php

declare(strict_types=1);

use App\Domain\Context\Enums\MovementMode;
use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Feedback\Services\FeedbackLedger;
use App\Domain\Notifications\Actions\ConsiderNotification;
use App\Domain\Notifications\Data\InterruptionContext;
use App\Domain\Notifications\Data\NotificationCandidate;
use App\Domain\Notifications\Enums\NotificationGate;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationPolicy;
use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E30 — the notification decision engine
|--------------------------------------------------------------------------
|
| NON-NEGOTIABLE #4: the LLM never decides when to interrupt. `NotificationPolicy` is
| that sentence, made executable — plain deterministic PHP, no I/O, versioned. A model may
| write the WORDS of a push; it may not choose the MOMENT.
|
| PRD risk 5, stated plainly: notification fatigue destroys the product's core promise. The
| promise is that when this thing speaks, it is worth hearing. One bad push costs more than
| ten good ones earn, and every gate below exists because of that arithmetic.
|
*/

function tripInMode(User $user): Trip
{
    return Trip::factory()->create([
        'user_id' => $user->id,
        'trip_mode_started_at' => now()->subHour(),
    ]);
}

function candidate(User $user, array $overrides = []): NotificationCandidate
{
    $opportunity = Opportunity::factory()->create([
        'status' => OpportunityStatus::Served,
        'title' => $overrides['title'] ?? 'Söderhallarna market',
        'expires_at' => now()->addDay(),
    ]);

    $recommendation = Recommendation::query()->create([
        'user_id' => $user->id,
        'opportunity_id' => $opportunity->id,
        'position' => 1,
        'scores' => [],
        'score_inputs' => ['candidate' => ['name' => 'Söderhallarna', 'type_domain' => $overrides['type_domain'] ?? 'food_drink']],
        'scoring_model_version' => 'v1',
        'taxonomy_version' => 1,
        'served_at' => now(),
    ]);

    return new NotificationCandidate(
        recommendationId: $recommendation->id,
        opportunityId: $opportunity->id,
        title: 'Söderhallarna market',
        confidence: $overrides['confidence'] ?? 0.9,
        urgency: $overrides['urgency'] ?? 0.9,
        personalFit: $overrides['personal_fit'] ?? 0.8,
        uniqueness: $overrides['uniqueness'] ?? 0.6,
        composite: $overrides['composite'] ?? 0.7,
        detourMinutes: $overrides['detour'] ?? 8,
        openNow: $overrides['open_now'] ?? true,
        windowEndsAt: $overrides['window_ends_at'] ?? CarbonImmutable::now()->addMinutes(30),
        evidenceAgeDays: $overrides['evidence_age'] ?? 1.0,
        typeDomain: $overrides['type_domain'] ?? 'food_drink',
        pushable: $overrides['pushable'] ?? true,
    );
}

/** Midday, so nothing collides with quiet hours by accident. */
function noon(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-07-14 12:00:00');
}

it('does not interrupt anybody who has not turned Trip Mode on', function () {
    Queue::fake();

    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);   // mode OFF

    $notification = app(ConsiderNotification::class)($user->id, $trip->id, candidate($user), noon());

    // The first gate, and the one the whole consent story rests on (PRD §16).
    expect($notification->allowed)->toBeFalse()
        ->and($notification->denied_by)->toBe(NotificationGate::TripModeOff);

    Queue::assertNothingPushed();
});

it('caps a deliberately spammy day at three pushes — and writes down every refusal', function () {
    Queue::fake();

    $user = User::factory()->create();
    $trip = tripInMode($user);
    $consider = app(ConsiderNotification::class);

    /*
     * THE EPIC'S OWN ACCEPTANCE TEST: "a deliberately spammy synthetic day is provably
     * capped at 3 pushes".
     *
     * Ten superb candidates, each one genuinely urgent, each one hours apart so the cooldown
     * never bites. The cap is not "three unless something is really good" — it is three.
     * CLAUDE.md non-negotiable #4, and a daily cap protects a relationship in a way a
     * cooldown never can.
     */
    // Inside waking hours, start to finish. The first draft stepped 2 hours ten times from
    // noon and walked straight into the 22:00 quiet-hours gate — which denied the last five
    // for the RIGHT reason and the wrong one for this test. 09:00 + 70 min × 10 ends at 20:40.
    $at = CarbonImmutable::parse('2026-07-14 09:00:00');

    for ($i = 0; $i < 10; $i++) {
        $notification = $consider($user->id, $trip->id, candidate($user), $at);

        // Pretend the ones we allowed actually went out — the budget is derived from the
        // ledger, which cannot drift from the thing it counts.
        if ($notification->allowed) {
            $notification->forceFill(['sent_at' => $at])->save();
        }

        $at = $at->addMinutes(70);   // past the 60-minute cooldown, every time
    }

    expect(Notification::query()->where('allowed', true)->count())->toBe(3);

    // ...and the other seven are not gone. Every refusal is written down with the gate that
    // made it — which is the only thing that makes PRD §12.2's counterfactual askable, and
    // what the digest valve (§12.4) is built to catch.
    expect(Notification::query()->where('allowed', false)->count())->toBe(7)
        ->and(Notification::query()->where('denied_by', NotificationGate::DailyBudget)->count())->toBe(7);
});

it('holds the cooldown, and lets a genuinely urgent thing through it', function () {
    Queue::fake();

    $user = User::factory()->create();
    $trip = tripInMode($user);
    $consider = app(ConsiderNotification::class);

    $first = $consider($user->id, $trip->id, candidate($user), noon());
    $first->forceFill(['sent_at' => noon()])->save();

    // Ten minutes later, a perfectly nice thing. Not now.
    $ordinary = $consider($user->id, $trip->id, candidate($user, ['urgency' => 0.4, 'confidence' => 0.7]), noon()->addMinutes(10));

    expect($ordinary->denied_by)->toBe(NotificationGate::Cooldown);

    /*
     * ...and the exception, EXACTLY as PRD §12.2 writes it: confidence > .85 AND urgency >
     * .85 AND personal_fit > .75 AND detour within tolerance. All four.
     *
     * It buys one thing: the right to ignore the COOLDOWN. It does not buy the daily cap, it
     * does not buy quiet hours, and it certainly does not buy driving.
     */
    $urgent = $consider($user->id, $trip->id, candidate($user, [
        'confidence' => 0.95, 'urgency' => 0.95, 'personal_fit' => 0.9, 'detour' => 5,
    ]), noon()->addMinutes(12));

    expect($urgent->allowed)->toBeTrue()
        ->and($urgent->trace['urgent_exception'])->toBeTrue();
});

it('never speaks inside quiet hours, however urgent it thinks it is', function () {
    Queue::fake();

    $user = User::factory()->create(['quiet_hours_start' => 22, 'quiet_hours_end' => 8]);
    $trip = tripInMode($user);

    // 03:00, and the most exciting market in Sweden.
    $notification = app(ConsiderNotification::class)($user->id, $trip->id, candidate($user, [
        'confidence' => 0.99, 'urgency' => 0.99, 'personal_fit' => 0.99,
    ]), CarbonImmutable::parse('2026-07-14 03:00:00'));

    /*
     * The urgent exception relaxes the cooldown and NOTHING ELSE. A boost that could open a
     * gate would mean a sufficiently exciting café could wake you at 3am, and no amount of
     * excitement makes that acceptable.
     *
     * Note the window WRAPS midnight (22 → 08). Written naively as `start <= h && h < end`
     * this default would never fire at all — a bug that only ever shows up at 3am, in
     * production, to a real person.
     */
    expect($notification->allowed)->toBeFalse()
        ->and($notification->denied_by)->toBe(NotificationGate::QuietHours);
});

it('does not make somebody look at a phone while they are driving', function () {
    Queue::fake();

    $user = User::factory()->create();
    $trip = tripInMode($user);

    // Phase 2 has no voice mode, so there is no safe way to say this. PRD §12.2 permits the
    // exception only "unless voice mode", and voice mode does not exist.
    $policy = app(NotificationPolicy::class);

    $decision = $policy->decide(
        candidate($user, ['confidence' => 0.99, 'urgency' => 0.99, 'personal_fit' => 0.99]),
        new InterruptionContext(
            userId: $user->id,
            tripId: $trip->id,
            inTripMode: true,
            at: noon(),
            localHour: 12,
            quietHoursStart: null,
            quietHoursEnd: null,
            maxDetourMinutes: null,
            movementMode: MovementMode::Driving,
            sentToday: 0,
            lastSentAt: null,
        ),
    );

    expect($decision->allowed)->toBeFalse()
        ->and($decision->deniedBy)->toBe(NotificationGate::Driving);
});

it('does not ask again about a kind of thing they just said no to', function () {
    Queue::fake();

    $user = User::factory()->create();
    $trip = tripInMode($user);

    // They dismissed a food_drink recommendation yesterday.
    $rejected = candidate($user, ['type_domain' => 'food_drink']);

    app(FeedbackLedger::class)->record(
        $rejected->recommendationId,
        FeedbackEvent::Dismissed,
        [],
        CarbonImmutable::now()->subDay(),
    );

    $notification = app(ConsiderNotification::class)(
        $user->id, $trip->id, candidate($user, ['type_domain' => 'food_drink']), noon(),
    );

    // Asking again is not persistence. It is not listening.
    expect($notification->denied_by)->toBe(NotificationGate::CategoryRejected);
});

it('will not push what the licence only lets us show', function () {
    Queue::fake();

    $user = User::factory()->create();
    $trip = tripInMode($user);

    // Some feeds licence display in-app and nothing else (conventions/09). A licence breach
    // is not a growth tactic, and the gate exists so nobody has to remember that at 2am.
    $notification = app(ConsiderNotification::class)(
        $user->id, $trip->id, candidate($user, ['pushable' => false]), noon(),
    );

    expect($notification->denied_by)->toBe(NotificationGate::NotPushable);
});

it('answers the counterfactual: would a different policy have sent this?', function () {
    Queue::fake();

    $user = User::factory()->create();
    $trip = tripInMode($user);

    // A candidate that squeaks past v1's confidence floor.
    $marginal = candidate($user, ['confidence' => 0.6]);

    $allowed = app(ConsiderNotification::class)($user->id, $trip->id, $marginal, noon());
    expect($allowed->allowed)->toBeTrue();

    /*
     * THE EPIC'S OTHER ACCEPTANCE TEST — PRD §12.2, verbatim:
     *
     *   "Every push records which notification_policy_version allowed it, enabling offline
     *    questions like *would policy_v3 have avoided the annoying push policy_v2 sent?*"
     *
     * You cannot ask that of a model. You can ask it of a function — so re-decide the same
     * candidate under a stricter constant set and diff. This is only possible because the
     * policy is pure: same inputs, same answer, every time.
     */
    config()->set('notifications.gates.min_confidence', 0.8);

    $stricter = app(NotificationPolicy::class)->decide(
        $marginal,
        new InterruptionContext(
            userId: $user->id, tripId: $trip->id, inTripMode: true, at: noon(),
            localHour: 12, quietHoursStart: null, quietHoursEnd: null, maxDetourMinutes: null,
            movementMode: null, sentToday: 0, lastSentAt: null,
        ),
    );

    expect($stricter->allowed)->toBeFalse()
        ->and($stricter->deniedBy)->toBe(NotificationGate::LowConfidence)
        // The decision that DID go out still says which version allowed it, so the diff is a
        // fact rather than a reconstruction.
        ->and($allowed->notification_policy_version)->toBe('v1');
});

it('penalises the third interruption of an afternoon, without gating it', function () {
    Queue::fake();

    $user = User::factory()->create();
    $trip = tripInMode($user);
    $consider = app(ConsiderNotification::class);

    $first = $consider($user->id, $trip->id, candidate($user), noon());
    $first->forceFill(['sent_at' => noon()])->save();

    $second = $consider($user->id, $trip->id, candidate($user, [
        'confidence' => 0.95, 'urgency' => 0.95, 'personal_fit' => 0.9,
    ]), noon()->addMinutes(90));

    /*
     * SCORING §5.3's stub, switched on. The third push of an afternoon is worth less than
     * the first, WHATEVER IT SAYS — the cost of an interruption is not a property of the
     * thing interrupting, it is a property of how recently you were last interrupted.
     *
     * It ORDERS; it does not gate (§5.3: "it only orders candidates within the set the
     * deterministic notification policy already allowed — it never replaces those gates").
     */
    expect($second->allowed)->toBeTrue()
        ->and($second->trace['interruption_penalty'])->toBeGreaterThan(0.0)
        ->and($second->priority)->toBeLessThan($first->priority);
});
