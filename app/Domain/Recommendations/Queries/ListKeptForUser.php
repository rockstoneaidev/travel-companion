<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Queries;

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Feedback\Services\FeedbackLedger;
use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Recommendations\Data\KeptItemData;
use App\Domain\Recommendations\Models\Recommendation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * KEPT (SCREENS S6).
 *
 * Two questions, and they are different: *did you keep this* (the ledger) and
 * *can you still do it* (the world model). Answering the second from the first —
 * showing a keep as live because it was live when you kept it — is exactly the
 * failure this screen exists to avoid. So the window is re-checked here, on every
 * open, against the opportunity as it is right now.
 *
 * A keep is retracted, never deleted: the ledger is append-only (PRD §14.5), so
 * "kept" means the latest of {saved, unsaved} is `saved`.
 */
final class ListKeptForUser
{
    public function __construct(private readonly FeedbackLedger $ledger) {}

    /** @return list<KeptItemData> */
    public function forUser(int $userId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        // Anything they ever kept — the retraction is settled below, against the
        // ledger's own event stream rather than a second guess in SQL.
        $recommendations = Recommendation::query()
            ->where('user_id', $userId)
            ->whereExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('recommendation_feedback as kept')
                ->whereColumn('kept.recommendation_id', 'recommendations.id')
                ->where('kept.event', FeedbackEvent::Saved->value))
            ->get(['id', 'opportunity_id', 'score_inputs']);

        if ($recommendations->isEmpty()) {
            return [];
        }

        $events = $this->ledger->eventsForRecommendations($recommendations->pluck('id')->all());

        $live = $this->liveOpportunities($recommendations->pluck('opportunity_id')->filter()->all());

        $out = [];
        foreach ($recommendations as $recommendation) {
            $keptAt = $this->keptAt($events[$recommendation->id] ?? []);

            if ($keptAt === null) {
                continue;   // kept, then removed — the retraction wins
            }

            $candidate = $recommendation->score_inputs['candidate'] ?? null;

            if ($candidate === null || ! isset($candidate['lat'], $candidate['lng'], $candidate['name'])) {
                continue;
            }

            $opportunity = $live[$recommendation->opportunity_id] ?? null;

            $windowEndsAt = $opportunity?->window_ends_at !== null
                ? CarbonImmutable::parse($opportunity->window_ends_at)
                : null;

            $out[] = new KeptItemData(
                recommendationId: $recommendation->id,
                opportunityId: $recommendation->opportunity_id,
                title: (string) ($opportunity->title ?? $candidate['name']),
                note: $opportunity->summary ?? null,
                lat: (float) $candidate['lat'],
                lng: (float) $candidate['lng'],
                keptAt: $keptAt,
                windowEndsAt: $windowEndsAt,
                // Gone from the world model, expired, or its window has closed: any
                // of those and we cannot honestly offer to take them there.
                stillPossible: $opportunity !== null && ($windowEndsAt === null || $windowEndsAt->isAfter($now)),
            );
        }

        // Newest keep first — within each group the screen splits them.
        usort($out, static fn (KeptItemData $a, KeptItemData $b): int => $b->keptAt <=> $a->keptAt);

        return $out;
    }

    /**
     * The moment it was kept — or null if it was since removed. Latest event wins,
     * so keep → remove → keep again is kept, at the second keep's time.
     *
     * @param  list<array{event: string, occurred_at: string}>  $events
     */
    private function keptAt(array $events): ?CarbonImmutable
    {
        $latest = null;

        foreach ($events as $event) {
            $type = FeedbackEvent::tryFrom($event['event']);

            if ($type?->togglesKeep() === true) {
                $latest = $event;   // eventsForRecommendations() is ordered by occurred_at
            }
        }

        return $latest !== null && $latest['event'] === FeedbackEvent::Saved->value
            ? CarbonImmutable::parse($latest['occurred_at'])
            : null;
    }

    /**
     * Opportunities are ephemeral and TTL'd; recommendations are not (PRD §14).
     * A keep can therefore outlive the thing it points at, which is precisely why
     * this is a left-join in spirit: the row still renders from the recommendation's
     * own snapshot, it just stops claiming to be possible.
     *
     * Read as a table, not through Opportunities' Eloquent model — cross-module
     * traffic never touches another module's models (conventions/01).
     *
     * @param  list<string>  $ids
     * @return array<string, object>
     */
    private function liveOpportunities(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('opportunities')
            ->whereIn('id', $ids)
            ->whereNotIn('status', array_map(
                static fn (OpportunityStatus $status): string => $status->value,
                OpportunityStatus::terminal(),
            ))
            ->where('expires_at', '>', now())
            ->get(['id', 'title', 'summary', 'window_ends_at'])
            ->keyBy('id')
            ->all();
    }
}
