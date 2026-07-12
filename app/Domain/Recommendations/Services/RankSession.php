<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Services;

use App\Domain\Feedback\Services\FeedbackLedger;
use App\Domain\Opportunities\Services\MaterializeEvergreenOpportunities;
use App\Domain\Places\Services\CoverageGeometry;
use App\Domain\Places\Services\ScoutRunner;
use App\Domain\Places\Services\TileUniquenessSignals;
use App\Domain\Profiles\Services\TasteProfiles;
use App\Domain\Recommendations\Data\ScoringModel;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Jobs\Enrichment\GenerateOpportunityVoiceJob;
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
    /**
     * Source → credibility tier for the confidence sub-score and the Decide
     * evidence gates (SCORING §2.1, §4.6).
     *
     * An unlisted source falls through to `community` (Tier D), which cannot
     * establish existence on its own — so forgetting to add an adapter here does
     * not quietly serve unvouched places, it holds them as leads. Safe by
     * default, but it means every new adapter MUST be registered here or its
     * places never surface.
     */
    private const CREDIBILITY_BY_SOURCE = [
        'osm' => 'open',
        'overture' => 'open',
        'wikidata' => 'reference',
        'merimee' => 'official',        // a national ministry registry (DATA-SOURCES §1.2 Tier A)
        'datatourisme' => 'official',   // a tourism board writing about its own territory
        'curated' => 'official',
    ];

    private const STATIC_PLACE_TTL_DAYS = 30.0;

    public function __construct(
        private readonly CoverageGeometry $geometry,
        private readonly ScoutRunner $runner,
        private readonly TravelTimeEstimator $estimator,
        private readonly ReachabilityGate $gate,
        private readonly TasteProfiles $profiles,
        private readonly ScoringModelResolver $resolver,
        private readonly MaterializeEvergreenOpportunities $materialize,
        private readonly CostMeter $cost,
        private readonly TileUniquenessSignals $uniqueness,
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
     * @return array{picked: list<array<string, mixed>>, held: list<array<string, mixed>>, model: ScoringModel, alpha: float, context: string, scout_summary: array, rank_ms: int}
     */
    public function plan(ExploreSessionData $session, ?CarbonImmutable $at = null, ?ScoringModel $modelOverride = null): array
    {
        $at ??= now()->toImmutable();
        $started = hrtime(true);

        $model = $modelOverride ?? $this->resolver->resolve();
        $subScores = new SubScores($model);
        $scorer = new CompositeScorer($model);
        $selector = new FeedSelector($model, $scorer);
        $evidence = new EvidenceGate($model);

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

        // Decide (PRD §10 step 10): evidence gates decide membership, before
        // selection ever sees a candidate — a held item must not merely rank low.
        $decided = $evidence->partition($scored);

        $picked = $selector->select($decided['served'], $context, $alpha, (int) config('trips.session.feed_size'));

        return [
            'picked' => $picked,
            'held' => $decided['held'],
            // Candidates the reachability gate dropped, with their breakdowns.
            // The gate computed these and they were being thrown away, so a trace
            // could never answer "why was this not offered to me" — which is the
            // only question a decision trace exists to answer (PRD §15.1).
            'unreachable' => $this->unreachableTrace($gated['excluded']),
            'model' => $model,
            'alpha' => $alpha,
            'context' => $context,
            'scout_summary' => $scoutSummary,
            'rank_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ];
    }

    /**
     * @param  array{picked: list<array<string, mixed>>, held: list<array<string, mixed>>, model: ScoringModel, scout_summary: array, rank_ms: int}  $plan
     * @return list<Recommendation>
     */
    private function persist(ExploreSessionData $session, array $plan): array
    {
        $picked = $plan['picked'];
        $model = $plan['model'];

        $opportunities = ($this->materialize)(array_map(static fn (array $c): array => [
            'place_id' => $c['place_id'], 'name' => $c['name'], 'h3_index' => $c['h3_index'],
            'walk_minutes' => $c['reachability']['travel_min'],
            'summary' => $c['curated_claim'] ?? null,   // a reviewed human/curated claim may speak (conventions/10)
        ], $picked));

        $this->requestVoiceFor($picked, $opportunities, $session);

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
                    // Session-level funnel: what this item beat, and what never
                    // got the chance to compete (PRD §15.1 — the full decision
                    // trace, not just the winner's half of it).
                    'funnel' => [
                        'unreachable' => $plan['unreachable'],
                        'held' => array_map(static fn (array $c): array => [
                            'place_id' => $c['place_id'], 'name' => $c['name'], 'hold' => $c['hold'],
                        ], $plan['held']),
                    ],
                ],
                'coverage_flags' => $candidate['missing'],
                'scoring_model_version' => $model->version,
                'taxonomy_version' => 1,
                'resolver_version' => (string) config('resolver.version'),
                'served_at' => now(),
                // Per-recommendation cost (PRD §14.3). Phase 1 ranks off our own
                // database, so these are zero — but they are *measured* zero, not
                // asserted zero: the CostMeter counts every outbound call made
                // while serving, so a paid API added here shows up on the trace
                // without anyone having to remember to instrument it.
                'cost' => [
                    'api_calls' => $this->cost->apiCalls(),
                    'llm_tokens' => $this->cost->llmTokens(),
                    'api_calls_by_host' => $this->cost->byHost(),
                    'rank_ms' => $plan['rank_ms'],
                    'scout_tiles_filled' => array_sum(array_column($plan['scout_summary'], 'filled')),
                    'scout_tiles_hit' => array_sum(array_column($plan['scout_summary'], 'hits')),
                ],
            ]);
        }

        return $recommendations;
    }

    /**
     * Ask the Agent module for a voice on the items we are about to serve
     * (conventions/10).
     *
     * Dispatched, never awaited. The feed goes out now, with the template; the
     * generated line lands on the next read. A user waiting on a model is a user
     * watching a spinner, and the whole product is a promise not to waste their
     * attention.
     *
     * An item that already speaks — a reviewed curated claim — is skipped. A human
     * who read the evidence outranks a model that read the same evidence.
     *
     * @param  list<array<string, mixed>>  $picked
     * @param  array<string, string>  $opportunities  place_id => opportunity_id
     */
    private function requestVoiceFor(array $picked, array $opportunities, ExploreSessionData $session): void
    {
        $partOfDay = match (true) {
            now()->hour < 12 => 'morning',
            now()->hour < 18 => 'afternoon',
            default => 'evening',
        };

        foreach ($picked as $candidate) {
            if (($candidate['curated_claim'] ?? null) !== null) {
                continue;
            }

            GenerateOpportunityVoiceJob::dispatch(
                $opportunities[$candidate['place_id']],
                $partOfDay,
                $session->travelMode->value,
                (int) round((float) $candidate['reachability']['travel_min']),
            );
        }
    }

    /**
     * A compact record of what the reachability gate dropped and why. Capped:
     * a wide coverage disc can exclude thousands, and a trace nobody can read
     * is not observability.
     *
     * @param  list<array<string, mixed>>  $excluded
     * @return array{count: int, sample: list<array<string, mixed>>}
     */
    private function unreachableTrace(array $excluded): array
    {
        $sample = [];

        foreach (array_slice($excluded, 0, 25) as $candidate) {
            $sample[] = [
                'place_id' => $candidate['place_id'],
                'name' => $candidate['name'],
                'reachability' => $candidate['reachability'],
            ];
        }

        return ['count' => count($excluded), 'sample' => $sample];
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

        // Tile-relative and tile-cached (SCORING §2.3, §4.2). u1 needs Google
        // review counts (edge-only, never persisted) and u2 needs embeddings that
        // do not exist yet — both drop out of the weighted sum and discount
        // confidence, which is the designed behaviour for a missing signal (§2.5).
        $uniq = $subScores->uniqueness([
            'u1' => null,
            'u2' => null,
            'u3' => $candidate['u3'] ?? null,
            'u4' => 0.0,                              // evergreen: rarity 0 by definition, not missing
            'u5' => $candidate['u5'] ?? null,
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
            'tiers' => $tiers,   // the Decide evidence gates read these (SCORING §2.1)
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

            if (! isset($byPlace[$id])) {
                $candidate['scouts'] = [$candidate['scout']];
                $byPlace[$id] = $candidate;

                continue;
            }

            $byPlace[$id]['scouts'][] = $candidate['scout'];

            // Union what the later scout knows that the first one did not.
            //
            // This used to `continue` here, which silently dropped everything
            // except the scout's name — and CuratedScout runs last. So for any
            // place another scout had already seen (a lake is found by
            // NatureScout; a gallery by NearbyPlaceScout), the reviewed curated
            // claim was thrown away and the place was served with no voice at
            // all. Curated content is the whole point of the pack.
            foreach ($candidate as $key => $value) {
                if ($key === 'scout' || $key === 'scouts' || $value === null) {
                    continue;
                }

                if (! isset($byPlace[$id][$key]) || $byPlace[$id][$key] === null) {
                    $byPlace[$id][$key] = $value;
                }
            }
        }

        // Uniqueness signals are tile-relative and tile-cached (SCORING §2.3):
        // computed once per tile over EVERY place in it, shared across users.
        // They used to be computed here over the scouted candidate set, which is
        // only the slice of the tile the scouts happened to return — a percentile
        // over a fraction of the population is not a percentile.
        $tiles = array_values(array_unique(array_column($byPlace, 'h3_index')));

        $signals = [];
        foreach ($tiles as $tile) {
            $signals += $this->uniqueness->forTile($tile);
        }

        foreach ($byPlace as $id => $candidate) {
            $byPlace[$id] = [...$candidate, ...($signals[$id] ?? ['u3' => null, 'u5' => null, 'u6' => null])];
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
