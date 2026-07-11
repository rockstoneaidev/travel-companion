<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Services;

use App\Domain\Feedback\Services\FeedbackLedger;
use App\Domain\Opportunities\Services\MaterializeEvergreenOpportunities;
use App\Domain\Places\Services\CoverageGeometry;
use App\Domain\Places\Services\ScoutRunner;
use App\Domain\Profiles\Services\TasteProfiles;
use App\Domain\Recommendations\Data\ScoringModel;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Recommendations\Support\Num;
use App\Domain\Trips\Data\ExploreSessionData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * PRD §10 steps 8–10, per user at request time: coverage → shared tile cache
 * → reachability gate → sub-scores → greedy selection → recommendations with
 * their full decision trace. Everything expensive is tile-scoped and cached
 * (SCORING §2.3); this method is a handful of multiplications per candidate.
 */
final class RankSession
{
    private const CREDIBILITY_BY_SOURCE = ['osm' => 'open', 'overture' => 'open', 'wikidata' => 'reference', 'curated' => 'official'];

    private const STATIC_PLACE_TTL_DAYS = 30.0;

    public function __construct(
        private readonly CoverageGeometry $geometry,
        private readonly ScoutRunner $runner,
        private readonly TravelTimeEstimator $estimator,
        private readonly ReachabilityGate $gate,
        private readonly TasteProfiles $profiles,
        private readonly ScoringModelResolver $resolver,
        private readonly MaterializeEvergreenOpportunities $materialize,
    ) {}

    /**
     * The ranked feed for a session — served once, then replayed from the
     * stored recommendations (server order is the order, SCREENS S1).
     *
     * @return list<Recommendation>
     */
    public function feedFor(ExploreSessionData $session): array
    {
        $existing = Recommendation::query()
            ->where('explore_session_id', $session->id)
            ->orderBy('position')
            ->get()
            ->all();

        if ($existing !== [] || $session->origin === null) {
            return $existing;
        }

        $plan = $this->plan($session);

        return DB::transaction(fn (): array => $this->persist($session, $plan));
    }

    /**
     * The pure planning pass (PRD §15.2): compute what WOULD be served, under
     * an injectable clock and scoring model — the replayer's entry point.
     * Warms the shared tile cache but never writes recommendations.
     *
     * @return array{picked: list<array<string, mixed>>, model: ScoringModel, alpha: float, context: string, scout_summary: array, rank_ms: int}
     */
    public function plan(ExploreSessionData $session, ?CarbonImmutable $at = null, ?ScoringModel $modelOverride = null): array
    {
        $at ??= now()->toImmutable();
        $started = hrtime(true);

        $model = $modelOverride ?? $this->resolver->resolve();
        $subScores = new SubScores($model);
        $scorer = new CompositeScorer($model);
        $selector = new FeedSelector($model, $scorer);

        $profile = $this->profiles->forUser($session->userId);
        $alpha = $scorer->alpha($profile->eventCounts, $profile->calibrated);
        $context = $session->destinationPoint !== null ? 'route' : 'radius';

        $coverage = $this->geometry->forSession(
            $session->origin->lat, $session->origin->lng, $session->travelMode,
            $session->timeBudgetMinutes, $session->heading,
            $session->destinationPoint?->lat, $session->destinationPoint?->lng,
        );

        $scoutSummary = $this->runner->warm($coverage);
        $candidates = $this->dedupe($this->runner->candidates($coverage->allTiles()));

        $remaining = ReachabilityGate::remainingMinutes($session->startedAt, $session->timeBudgetMinutes, $at);
        $gated = $this->gate->filter(
            $candidates, $session->origin->lat, $session->origin->lng, $session->travelMode,
            $remaining, $session->destinationPoint?->lat, $session->destinationPoint?->lng,
        );

        $tripEvents = $this->tripHistory($session->tripId, $at);
        $scored = [];

        foreach ($gated['kept'] as $candidate) {
            $scored[] = $this->score($candidate, $session, $subScores, $profile->facetWeights, $profile->walkToleranceMinutes, $remaining, $tripEvents, $at);
        }

        $picked = $selector->select($scored, $context, $alpha, (int) config('trips.session.feed_size'));

        return [
            'picked' => $picked,
            'model' => $model,
            'alpha' => $alpha,
            'context' => $context,
            'scout_summary' => $scoutSummary,
            'rank_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ];
    }

    /**
     * @param  array{picked: list<array<string, mixed>>, model: ScoringModel, scout_summary: array, rank_ms: int}  $plan
     * @return list<Recommendation>
     */
    private function persist(ExploreSessionData $session, array $plan): array
    {
        $picked = $plan['picked'];
        $model = $plan['model'];

        $opportunities = ($this->materialize)(array_map(static fn (array $c): array => [
            'place_id' => $c['place_id'], 'name' => $c['name'], 'h3_index' => $c['h3_index'],
            'walk_minutes' => $c['reachability']['travel_min'],
        ], $picked));

        $recommendations = [];
        foreach ($picked as $position => $candidate) {
            $recommendations[] = Recommendation::query()->create([
                'opportunity_id' => $opportunities[$candidate['place_id']],
                'explore_session_id' => $session->id,
                'trip_id' => $session->tripId,
                'user_id' => $session->userId,
                'position' => $position + 1,
                'scores' => [...$candidate['sub_scores'], 'friction_raw' => $candidate['friction_raw'], 'composite' => $candidate['composite']],
                'score_inputs' => [
                    'candidate' => $this->snapshot($candidate),
                    'raw' => $candidate['raw_inputs'],
                    'selection' => $candidate['selection'],
                    'reachability' => $candidate['reachability'],
                ],
                'coverage_flags' => $candidate['missing'],
                'scoring_model_version' => $model->version,
                'taxonomy_version' => 1,
                'resolver_version' => (string) config('resolver.version'),
                'served_at' => now(),
                // Per-recommendation cost (PRD §14.3): honest Phase 1 numbers —
                // own-DB scouts, no paid APIs, no LLM yet.
                'cost' => [
                    'api_calls' => 0,
                    'llm_tokens' => 0,
                    'rank_ms' => $plan['rank_ms'],
                    'scout_tiles_filled' => array_sum(array_column($plan['scout_summary'], 'filled')),
                    'scout_tiles_hit' => array_sum(array_column($plan['scout_summary'], 'hits')),
                ],
            ]);
        }

        return $recommendations;
    }

    /** @param array<string, mixed> $candidate */
    private function score(array $candidate, ExploreSessionData $session, SubScores $subScores, array $facetWeights, int $tolerance, float $remaining, array $tripEvents, CarbonImmutable $at): array
    {
        $raw = [];
        $missing = [];
        $scores = [];

        $fit = $subScores->personalFit($facetWeights, $candidate['facets']);
        $scores['personal_fit'] = $fit['value'];
        $raw['personal_fit'] = $fit['inputs'];

        $uniq = $subScores->uniqueness([
            'u1' => null, 'u2' => null, 'u3' => null, // review counts, embeddings, evidence locality: not yet measured (§2.5)
            'u4' => 0.0,                              // evergreen temporal rarity
            'u5' => null,
            'u6' => $candidate['u6'] ?? null,
        ]);
        $scores['uniqueness'] = $uniq['value'];
        $raw['uniqueness'] = $uniq['inputs'];
        $missing = [...$missing, ...array_map(static fn (string $u): string => "uniqueness.{$u}", $uniq['missing'])];

        // Phase 1 horizon: the opportunity's own closing bounded by end of the
        // session's day; evergreen has no closing → slack = end of day.
        $travel = (float) $candidate['reachability']['travel_min'];
        $slack = max(0.0, $at->diffInMinutes($at->endOfDay(), false) - $travel);
        $urgency = $subScores->temporalUrgency($slack);
        $scores['temporal_urgency'] = $urgency['value'];
        $raw['temporal_urgency'] = $urgency['inputs'];

        if ($session->destinationPoint !== null) {
            $direct = $this->estimator->minutes($session->origin->lat, $session->origin->lng, $session->destinationPoint->lat, $session->destinationPoint->lng, $session->travelMode);
            $detour = $travel + (float) $candidate['reachability']['return_min'] - $direct;
            $routeFit = $subScores->routeFit(max(0.0, $detour), max(0.0, $remaining - $direct), (float) $tolerance);
            $scores['route_fit'] = $routeFit['value'];
            $raw['route_fit'] = $routeFit['inputs'];
        }

        $novelty = $subScores->novelty($this->noveltyEventsFor($candidate, $tripEvents));
        $scores['novelty'] = $novelty['value'];
        $raw['novelty'] = $novelty['inputs'];

        $tiers = array_values(array_unique(array_map(
            static fn (string $s): string => self::CREDIBILITY_BY_SOURCE[$s] ?? 'community',
            $candidate['sources'] ?? [],
        )));
        $confidence = $subScores->confidence(
            $tiers,
            (int) ($candidate['conflict_groups'] ?? 0),
            $missing,
            (float) ($candidate['age_days'] ?? 0) / self::STATIC_PLACE_TTL_DAYS,
        );
        $scores['confidence'] = $confidence['value'];
        $raw['confidence'] = $confidence['inputs'];

        // Final-approach walk: the travel leg when walking; a short fixed
        // approach otherwise (input logged either way — §2.2).
        $walkMinutes = $session->travelMode->value === 'walk' ? $travel : 3.0;
        $friction = $subScores->frictionRaw($walkMinutes, (float) $tolerance, null, 'low', 0.0, 0.0);

        return [
            ...$candidate,
            'sub_scores' => $scores,
            'friction_raw' => $friction['value'],
            'raw_inputs' => [...$raw, 'friction' => $friction['inputs']],
            'missing' => $missing,
            'total_minutes' => (float) $candidate['reachability']['total_min'],
        ];
    }

    /**
     * Dedupe multi-scout hits per place (union the scout tags) and compute u6
     * facet-combination rarity tile-relatively while everything is in memory.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function dedupe(array $candidates): array
    {
        $byPlace = [];
        foreach ($candidates as $candidate) {
            $id = $candidate['place_id'];
            if (isset($byPlace[$id])) {
                $byPlace[$id]['scouts'][] = $candidate['scout'];

                continue;
            }
            $candidate['scouts'] = [$candidate['scout']];
            $byPlace[$id] = $candidate;
        }

        // u6: share of tile places with facet-set Jaccard ≥ 0.5 (SCORING §4.2).
        $byTile = [];
        foreach ($byPlace as $id => $c) {
            $byTile[$c['h3_index']][] = $id;
        }

        foreach ($byTile as $ids) {
            foreach ($ids as $id) {
                $mine = $byPlace[$id]['facets'];
                if ($mine === [] || count($ids) < 2) {
                    $byPlace[$id]['u6'] = null;

                    continue;
                }

                $similar = 0;
                foreach ($ids as $otherId) {
                    $theirs = $byPlace[$otherId]['facets'];
                    $union = count(array_unique([...$mine, ...$theirs]));
                    if ($union > 0 && count(array_intersect($mine, $theirs)) / $union >= 0.5) {
                        $similar++;
                    }
                }

                $byPlace[$id]['u6'] = round(Num::clamp(1.0 - $similar / count($ids)), 4);
            }
        }

        return array_values($byPlace);
    }

    /** @return list<array{type: ?string, type_domain: ?string, event: string, age_days: float}> */
    private function tripHistory(string $tripId, CarbonImmutable $at): array
    {
        $rows = Recommendation::query()
            ->where('trip_id', $tripId)
            ->whereNotNull('served_at')
            ->get(['id', 'score_inputs', 'served_at']);

        if ($rows->isEmpty()) {
            return [];
        }

        $events = app(FeedbackLedger::class)
            ->eventsForRecommendations($rows->pluck('id')->all());

        $out = [];
        foreach ($rows as $row) {
            $candidate = $row->score_inputs['candidate'] ?? [];
            foreach ($events[$row->id] ?? [] as $event) {
                $out[] = [
                    'type' => $candidate['type'] ?? null,
                    'type_domain' => $candidate['type_domain'] ?? null,
                    'event' => $event['event'],
                    'age_days' => max(0.0, CarbonImmutable::parse($event['occurred_at'])->diffInDays($at, false)),
                ];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $candidate */
    private function noveltyEventsFor(array $candidate, array $tripEvents): array
    {
        $out = [];
        foreach ($tripEvents as $event) {
            if ($event['type_domain'] !== $candidate['type_domain']) {
                continue;
            }
            $out[] = ['event' => $event['event'], 'age_days' => $event['age_days'], 'same_type' => $event['type'] === $candidate['type']];
        }

        return $out;
    }

    /** @param array<string, mixed> $candidate */
    private function snapshot(array $candidate): array
    {
        return [
            'place_id' => $candidate['place_id'],
            'name' => $candidate['name'],
            'type' => $candidate['type'],
            'type_domain' => $candidate['type_domain'],
            'facets' => $candidate['facets'],
            'lat' => $candidate['lat'],
            'lng' => $candidate['lng'],
            'h3_index' => $candidate['h3_index'],
            'scouts' => $candidate['scouts'],
        ];
    }
}
