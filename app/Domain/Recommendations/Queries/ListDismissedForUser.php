<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Queries;

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Feedback\Services\FeedbackLedger;
use App\Domain\Places\Contracts\PlaceImageLookup;
use App\Domain\Recommendations\Data\DismissedItemData;
use App\Domain\Recommendations\Models\Recommendation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The "Not for me" list on KEPT (SCREENS S6) — the negative twin of ListKeptForUser,
 * and deliberately its mirror image: same append-only ledger, same latest-wins toggle,
 * same "the recommendation's own snapshot is what renders" rule.
 *
 * Why the screen exists at all: "Not for me" is the most destructive tap in the product
 * — it hides the card AND teaches the profile to serve fewer like it (η .25) — and it
 * sits one thumb-width from "Keep". A one-way door that irreversible needs a way back,
 * or a mis-tap is a permanent, invisible narrowing of what the user is ever shown again.
 *
 * Unlike KEPT, this does NOT re-check the window against the world model. Nothing here
 * is offered as actionable — there is no "Take me", only "Show me these again" — so a
 * live-ness check would be a query run to answer a question nobody asked. Restoring puts
 * the item back in the feed, and the feed does its own checking.
 */
final class ListDismissedForUser
{
    public function __construct(
        private readonly FeedbackLedger $ledger,
        private readonly PlaceImageLookup $images,
    ) {}

    /** @return list<DismissedItemData> */
    public function forUser(int $userId): array
    {
        // Anything they ever dismissed — the retraction is settled below, against the
        // ledger's event stream rather than a second guess in SQL.
        $recommendations = Recommendation::query()
            ->where('user_id', $userId)
            ->whereExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('recommendation_feedback as dismissed')
                ->whereColumn('dismissed.recommendation_id', 'recommendations.id')
                ->where('dismissed.event', FeedbackEvent::Dismissed->value))
            ->get(['id', 'opportunity_id', 'score_inputs']);

        if ($recommendations->isEmpty()) {
            return [];
        }

        $events = $this->ledger->eventsForRecommendations($recommendations->pluck('id')->all());

        // The title the user actually saw, where the opportunity still exists. It is
        // ephemeral and TTL'd (PRD §14), so this is a left join in spirit: when it has
        // expired we still render the row from the recommendation's frozen candidate.
        $opportunities = DB::table('opportunities')
            ->whereIn('id', $recommendations->pluck('opportunity_id')->filter()->all())
            ->get(['id', 'title', 'summary'])
            ->keyBy('id');

        $images = $this->images->forPlaces(
            $recommendations
                ->map(static fn ($r) => $r->score_inputs['candidate']['place_id'] ?? null)
                ->filter()->unique()->values()->all(),
        );

        $out = [];
        foreach ($recommendations as $recommendation) {
            $dismissedAt = $this->dismissedAt($events[$recommendation->id] ?? []);

            if ($dismissedAt === null) {
                continue;   // dismissed, then restored — the retraction wins
            }

            $candidate = $recommendation->score_inputs['candidate'] ?? null;

            if ($candidate === null || ! isset($candidate['name'])) {
                continue;
            }

            $opportunity = $opportunities->get($recommendation->opportunity_id);

            $out[] = new DismissedItemData(
                recommendationId: $recommendation->id,
                title: (string) ($opportunity->title ?? $candidate['name']),
                note: $opportunity->summary ?? null,
                dismissedAt: $dismissedAt,
                image: $images[$candidate['place_id'] ?? ''] ?? null,
            );
        }

        // Most recently dismissed first — the mis-tap you want to undo is the one you
        // just made.
        usort($out, static fn (DismissedItemData $a, DismissedItemData $b): int => $b->dismissedAt <=> $a->dismissedAt);

        return $out;
    }

    /**
     * The moment it was dismissed — or null if it was since restored. Latest event
     * wins, so dismiss → restore → dismiss again is dismissed, at the second one's time.
     *
     * @param  list<array{event: string, occurred_at: string}>  $events
     */
    private function dismissedAt(array $events): ?CarbonImmutable
    {
        $latest = null;

        foreach ($events as $event) {
            $type = FeedbackEvent::tryFrom($event['event']);

            if ($type?->togglesDismiss() === true) {
                $latest = $event;   // eventsForRecommendations() is ordered by occurred_at
            }
        }

        return $latest !== null && $latest['event'] === FeedbackEvent::Dismissed->value
            ? CarbonImmutable::parse($latest['occurred_at'])
            : null;
    }
}
