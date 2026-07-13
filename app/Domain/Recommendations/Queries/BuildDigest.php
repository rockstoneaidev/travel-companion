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
            ->join('places_core', 'places_core.id', '=', 'opportunities.place_id')
            ->whereIn('opportunities.place_id', array_keys($byPlace))
            ->whereNotIn('opportunities.status', array_map(
                static fn (OpportunityStatus $s): string => $s->value,
                OpportunityStatus::terminal(),
            ))
            ->where('opportunities.expires_at', '>', $at)
            ->get([
                'opportunities.id',
                'opportunities.place_id',
                'opportunities.title',
                'opportunities.summary',
                'opportunities.window_ends_at',
                // Geometry, for the dashboard map. Every place has this; a photograph
                // is the exception (2.8% of the world model), so the map is the only
                // picture we can always draw.
                DB::raw('ST_Y(places_core.location::geometry) AS lat'),
                DB::raw('ST_X(places_core.location::geometry) AS lng'),
            ]);

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
                lat: (float) $opportunity->lat,
                lng: (float) $opportunity->lng,
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
        /*
         * Selected on the CELL, not on the coordinate — and the difference is the whole
         * point of the cell existing.
         *
         * This used to require `origin IS NOT NULL`. The retention pass nulls `origin` at
         * 30 days and *deliberately keeps* `origin_h3_index` (PRD §16: the coordinate is
         * the sensitive part, the ~460m hex is not), so the greeting quietly stopped
         * working for anyone whose last session was over a month old — the one field the
         * forecast actually needs was sitting right there, and the query was throwing the
         * row away because a field it does NOT need had been erased.
         *
         * Asking for the cell instead is also strictly better privacy: the digest no longer
         * touches the precise coordinate at all.
         */
        $session = DB::table('explore_sessions')
            ->select('origin_h3_index')
            ->where('user_id', $userId)
            ->whereNotNull('origin_h3_index')
            ->orderByDesc('started_at')
            ->first();

        if ($evening) {
            return 'That was the day.';
        }

        if ($session === null) {
            return 'Good morning.';
        }

        // A session with no cell is a real state, not a bug to assume away: erasure nulls
        // it (EraseTripLocations), and it was NULL on every session ever created until the
        // indexer was wired into StartExploreSession. `(string) null` is `''`, and an empty
        // string handed to `h3_cell_to_geometry` is a Postgres error, not a null — so this
        // guard is the difference between a dashboard that degrades and one that 500s.
        $cell = (string) ($session->origin_h3_index ?? '');

        if ($cell === '') {
            return 'Good morning.';
        }

        $rainAt = $this->weather->rainStartsAt($cell, $at);

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
