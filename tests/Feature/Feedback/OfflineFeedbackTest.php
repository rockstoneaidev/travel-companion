<?php

declare(strict_types=1);

use App\Domain\Feedback\Models\RecommendationFeedback;
use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Recommendations\Models\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| S11 — feedback queued in a dead zone, flushed on reconnect
|--------------------------------------------------------------------------
|
| When you tapped is not when we hear about it. France-corridor dead zones are
| the normal condition (PRD risk #10), so a tap can reach us hours late — and
| PendingVisitPrompts reasons about ELAPSED time, so stamping the flush time is
| not a rounding error, it is a wrong answer.
|
| The client is not trusted with the clock, though: it is clamped.
|
*/

function recommendationFor(User $user): Recommendation
{
    $opportunity = Opportunity::factory()->create(['status' => OpportunityStatus::Served]);

    return Recommendation::query()->create([
        'user_id' => $user->id,
        'opportunity_id' => $opportunity->id,
        'position' => 1,
        'scores' => [],
        'score_inputs' => ['candidate' => ['name' => 'Färgfabriken', 'lat' => 59.31, 'lng' => 18.02, 'facets' => ['history']]],
        'scoring_model_version' => 'v1',
        'taxonomy_version' => 1,
        'served_at' => now(),
    ]);
}

it('records when the tap happened, not when the dead zone let it through', function () {
    $this->actingAs($user = User::factory()->create());
    $recommendation = recommendationFor($user);

    $tappedAt = now()->subHours(3);

    $this->postJson("/recommendations/{$recommendation->id}/feedback", [
        'event' => 'visited',
        'occurred_at' => $tappedAt->toIso8601String(),
    ])->assertNoContent();

    $feedback = RecommendationFeedback::query()->sole();

    // Three hours out in the field, then a flush. The moat says three hours ago.
    expect($feedback->occurred_at->timestamp)->toBe($tappedAt->timestamp);
});

it('still stamps now when the client says nothing', function () {
    $this->actingAs($user = User::factory()->create());
    $recommendation = recommendationFor($user);

    $this->postJson("/recommendations/{$recommendation->id}/feedback", ['event' => 'saved'])->assertNoContent();

    expect(RecommendationFeedback::query()->sole()->occurred_at->timestamp)
        ->toBeGreaterThanOrEqual(now()->subMinute()->timestamp);
});

it('refuses a tap from the future — a clock is not a fact', function () {
    $this->actingAs($user = User::factory()->create());
    $recommendation = recommendationFor($user);

    $this->postJson("/recommendations/{$recommendation->id}/feedback", [
        'event' => 'visited',
        'occurred_at' => now()->addDay()->toIso8601String(),
    ])->assertNoContent();

    // A device clock can be wrong, or lying. A tap that happened "tomorrow" would
    // make this recommendation look accepted before it was ever served.
    expect(RecommendationFeedback::query()->sole()->occurred_at->timestamp)
        ->toBeLessThanOrEqual(now()->addSecond()->timestamp);
});

it('refuses a tap older than any plausible dead zone', function () {
    $this->actingAs($user = User::factory()->create());
    $recommendation = recommendationFor($user);

    $this->postJson("/recommendations/{$recommendation->id}/feedback", [
        'event' => 'visited',
        'occurred_at' => now()->subYear()->toIso8601String(),
    ])->assertNoContent();

    // Nobody was offline for a year. Accepting it would resurrect a dead trip.
    expect(RecommendationFeedback::query()->sole()->occurred_at->timestamp)
        ->toBeGreaterThan(now()->subDays(8)->timestamp);
});

it('answers the offline queue with JSON, not a redirect it would have to follow', function () {
    $this->actingAs($user = User::factory()->create());
    $recommendation = recommendationFor($user);

    // The queue flushes with fetch(), not an Inertia visit: a 302 would have it
    // re-download a page nobody is looking at, in a dead zone, over roaming data.
    $this->postJson("/recommendations/{$recommendation->id}/feedback", ['event' => 'saved'])
        ->assertNoContent();

    // ...while a real Inertia visit still gets its redirect back.
    $this->post("/recommendations/{$recommendation->id}/feedback", ['event' => 'saved'])
        ->assertRedirect();
});

it('will not take another traveller\'s queued feedback', function () {
    $this->actingAs($owner = User::factory()->create());
    $recommendation = recommendationFor($owner);

    $this->actingAs(User::factory()->create());

    $this->postJson("/recommendations/{$recommendation->id}/feedback", ['event' => 'visited'])
        ->assertForbidden();
});
