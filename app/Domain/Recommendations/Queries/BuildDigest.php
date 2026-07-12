<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Queries;

use App\Domain\Context\Services\WeatherClient;
use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Places\Contracts\PlaceImageLookup;
use App\Domain\Recommendations\Data\DigestData;
use App\Domain\Recommendations\Data\DigestItem;
use App\Domain\Recommendations\Models\Recommendation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The digest release valve (PRD §12.4, SCREENS S8).
 *
 * Built from what the FEED THREW AWAY: the near-misses (scored, servable, and
 * beaten by four better things) and the held items. Those are the most valuable
 * candidates nobody ever sees, and until now they went nowhere.
 *
 * Two variants of one shell:
 *
 *   morning — "today near you". What is still possible, with its window.
 *   evening — a recap. Past tense, and the day's visited/kept count.
 *
 * The lede is TEMPLATE-OVER-TRACE, never generated. "Stockholm is dry until four"
 * is a factual claim, and the LLM is never a source of facts (CLAUDE.md): the hour
 * comes from an hourly forecast, and when there is no forecast there is no claim.
 */
final class BuildDigest
{
    /** Scarcity is the product (§12.1). A digest of thirty things is a list. */
    private const MAX_ITEMS = 5;

    public function __construct(
        private readonly WeatherClient $weather,
        private readonly PlaceImageLookup $images,
    ) {}

    public function forUser(int $userId, ?CarbonImmutable $at = null): DigestData
    {
        $at ??= CarbonImmutable::now();
        $evening = $at->hour >= 17;

        $trip = DB::table('trips')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->first(['id', 'name']);

        $lede = $this->lede($userId, $at, $evening);

        $counts = $this->todaysCounts($userId, $at);

        return new DigestData(
            variant: $evening ? 'evening' : 'morning',
            lede: $lede,
            subline: $evening
                ? 'Nothing needs deciding tonight.'
                : 'Nothing needs deciding now — these will keep.',
            items: $this->items($userId, $at),
            tripId: $trip?->id,
            tripName: $trip?->name,
            visitedToday: $counts['visited'],
            keptToday: $counts['kept'],
        );
    }

    /**
     * What the feed passed over, in the last day.
     *
     * @return list<DigestItem>
     */
    private function items(int $userId, CarbonImmutable $at): array
    {
        $funnels = Recommendation::query()
            ->where('user_id', $userId)
            ->where('served_at', '>=', $at->subDay())
            ->orderByDesc('served_at')
            ->limit(50)
            ->pluck('score_inputs');

        $byPlace = [];

        foreach ($funnels as $inputs) {
            foreach ($inputs['funnel']['near_misses'] ?? [] as $miss) {
                $byPlace[$miss['place_id']] ??= ['name' => $miss['name'], 'reason' => 'outranked'];
            }

            foreach ($inputs['funnel']['held'] ?? [] as $held) {
                $byPlace[$held['place_id']] ??= ['name' => $held['name'], 'reason' => 'held'];
            }
        }

        if ($byPlace === []) {
            return [];
        }

        // Join to the live opportunity for the title, the note and — the thing that
        // makes a digest useful rather than a list — the window.
        $opportunities = DB::table('opportunities')
            ->whereIn('place_id', array_keys($byPlace))
            ->whereNotIn('status', array_map(
                static fn (OpportunityStatus $s): string => $s->value,
                OpportunityStatus::terminal(),
            ))
            ->where('expires_at', '>', $at)
            ->get(['id', 'place_id', 'title', 'summary', 'window_ends_at']);

        $images = $this->images->forPlaces(array_keys($byPlace));

        $items = [];

        foreach ($opportunities as $opportunity) {
            $window = $opportunity->window_ends_at === null
                ? null
                : CarbonImmutable::parse($opportunity->window_ends_at);

            // A window that has already closed is not "today near you". Silence beats
            // a stale suggestion, here as everywhere else.
            if ($window !== null && $window->isBefore($at)) {
                continue;
            }

            $meta = $byPlace[$opportunity->place_id];

            $items[] = new DigestItem(
                opportunityId: $opportunity->id,
                title: (string) ($opportunity->title ?? $meta['name']),
                note: $opportunity->summary,
                windowEndsAt: $window,
                reason: $meta['reason'],
                image: $images[$opportunity->place_id] ?? null,
            );

            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $items;
    }

    /**
     * The greeting, written from real context — never generated.
     *
     * Falls back to saying less rather than saying something untrue: with no
     * forecast we do not claim the weather, and with no location we do not name a
     * city we are guessing at.
     */
    private function lede(int $userId, CarbonImmutable $at, bool $evening): string
    {
        $session = DB::table('explore_sessions')
            ->selectRaw('origin_h3_index, ST_Y(origin::geometry) AS lat, ST_X(origin::geometry) AS lng')
            ->where('user_id', $userId)
            ->whereNotNull('origin')
            ->orderByDesc('started_at')
            ->first();

        if ($evening) {
            return 'That was the day.';
        }

        if ($session === null) {
            return 'Good morning.';
        }

        $rainAt = $this->weather->rainStartsAt((string) $session->origin_h3_index, $at);

        if ($rainAt === null) {
            return 'Good morning — it stays dry today.';
        }

        return "Good morning — it's dry until {$rainAt->format('g')}.";
    }

    /** @return array{visited: int, kept: int} */
    private function todaysCounts(int $userId, CarbonImmutable $at): array
    {
        $rows = DB::table('recommendation_feedback as f')
            ->join('recommendations as r', 'r.id', '=', 'f.recommendation_id')
            ->where('r.user_id', $userId)
            ->whereBetween('f.occurred_at', [$at->startOfDay(), $at->endOfDay()])
            ->whereIn('f.event', [FeedbackEvent::Visited->value, FeedbackEvent::Saved->value])
            ->selectRaw('f.event, COUNT(*) AS n')
            ->groupBy('f.event')
            ->pluck('n', 'event');

        return [
            'visited' => (int) ($rows[FeedbackEvent::Visited->value] ?? 0),
            'kept' => (int) ($rows[FeedbackEvent::Saved->value] ?? 0),
        ];
    }
}
