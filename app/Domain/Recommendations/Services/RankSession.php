<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Services;

use App\Domain\Context\Data\WeatherContext;
use App\Domain\Context\Services\GoogleHoursVerifier;
use App\Domain\Context\Services\LightContextResolver;
use App\Domain\Context\Services\WeatherClient;
use App\Domain\Feedback\Services\FeedbackLedger;
use App\Domain\Opportunities\Services\MaterializeEvergreenOpportunities;
use App\Domain\Places\Enums\PlaceType;
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
use Illuminate\Support\Facades\Log;

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
        private readonly LightContextResolver $light,
        private readonly WeatherClient $weather,
        private readonly GoogleHoursVerifier $hours,
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
        // Second precision, deliberately.
        //
        // `served_at` is a timestamp(0) — the database truncates it. So a serve
        // taken at microsecond precision is replayed from a clock up to a second
        // EARLIER than the one it actually ran on, and temporal_urgency is a
        // function of that clock. Measured: the replay produced a different
        // composite than the original serve in ~7% of instants.
        //
        // That is not a rounding nit. The replayer exists to answer "did my change
        // alter what we serve" (PRD §15.2), and it was answering "yes" one time in
        // fourteen for a pipeline that had not changed at all. A tool that lies at
        // that rate is worse than no tool, because people believe it.
        //
        // Truncating here makes the stored clock exactly the clock we ranked on.
        $at ??= now()->toImmutable()->startOfSecond();
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

        // One call per TILE, not per candidate and never per user (conventions/12):
        // everyone standing in this hex is standing under the same sky.
        $weather = $this->weather->forTile($coverage->originCell, $session->origin->lat, $session->origin->lng);

        $scored = [];

        foreach ($gated['kept'] as $candidate) {
            $scored[] = $this->score($candidate, $session, $subScores, $profile->facetWeights, $profile->walkToleranceMinutes, $remaining, $tripEvents, $at, $weather);
        }

        // Decide (PRD §10 step 10): evidence gates decide membership, before
        // selection ever sees a candidate — a held item must not merely rank low.
        $decided = $evidence->partition($scored);

        $feedSize = (int) config('trips.session.feed_size');
        $picked = $selector->select($decided['served'], $context, $alpha, $feedSize);

        // Verify-before-recommend (conventions/09, conventions/12) — the last gate.
        //
        // Run AFTER selection, on purpose: hours cost a paid Google call each, so we
        // verify the handful we are about to serve, not the hundreds we scored. It
        // also means the cost of the feed is bounded by its size rather than by how
        // dense the city is.
        $picked = $this->verifyOpenNow($picked, $decided['served'], $feedSize, $at);

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
            // The exact clock this plan was ranked on. persist() stores it as
            // `served_at`, and the replayer reads it back — so the replay runs on
            // the very same instant, not on a nearby one.
            'at' => $at,
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
            // When the light goes — a real closing time for a daylight place (E16).
            'closes_at' => $c['light']?->closesAt?->toDateTimeString(),
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
                // The clock we RANKED on, not the clock we happen to be writing at.
                // These differ by however long the rank took, and the replayer
                // replays from this column — so writing now() here would hand the
                // replayer a clock the pipeline never actually used (PRD §15.2).
                'served_at' => $plan['at'],
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

    /**
     * Rain, as friction (SCORING §4.7 — the `weather` term the model already had a
     * slot for and nothing was filling).
     *
     * It is not a veto. A wet day is a reason to prefer the cloister to the
     * clifftop, not a reason to tell someone to stay in their hotel — they are on
     * holiday and it rains in Normandy. So an outdoor place takes the full penalty
     * and an indoor one takes a smaller one, because you still get wet walking
     * there, and the ranking sorts it out from there.
     *
     * Unknown weather scores 0: a missing signal is not evidence of rain.
     */
    private function weatherFriction(WeatherContext $weather, ?PlaceType $type): float
    {
        if (! $weather->known() || ! $weather->isWet()) {
            return 0.0;
        }

        return $type?->needsDaylight() === true ? 1.0 : 0.35;
    }

    /**
     * Drop what we can VERIFY is shut, and backfill from what we already scored.
     *
     * "We do not tell a user a place is open on the strength of a day-old cache"
     * (conventions/12) — so the check happens here, at serve time, against a
     * short-TTL edge cache.
     *
     * Unknown is not closed. Most of the OSM long tail has no hours published
     * anywhere, and treating silence as "shut" would quietly delete the entire long
     * tail from the feed — the exact layer this product exists to surface. So only a
     * definite, verified "closed" removes anything.
     *
     * @param  list<array<string, mixed>>  $picked
     * @param  list<array<string, mixed>>  $servable  everything the evidence gates allowed
     * @return list<array<string, mixed>>
     */
    private function verifyOpenNow(array $picked, array $servable, int $feedSize, CarbonImmutable $at): array
    {
        $open = [];
        $rejected = [];

        // Bounded: a city where everything is shut must not turn one feed into fifty
        // paid calls walking down the candidate list.
        $budget = $feedSize + 3;

        $queue = [...$picked, ...array_filter(
            $servable,
            static fn (array $c): bool => ! in_array($c['place_id'], array_column($picked, 'place_id'), true),
        )];

        foreach ($queue as $candidate) {
            if (count($open) >= $feedSize || $budget <= 0) {
                break;
            }

            $budget--;

            $hours = $this->hours->forPlace(
                (string) $candidate['place_id'],
                (string) $candidate['name'],
                (float) $candidate['lat'],
                (float) $candidate['lng'],
                $at,
            );

            $candidate['raw_inputs']['hours'] = $hours->toTrace();

            if ($hours->definitelyClosed()) {
                $rejected[] = $candidate['name'];

                continue;
            }

            $open[] = $candidate;
        }

        if ($rejected !== []) {
            Log::info('verify-before-recommend dropped closed places', ['places' => $rejected]);
        }

        return $open;
    }

    /** The nearer of two closings — a null closing is no closing, not an early one. */
    private function earliest(CarbonImmutable $a, ?CarbonImmutable $b): CarbonImmutable
    {
        return $b !== null && $b->isBefore($a) ? $b : $a;
    }

    /** @param array<string, mixed> $candidate */
    private function score(array $candidate, ExploreSessionData $session, SubScores $subScores, array $facetWeights, int $tolerance, float $remaining, array $tripEvents, CarbonImmutable $at, WeatherContext $weather): array
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

        /*
         * Phase 1 horizon (SCORING §4.3): last_feasible_start is the opportunity's
         * OWN closing, bounded by end of the session's day.
         *
         * The closing used to be missing entirely — every candidate got
         * `slack = end of day`, so a viewpoint forty minutes before dark scored
         * exactly the same urgency as a park that never closes. The GO NOW slot was
         * therefore incapable of being *right*, which is the whole point of E16.
         *
         * Daylight is the first real closing time we have, and the most honest one:
         * it needs no API, it cannot go stale, and it is simply true. Google-verified
         * opening hours narrow it further where we have them.
         */
        $travel = (float) $candidate['reachability']['travel_min'];
        $type = $candidate['type'] !== null ? PlaceType::from($candidate['type']) : null;

        $light = $this->light->forCandidate($type, (float) $candidate['lat'], (float) $candidate['lng'], $at);

        $closesAt = $this->earliest($at->endOfDay(), $light->closesAt);
        $slack = max(0.0, $at->diffInMinutes($closesAt, false) - $travel);

        // The special-moment floor is NOT a deadline — it is a reason. The light is
        // good now and it will not be later, and that is worth interrupting for even
        // when there are hours of slack left (SCORING §4.3).
        // ...but golden hour under a lid of cloud is NOT golden. The sun can be at
        // exactly the right angle and the light still be flat grey. "The light is
        // good right now" is a factual claim, and we do not make factual claims we
        // cannot support — so geometry alone may not raise the floor.
        $specialMoment = $light->goldenHourOpen() && $weather->lightIsGood();

        $urgency = $subScores->temporalUrgency($slack, specialMomentOpen: $specialMoment);
        $scores['temporal_urgency'] = $urgency['value'];
        $raw['temporal_urgency'] = [...$urgency['inputs'], 'light' => $light->toTrace(), 'weather' => $weather->toTrace()];
        $candidate['light'] = $light;

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
        $friction = $subScores->frictionRaw($walkMinutes, (float) $tolerance, null, 'low', $this->weatherFriction($weather, $type), 0.0);

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
