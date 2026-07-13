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
     * The live keep/dismiss state of one recommendation, latest-wins over the stream.
     *
     * The two toggles are read as INDEPENDENT axes here because that is what the table
     * holds — `saved`/`unsaved` and `dismissed`/`undismissed` are disjoint pairs, and a
     * row can genuinely carry a live keep and a live dismissal at once. That state is a
     * contradiction, not a feature: RecordFeedback exists to stop new ones being made.
     * This method is how it sees the one it is about to contradict.
     *
     * Ordered by `id` after `occurred_at`: the deferred-POST queue (S11) flushes a whole
     * dead zone at once, so two events can land on the same clamped timestamp, and
     * "latest" then has to mean "last written" or it means nothing.
     *
     * @return array{kept: bool, dismissed: bool}
     */
    public function toggleStateFor(string $recommendationId): array
    {
        $events = RecommendationFeedback::query()
            ->where('recommendation_id', $recommendationId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $latest = static function (callable $inPair) use ($events): ?FeedbackEvent {
            $matching = $events->filter(static fn (RecommendationFeedback $f): bool => $inPair($f->event));

            return $matching->last()?->event;
        };

        return [
            'kept' => $latest(static fn (FeedbackEvent $e): bool => $e->togglesKeep()) === FeedbackEvent::Saved,
            'dismissed' => $latest(static fn (FeedbackEvent $e): bool => $e->togglesDismiss()) === FeedbackEvent::Dismissed,
        ];
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
