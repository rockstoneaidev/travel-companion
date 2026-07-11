<?php

declare(strict_types=1);

namespace App\Domain\Feedback\Services;

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Feedback\Models\RecommendationFeedback;
use DateTimeInterface;

/**
 * Feedback's public API (conventions/01): append events, read them back for
 * novelty and α. This stream is the moat (PRD §14.5) — treat completeness as
 * a product requirement.
 */
final class FeedbackLedger
{
    /** @param array<string, mixed> $metadata */
    public function record(string $recommendationId, FeedbackEvent $event, array $metadata, DateTimeInterface $occurredAt): void
    {
        RecommendationFeedback::query()->create([
            'recommendation_id' => $recommendationId,
            'event' => $event,
            'metadata' => $metadata,
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * @param  list<string>  $recommendationIds
     * @return array<string, list<array{event: string, occurred_at: string}>>
     */
    public function eventsForRecommendations(array $recommendationIds): array
    {
        if ($recommendationIds === []) {
            return [];
        }

        return RecommendationFeedback::query()
            ->whereIn('recommendation_id', $recommendationIds)
            ->orderBy('occurred_at')
            ->get()
            ->groupBy('recommendation_id')
            ->map(static fn ($group) => $group->map(static fn (RecommendationFeedback $f): array => [
                'event' => $f->event->value,
                'occurred_at' => $f->occurred_at->toIso8601String(),
            ])->all())
            ->all();
    }
}
