<?php

declare(strict_types=1);

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Feedback\Models\RecommendationFeedback;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Profiles\Models\UserTasteProfile;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Recommendations\Queries\PendingVisitPrompts;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| "Were you there?" (SCREENS S4)
|--------------------------------------------------------------------------
|
| The single most valuable tap in the learning loop. We ask only where the
| question is honest, and "Didn't go" must never be mistaken for a rejection.
|
*/

function servedRecommendation(User $user): Recommendation
{
    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id,
        'user_id' => $user->id,
    ]);
    $opportunity = Opportunity::factory()->create();

    return Recommendation::query()->create([
        'opportunity_id' => $opportunity->id,
        'explore_session_id' => $session->id,
        'trip_id' => $trip->id,
        'user_id' => $user->id,
        'position' => 1,
        'scores' => ['composite' => 0.5],
        'score_inputs' => [
            'candidate' => [
                'place_id' => $opportunity->place_id,
                'name' => 'Färgfabriken',
                'lat' => 59.3117,
                'lng' => 18.0206,
                'facets' => ['art', 'architecture'],
            ],
        ],
        'coverage_flags' => [],
        'scoring_model_version' => 'v1',
        'taxonomy_version' => 1,
        'resolver_version' => 'v1',
        'served_at' => now()->subHour(),
        'cost' => [],
    ]);
}

function tookMeThere(Recommendation $recommendation, int $minutesAgo): void
{
    RecommendationFeedback::query()->create([
        'recommendation_id' => $recommendation->id,
        'event' => FeedbackEvent::Accepted->value,
        'metadata' => ['started_navigation' => true],
        'occurred_at' => now()->subMinutes($minutesAgo),
    ]);
}

it('asks once enough time has passed to have actually got there', function () {
    $user = User::factory()->create();
    tookMeThere(servedRecommendation($user), minutesAgo: 45);

    $prompts = app(PendingVisitPrompts::class)->forUser($user->id);

    expect($prompts)->toHaveCount(1)
        ->and($prompts[0]->placeName)->toBe('Färgfabriken')
        ->and($prompts[0]->lat)->toBe(59.3117);
});

it('does not ask five minutes after a Take me — they are still walking', function () {
    $user = User::factory()->create();
    tookMeThere(servedRecommendation($user), minutesAgo: 5);

    expect(app(PendingVisitPrompts::class)->forUser($user->id))->toBeEmpty();
});

it('does not ask about an item they never said Take me to', function () {
    $user = User::factory()->create();
    $recommendation = servedRecommendation($user);

    // Merely served, and separately merely saved — neither is a navigation start.
    RecommendationFeedback::query()->create([
        'recommendation_id' => $recommendation->id,
        'event' => FeedbackEvent::Saved->value,
        'metadata' => [],
        'occurred_at' => now()->subHour(),
    ]);

    expect(app(PendingVisitPrompts::class)->forUser($user->id))->toBeEmpty();
});

it('stops asking once answered, either way', function (string $answer) {
    $user = User::factory()->create();
    $recommendation = servedRecommendation($user);
    tookMeThere($recommendation, minutesAgo: 45);

    $this->actingAs($user)->post("/recommendations/{$recommendation->id}/feedback", ['event' => $answer]);

    expect(app(PendingVisitPrompts::class)->forUser($user->id))->toBeEmpty();
})->with(['visited', 'visit_prompt_declined']);

it('learns the golden label from "I was there"', function () {
    $user = User::factory()->create();
    $recommendation = servedRecommendation($user);
    tookMeThere($recommendation, minutesAgo: 45);

    $this->actingAs($user)->post("/recommendations/{$recommendation->id}/feedback", ['event' => 'visited']);

    $profile = UserTasteProfile::query()->where('user_id', $user->id)->sole();

    // η .30 toward 1.0 from the 0.5 default.
    expect($profile->facet_weights['art'])->toBeGreaterThan(0.5)
        ->and($profile->event_counts['visited'])->toBe(1);
});

it('learns nothing at all from "Didn\'t go"', function () {
    $user = User::factory()->create();
    $recommendation = servedRecommendation($user);
    tookMeThere($recommendation, minutesAgo: 45);

    $this->actingAs($user)->post("/recommendations/{$recommendation->id}/feedback", [
        'event' => 'visit_prompt_declined',
    ]);

    // Recorded, so we stop asking...
    expect(RecommendationFeedback::query()
        ->where('recommendation_id', $recommendation->id)
        ->where('event', 'visit_prompt_declined')
        ->exists())->toBeTrue();

    // ...but it is not a taste signal. The user *accepted* this item; the rain
    // is not evidence they dislike art. No weight moved, and — just as important
    // — it must not count toward n_eff and warm them out of cold start.
    $profile = UserTasteProfile::query()->where('user_id', $user->id)->first();

    if ($profile !== null) {
        expect($profile->facet_weights)->toBe([])
            ->and($profile->event_counts)->not->toHaveKey('visit_prompt_declined');
    }
});
