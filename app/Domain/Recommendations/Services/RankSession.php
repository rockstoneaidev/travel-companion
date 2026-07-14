<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Services;

use App\Cost\Services\CostMeter;
use App\Domain\Context\Data\WeatherContext;
use App\Domain\Context\Services\GoogleHoursVerifier;
use App\Domain\Context\Services\LightContextResolver;
use App\Domain\Context\Services\WeatherClient;
use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Feedback\Services\FeedbackLedger;
use App\Domain\Opportunities\Services\MaterializeEvergreenOpportunities;
use App\Domain\Places\Enums\PlaceType;
use App\Domain\Places\Services\CoverageGeometry;
use App\Domain\Places\Services\ScoutRunner;
use App\Domain\Places\Services\TileUniquenessSignals;
use App\Domain\Privacy\Services\HomeZone;
use App\Domain\Profiles\Services\TasteProfiles;
use App\Domain\Recommendations\Data\ScoringModel;
use App\Domain\Recommendations\Enums\ServeReason;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Services\SessionWeatherLog;
use App\Domain\Trips\Services\StayHorizon;
use App\Jobs\Enrichment\GenerateOpportunityVoiceJob;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
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
        private readonly SessionWeatherLog $sessionWeather,
        private readonly SessionAnchor $anchor,
        private readonly StayHorizon $horizon,
    ) {}

    /**
     * The ranked feed for a session — the LATEST serve batch, re-serving first if
     * the user has moved or dismissed their way below a full menu (E46).
     *
     * This used to serve once and replay the same rows forever, which is the bug the
     * founder walked into in Stockholm: the feed you got in Liljeholmen was still the
     * feed you had in Hornstull, and a dismissed card left a hole that never filled.
     * PRD §8.1 always said the menu refreshes ("re-opening the app yields a fresh
     * menu, scored against the remaining budget") — it simply was not wired.
     *
     * So a pull is now allowed to *do* something. Three things, in priority order:
     *
     *   1. MOVED  → rank a new batch from where they now are (§9.2).
     *   2. THINNED → top the current batch back up to feed_size.
     *   3. neither → replay, exactly as before.
     *
     * Only one of those can fire per pull, and (1) subsumes (2): a re-anchored batch
     * is a full menu by construction, so there is nothing to backfill.
     *
     * @return list<Recommendation>
     */
    public function feedFor(ExploreSessionData $session): array
    {
        // Name the work before spending on it. The HTTP middleware knows the user; only
        // here do we know which trip and session the money is being spent for, and a
        // ledger row that cannot say which session it belongs to cannot answer the one
        // question the whole thing exists for — cost per active trip-hour (PRD §14.3).
        $this->cost->onTrip($session->tripId)->onSession($session->id);

        $batch = $this->latestBatch($session->id);

        if ($batch === []) {
            return $session->origin === null
                ? []
                : $this->serve($session, ServeReason::Initial);
        }

        /*
         * An ended or expired session is a record, not a feed. It must still REPLAY —
         * the KEPT screen and the trip journal read these rows, and "why did I get
         * this?" has to keep answering — but nothing may re-rank it. A session whose
         * budget ran out an hour ago has no "remaining budget" to score against, and
         * re-serving one would quietly resurrect a dead session as a live one.
         */
        if (! $session->isLive()) {
            return $this->withoutDismissed($batch);
        }

        $at = now()->toImmutable()->startOfSecond();
        $opening = $batch[0];

        /*
         * No budget left, no re-rank.
         *
         * PRD §8.1 re-serves "against the REMAINING budget", and when that is zero there
         * is nothing to rank: every candidate fails the reachability gate by definition,
         * so the pipeline would run in full and return an empty feed. Which is precisely
         * what it did — a session whose three hours had quietly run out re-anchored on
         * the next pull, found nothing reachable, and BLANKED a feed that was showing
         * five cards.
         *
         * (Such a session is usually `expired` too; this does not depend on the reaper
         * having got to it. A budget that ran out is a budget that ran out.)
         */
        if (ReachabilityGate::remainingMinutes($session->startedAt, $session->timeBudgetMinutes, $at) <= 0) {
            return $this->withoutDismissed($batch);
        }

        $moved = $this->anchor->driftedFrom(
            $session,
            $opening->anchor,
            $opening->served_at,
            $this->serveCount($session->id),
            $at,
            $this->dryUntil($session->id),
        );

        if ($moved !== null) {
            // `$opening->serve_group` is the state our decision to re-anchor was based on.
            // If it has changed by the time we hold the lock, the decision is stale.
            $fresh = $this->serve($session->reAnchoredAt($moved), ServeReason::MoveReanchor, $at, $opening->serve_group);

            if ($fresh !== []) {
                return $fresh;
            }

            /*
             * Empty can mean two very different things, and telling them apart is what
             * stops a race from looking like an outage.
             *
             * Another request may have been serving this session while we waited on the
             * lock — in which case there IS a fresh batch, it simply is not ours. Show
             * theirs. Only if the group has not moved are we genuinely somewhere with
             * nothing to say.
             */
            $current = $this->latestBatch($session->id);

            if ($current !== [] && $current[0]->serve_group > $opening->serve_group) {
                return $this->withoutDismissed($current);
            }

            /*
             * We moved somewhere we have nothing to say about — the launch region has an
             * edge, and the user just walked over it (PRD §8.1, "graceful degradation
             * elsewhere"). Two things must NOT happen here, and both did:
             *
             *  1. The feed must not go empty. Degrading gracefully means keeping the last
             *     menu we could actually stand behind, not deleting it. Blanking the
             *     screen is not honesty about coverage, it is a regression.
             *  2. We must not re-rank on the next pull, and the one after that. A serve
             *     that picks nothing writes no rows, so `served_at` never advances and
             *     the min-interval guard never trips — every pull would run the whole
             *     pipeline again, forever, for a user standing still outside the region.
             *     So we remember the dry run and hold off, exactly as if we had served.
             */
            $this->markDry($session->id);
        }

        // "Not for me" has to survive a reload, and the replay is where it was being
        // lost: a dismissal that only hid the card client-side came straight back on
        // the next GET. Filtered on the way out rather than deleted or flagged on the
        // row: the recommendation is the decision trace (PRD §15.1) and must keep
        // saying what we served, even after the user tells us they didn't want it.
        $alive = $this->withoutDismissed($batch);

        if (count($alive) < (int) config('trips.session.feed_size') && $session->origin !== null) {
            $anchor = $opening->anchor ?? $session->origin;

            // Backfill ranks from where the batch was anchored, NOT from wherever the
            // user is now. If they had moved, we would have taken the re-anchor branch
            // above; since they haven't, the menu they are looking at is still the menu
            // for here, and the card sliding into the gap belongs to it.
            $topUp = $this->serve($session->reAnchoredAt($anchor), ServeReason::DismissBackfill, $at);

            return [...$alive, ...$topUp];
        }

        return $alive;
    }

    /**
     * Rank and persist one batch — the single write path for every serve reason.
     *
     * `$session` arrives already re-anchored: its `origin` IS the anchor to rank
     * from, so nothing below this line has any concept of the user having moved.
     * That is the whole trick of E46 — movement is expressed as a different origin,
     * and the pipeline that was written for one origin needed no notion of a second.
     *
     * @return list<Recommendation>
     */
    public function serve(
        ExploreSessionData $session,
        ServeReason $reason,
        ?CarbonImmutable $at = null,
        ?int $seenGroup = null,
    ): array {
        if ($session->origin === null) {
            return [];
        }

        $at ??= now()->toImmutable()->startOfSecond();

        /*
         * ONE SERVE AT A TIME, PER SESSION.
         *
         * Found by driving the emulator: a five-item feed came back with ten rows, every
         * position duplicated — Centralbadsparken above Centralbadsparken. Two requests
         * had raced in here, both read `max(serve_group)` as 1, both concluded they were
         * group 2, both planned, both persisted.
         *
         * The window is not a hair's breadth: `plan()` warms scouts, verifies hours and
         * scores hundreds of candidates — it takes SECONDS. Any two overlapping pulls
         * (the map view and the feed view; a page and its poll; a client that retries)
         * could do this, and in the emulator, where the phone pane and the console poll
         * the same session, it happened on the first walk anyone took.
         *
         * So the read and the write happen inside one lock, and everything decided from
         * the read — the group number, the backfill's size, the exclusion set — is
         * computed here, after we hold it. `block()` rather than `get()`: the second
         * caller should WAIT and then discover there is nothing left to do, not silently
         * skip a serve the user is waiting for.
         */
        $lock = Cache::lock("serve:lock:{$session->id}", 60);

        try {
            $lock->block(15);
        } catch (LockTimeoutException) {
            // Fifteen seconds and still someone else's turn. Whatever they are writing is
            // the batch the caller wants anyway; let it show what exists rather than
            // queueing a second rank behind a first that has evidently gone slowly.
            return [];
        }

        try {
            return $this->servedUnderLock($session, $reason, $at, $seenGroup);
        } finally {
            $lock->release();
        }
    }

    /** @return list<Recommendation> */
    private function servedUnderLock(
        ExploreSessionData $session,
        ServeReason $reason,
        CarbonImmutable $at,
        ?int $seenGroup,
    ): array {
        $group = $this->latestGroup($session->id);

        /*
         * THE WORLD MOVED WHILE WE WAITED. Our decision is stale; drop it.
         *
         * This is the check that actually holds, and the interval one below is not a
         * substitute for it. Observed while driving the emulator: two pulls arrive
         * together, the first takes the lock and spends TEN SECONDS ranking, the second
         * wakes up and asks "has anyone served within the last 8 seconds?" — and the
         * answer is no, because `served_at` is the clock from the START of that rank.
         * So it served again: two batches, two bills, one walk.
         *
         * Comparing the group instead compares what we DECIDED ON against what is now
         * true, which is the actual question, and it does not care how long the other
         * rank took.
         */
        if ($seenGroup !== null && $group !== $seenGroup) {
            return [];
        }

        /*
         * Re-check the guard now that we hold the lock — this is the half that actually
         * kills the duplicate.
         *
         * We may have queued behind the very request whose work makes ours redundant: it
         * decided to re-anchor at the same moment, from the same fix, and has just
         * written the batch. Serving again would produce a second, identical group.
         * `driftedFrom()` already refuses to re-serve inside the interval; the only
         * reason it said yes to us is that when we asked, the other serve had not landed.
         *
         * ONLY for the automatic path. A manual refresh is a person pressing a button,
         * and the interval is a courtesy to a reader, not a rule about the world — so it
         * may not overrule someone who explicitly asked for a fresh menu. They may not
         * have moved a metre; they may simply have eaten. (RefreshSessionFeed has its own
         * ceiling, `max_serves_per_session`, which is the guard that belongs on it.)
         */
        if ($reason === ServeReason::MoveReanchor && $group > 0) {
            $last = Recommendation::query()
                ->where('explore_session_id', $session->id)
                ->where('serve_group', $group)
                ->max('served_at');

            $interval = $this->anchor->minIntervalSeconds($session);

            if ($last !== null && CarbonImmutable::parse($last)->diffInSeconds($at, absolute: true) < $interval) {
                return [];
            }
        }
        $feedSize = (int) config('trips.session.feed_size');

        /*
         * A dismissal is forever, for this session, and it is keyed on the PLACE.
         *
         * `withoutDismissed()` filters recommendation ROWS, which was sufficient when a
         * session had exactly one batch. It is not sufficient now: the next batch ranks
         * the same candidate pool afresh, so the café you just said "not for me" to
         * would be re-picked as a brand-new row that carries no dismissal — and come
         * straight back, one card lower. The exclusion has to follow the place, not the
         * row that happened to carry it.
         */
        $exclude = $this->dismissedPlaceIds($session->id);

        if ($reason->opensNewGroup()) {
            $group += 1;
            $offset = 0;
            $limit = $feedSize;
        } else {
            // A backfill joins the batch on screen. It may only add what is missing,
            // and it must not add a second copy of a card already sitting there.
            $inGroup = $this->batchFor($session->id, $group);
            $offset = $this->highestPosition($session->id, $group);
            $limit = $feedSize - count($this->withoutDismissed($inGroup));
            $exclude = array_values(array_unique([...$exclude, ...$this->placeIdsOf($inGroup)]));

            if ($limit <= 0) {
                return [];
            }
        }

        $plan = $this->plan($session, $at, null, $exclude, $limit);

        if ($plan['picked'] === []) {
            // Nothing left to say. Honest silence beats an empty batch row, and the
            // group counter must not advance on a serve that served nothing —
            // otherwise `max_serves_per_session` burns down on failures.
            return [];
        }

        return DB::transaction(fn (): array => $this->persist($session, $plan, $reason, $group, $offset));
    }

    /**
     * The pure planning pass (PRD §15.2): compute what WOULD be served, under
     * an injectable clock and scoring model — the replayer's entry point.
     * Warms the shared tile cache but never writes recommendations.
     *
     * @param  list<string>  $excludePlaceIds  places this session may not offer again (E46: dismissed, or already in the batch being topped up)
     * @param  int|null  $feedSize  how many to pick; null = the configured menu size. A backfill asks for fewer.
     * @return array{picked: list<array<string, mixed>>, held: list<array<string, mixed>>, model: ScoringModel, alpha: float, context: string, scout_summary: array, rank_ms: int}
     */
    public function plan(
        ExploreSessionData $session,
        ?CarbonImmutable $at = null,
        ?ScoringModel $modelOverride = null,
        array $excludePlaceIds = [],
        ?int $feedSize = null,
    ): array {
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

        /*
         * Excluded before scoring, for the same reason the home zone is (below): a
         * place the user has dismissed must never be scored, never be learned from,
         * and never re-enter the funnel as a near-miss the digest could resurface.
         * "Not for me" is not "rank this lower" — it is "stop showing me this".
         *
         * The count is kept for the trace: PRD §15.3 says a bounded pipeline must say
         * so out loud, and "we had 40 candidates, you had already refused 6 of them"
         * is exactly the kind of silent narrowing that section exists to prevent.
         */
        $excluded = 0;

        if ($excludePlaceIds !== []) {
            $before = count($candidates);
            $candidates = array_values(array_filter(
                $candidates,
                static fn (array $c): bool => ! in_array($c['place_id'], $excludePlaceIds, true),
            ));
            $excluded = $before - count($candidates);
        }

        $remaining = ReachabilityGate::remainingMinutes($session->startedAt, $session->timeBudgetMinutes, $at);
        $gated = $this->gate->filter(
            $candidates, $session->origin->lat, $session->origin->lng, $session->travelMode,
            $remaining, $session->destinationPoint?->lat, $session->destinationPoint?->lng,
        );

        $tripEvents = $this->tripHistory($session->tripId, $at);

        /*
         * Sensitive-zone suppression (PRD §16). Nothing inside the user's declared
         * home zone is served — being told about the café at the end of your own
         * street is not a travel recommendation.
         *
         * Applied HERE, before scoring, rather than as a filter on the way out: a
         * suppressed place must never be scored, never be learned from, and never
         * appear in a decision trace. Ranking it and then hiding it would leave it
         * in the funnel, which is a record of where someone lives.
         */
        $homeZone = HomeZone::forUser($session->userId);

        if ($homeZone->declared()) {
            $gated['kept'] = array_values(array_filter(
                $gated['kept'],
                static fn (array $c): bool => ! $homeZone->contains((float) $c['lat'], (float) $c['lng']),
            ));
        }

        // One call per TILE, not per candidate and never per user (conventions/12):
        // everyone standing in this hex is standing under the same sky. The hex is all
        // Open-Meteo gets — we used to hand it the session origin, which is a person.
        $weather = $this->weather->forTile($coverage->originCell);

        // Write down the sky we ranked under. Free, in the only sense that matters: the
        // weather is already fetched (it feeds `weather_c`), so this is a write, not a
        // call. It is the difference between a journal that can say "it rained the
        // afternoon you were in Dijon" and one that can only offer `weather_c: 0` — a
        // coefficient that means "dry" and "we never knew" with equal confidence.
        $this->sessionWeather->record($session->id, $weather);

        $scored = [];

        foreach ($gated['kept'] as $candidate) {
            $scored[] = $this->score($candidate, $session, $subScores, $profile->facetWeights, $profile->walkToleranceMinutes, $remaining, $tripEvents, $at, $weather);
        }

        // Decide (PRD §10 step 10): evidence gates decide membership, before
        // selection ever sees a candidate — a held item must not merely rank low.
        $decided = $evidence->partition($scored);

        $feedSize ??= (int) config('trips.session.feed_size');

        /*
         * VERIFY, THEN RE-SELECT (conventions/09, conventions/12).
         *
         * The first version of this verified the picked items and then stapled
         * replacements onto the end, straight out of the servable pool. Those
         * replacements had never been through FeedSelector — so they carried no
         * `composite` and none of its selection metadata, and they bypassed the
         * diversity and α logic entirely. In the console that throws; in production
         * "Undefined array key" is a WARNING, so it quietly served items with a null
         * composite and nobody noticed. The replayer noticed.
         *
         * So a place we can verify is SHUT is excluded from the pool, and the feed is
         * selected again from what remains. Selection stays the selector's job.
         *
         * Bounded to two rounds: hours are cached per place, so the re-verify is cheap,
         * and a city where everything is closed must not turn one feed into a walk down
         * the whole candidate list.
         */
        $closed = [];
        $picked = [];

        for ($round = 0; $round < 2; $round++) {
            $pool = $closed === []
                ? $decided['served']
                : array_values(array_filter(
                    $decided['served'],
                    static fn (array $c): bool => ! in_array($c['place_id'], $closed, true),
                ));

            $picked = $selector->select($pool, $context, $alpha, $feedSize, $this->domainsSeenToday($session->tripId, $at));

            $shut = $this->verifyOpenNow($picked, $at);

            if ($shut === []) {
                break;
            }

            $closed = [...$closed, ...$shut];
        }

        $picked = array_values(array_filter(
            $picked,
            static fn (array $c): bool => ! in_array($c['place_id'], $closed, true),
        ));

        /*
         * The near-misses (PRD §12.4 — the digest release valve).
         *
         * "Opportunities that don't clear the feed bar don't die." They were reachable,
         * they cleared the evidence gates, they were scored — they simply lost to four
         * or five better things. Those are the most valuable items in the whole
         * pipeline that nobody ever sees, and they were being DROPPED on the floor.
         */
        $pickedIds = array_column($picked, 'place_id');
        $nearMisses = array_values(array_filter(
            $decided['served'],
            static fn (array $c): bool => ! in_array($c['place_id'], $pickedIds, true),
        ));

        return [
            'picked' => $picked,
            'held' => $decided['held'],
            'near_misses' => $nearMisses,
            // Candidates the reachability gate dropped, with their breakdowns.
            // The gate computed these and they were being thrown away, so a trace
            // could never answer "why was this not offered to me" — which is the
            // only question a decision trace exists to answer (PRD §15.1).
            'unreachable' => $this->unreachableTrace($gated['excluded']),
            // Coverage honesty (PRD §15.3): how many candidates this session is no
            // longer allowed to offer, because the user already said no to them.
            'excluded_count' => $excluded,
            // The res-8 cell we ranked from — the coarse survivor of the anchor once
            // PRD §16's 30-day retention has hard-deleted the coordinate itself.
            'origin_cell' => $coverage->originCell,
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
     * @param  int  $group  the batch these rows belong to
     * @param  int  $positionOffset  a backfill appends after the positions already in the batch
     * @return list<Recommendation>
     */
    private function persist(
        ExploreSessionData $session,
        array $plan,
        ServeReason $reason,
        int $group,
        int $positionOffset,
    ): array {
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
                'position' => $positionOffset + $position + 1,
                // The batch this row belongs to, why it was served, and where it was
                // ranked FROM (E46). The anchor is `$session->origin` because a
                // re-anchored serve is literally the same session with a different
                // origin — see serve(). It is NOT `explore_sessions.origin`, which
                // means "where the session started" and never moves.
                'serve_group' => $group,
                'serve_reason' => $reason,
                // Was anyone actually standing here? (ADMIN §6.) The learner, the
                // gold-trace recorder and the cost metrics all read this column; a serve
                // that cannot say whether it was real is a serve that will be counted.
                'context_source' => $session->contextSource,
                'anchor' => $session->origin,
                'anchor_h3_index' => $plan['origin_cell'],
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
                        // Candidates this session may no longer offer because they were
                        // dismissed (or are already on screen, for a backfill). Recorded
                        // so the trace cannot silently narrow (PRD §15.3).
                        'excluded' => $plan['excluded_count'],
                        'unreachable' => $plan['unreachable'],
                        'held' => array_map(static fn (array $c): array => [
                            'place_id' => $c['place_id'], 'name' => $c['name'], 'hold' => $c['hold'],
                        ], $plan['held']),
                        // Scored, servable, and beaten. The digest's raw material (§12.4).
                        'near_misses' => array_map(static fn (array $c): array => [
                            'place_id' => $c['place_id'], 'name' => $c['name'],
                            'composite' => $c['composite'] ?? null,
                        ], $plan['near_misses']),
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
                // SERVE-PATH cost only, and the name matters (docs/COST.md §9, bug 3).
                //
                // This blob used to carry `llm_tokens`, and it was structurally always
                // zero: the voice is generated by a job that is dispatched and never
                // awaited (correctly — a user waiting on a model is a user watching a
                // spinner), so the tokens are spent later, in another process, minutes
                // after this row is written. A cost field on the serve path cannot
                // possibly know them. It was not a bug in the number; it was a bug in
                // the belief that cost can be written once, at serve time.
                //
                // So the field is gone, and the money lives in `cost_events`, which
                // ACCRETES to this recommendation's id from whichever process spends it.
                // What stays here is what the serve path actually knows: how wide it
                // fanned out and how long it took — a latency record, not a bill.
                'cost' => [
                    'api_calls' => $this->cost->apiCalls(),
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

            // The correlation ids travel WITH the job (COST.md §5). The tokens are spent
            // in another process, minutes from now, and without these the ledger row
            // would land with no user, no trip and no session on it — which is precisely
            // how the old serve-path cost blob came to be structurally zero. Cost accretes
            // to ids; the ids have to be carried to where the money is actually spent.
            /*
             * AFTER COMMIT, and it matters more than it looks.
             *
             * `requestVoiceFor()` runs inside persist()'s transaction, before the
             * recommendation rows are committed. Dispatched immediately, the job can start
             * on another worker before that commit lands — and it now looks its
             * recommendation up by (session, opportunity) to bill the LLM spend to the
             * right card. It would find nothing, and the single largest real cost in the
             * product would go back to being unattributable.
             */
            GenerateOpportunityVoiceJob::dispatch(
                $opportunities[$candidate['place_id']],
                $partOfDay,
                $session->travelMode->value,
                (int) round((float) $candidate['reachability']['travel_min']),
                $session->userId,
                $session->id,
                $session->tripId,
                $session->contextSource,
            )->afterCommit();
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
     * Which of these can we VERIFY are shut right now?
     *
     * "We do not tell a user a place is open on the strength of a day-old cache"
     * (conventions/12) — so the check happens at serve time, against a short-TTL edge
     * cache, and only on the handful we are about to serve.
     *
     * UNKNOWN IS NOT CLOSED. Most of the OSM long tail has no hours published
     * anywhere, and treating silence as "shut" would quietly delete the entire long
     * tail — the exact layer this product exists to surface. Only a definite,
     * verified "closed" excludes anything.
     *
     * @param  list<array<string, mixed>>  $picked
     * @return list<string> place ids we know are shut
     */
    private function verifyOpenNow(array $picked, CarbonImmutable $at): array
    {
        $shut = [];

        foreach ($picked as $candidate) {
            $hours = $this->hours->forPlace(
                (string) $candidate['place_id'],
                (string) $candidate['name'],
                (float) $candidate['lat'],
                (float) $candidate['lng'],
                $at,
            );

            if ($hours->definitelyClosed()) {
                $shut[] = (string) $candidate['place_id'];
            }
        }

        if ($shut !== []) {
            Log::info('verify-before-recommend excluded closed places', ['places' => $shut]);
        }

        return $shut;
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

        /*
         * ...and the STAY-AWARE horizon on top of it (E38, SCORING §4.3). When the trip
         * knows when it ends, `last_feasible_start` stops being "before bedtime" and
         * becomes "before you leave the region" — which is what makes an evergreen place
         * calm on day one and everything urgent on the last morning, with no rule anywhere
         * that mentions either. A trip with no declared departure falls straight back to
         * the line above.
         */
        $closesAt = $this->horizon->lastFeasibleStart(
            $session->tripId,
            $at,
            $at->endOfDay(),
            $light->closesAt,
        );

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

    /**
     * "We just tried to re-serve this session and had nothing to offer."
     *
     * The cost brake on the edge of the launch region. A fruitless rank writes no rows,
     * so it leaves no `served_at` behind and the min-interval guard has nothing to bite
     * on — without this marker, a user standing outside our coverage would re-run the
     * entire pipeline on every single pull.
     *
     * In the cache rather than a column: it is a rate limiter, not a fact about the
     * session, and losing it to a Redis flush costs one extra rank.
     */
    private function markDry(string $sessionId): void
    {
        $seconds = (int) config('trips.reanchor.min_interval_seconds');

        Cache::put("serve:dry:{$sessionId}", true, $seconds);
    }

    private function dryUntil(string $sessionId): bool
    {
        return Cache::get("serve:dry:{$sessionId}", false) === true;
    }

    /**
     * The batch currently on screen: the highest serve group, in order (E46).
     *
     * Superseded groups are not deleted and not returned. They stay exactly as they
     * were written, because they are the record of what we served at the time — and
     * PRD §15.1 does not let us rewrite that just because the user has since walked
     * somewhere else.
     *
     * @return list<Recommendation>
     */
    private function latestBatch(string $sessionId): array
    {
        $group = $this->latestGroup($sessionId);

        return $group === 0 ? [] : $this->batchFor($sessionId, $group);
    }

    /** @return list<Recommendation> */
    private function batchFor(string $sessionId, int $group): array
    {
        return Recommendation::query()
            ->where('explore_session_id', $sessionId)
            ->where('serve_group', $group)
            ->orderBy('position')
            ->get()
            ->all();
    }

    /** 0 when the session has never been served. */
    private function latestGroup(string $sessionId): int
    {
        return (int) Recommendation::query()
            ->where('explore_session_id', $sessionId)
            ->max('serve_group');
    }

    private function highestPosition(string $sessionId, int $group): int
    {
        return (int) Recommendation::query()
            ->where('explore_session_id', $sessionId)
            ->where('serve_group', $group)
            ->max('position');
    }

    /**
     * How many times this session has been ranked — the ceiling behind
     * `max_serves_per_session`. Counts RANK PASSES, not batches: a backfill is a
     * rank, it costs what a rank costs, and it must count against the budget even
     * though it joins an existing group.
     */
    private function serveCount(string $sessionId): int
    {
        return (int) Recommendation::query()
            ->where('explore_session_id', $sessionId)
            ->distinct()
            ->count('served_at');
    }

    /**
     * The places this session has already been told "no" to.
     *
     * Keyed on the place, not the recommendation: the next batch re-ranks the same
     * candidate pool from scratch, so a row-level dismissal would be invisible to it
     * and the refused café would walk straight back into the feed as a fresh row.
     *
     * @return list<string>
     */
    private function dismissedPlaceIds(string $sessionId): array
    {
        $all = Recommendation::query()
            ->where('explore_session_id', $sessionId)
            ->get()
            ->all();

        if ($all === []) {
            return [];
        }

        $aliveIds = array_map(
            static fn (Recommendation $r): string => $r->id,
            $this->withoutDismissed($all),
        );

        return $this->placeIdsOf(array_values(array_filter(
            $all,
            static fn (Recommendation $r): bool => ! in_array($r->id, $aliveIds, true),
        )));
    }

    /**
     * @param  list<Recommendation>  $recommendations
     * @return list<string>
     */
    private function placeIdsOf(array $recommendations): array
    {
        $ids = [];

        foreach ($recommendations as $recommendation) {
            $placeId = $recommendation->score_inputs['candidate']['place_id'] ?? null;

            if (is_string($placeId)) {
                $ids[] = $placeId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Drop the ones they said "Not for me" to — latest-wins over {dismissed,
     * undismissed}, exactly as KEPT settles {saved, unsaved} (ListKeptForUser).
     * A dismissal is retracted by a later `undismissed`, never by deletion: the
     * ledger is append-only, and it is the moat (PRD §14.5).
     *
     * @param  list<Recommendation>  $recommendations
     * @return list<Recommendation>
     */
    private function withoutDismissed(array $recommendations): array
    {
        if ($recommendations === []) {
            return [];
        }

        $events = app(FeedbackLedger::class)->eventsForRecommendations(
            array_map(static fn (Recommendation $r): string => $r->id, $recommendations)
        );

        return array_values(array_filter(
            $recommendations,
            static function (Recommendation $recommendation) use ($events): bool {
                $latest = null;

                // eventsForRecommendations() is ordered by occurred_at, so the last
                // toggle we see is the one that stands.
                foreach ($events[$recommendation->id] ?? [] as $event) {
                    if (FeedbackEvent::tryFrom($event['event'])?->togglesDismiss() === true) {
                        $latest = $event['event'];
                    }
                }

                return $latest !== FeedbackEvent::Dismissed->value;
            }
        ));
    }

    /** @return list<array{type: ?string, type_domain: ?string, event: string, age_days: float}> */
    /**
     * What have we already shown this person TODAY? (E38; SCORING §5.2.)
     *
     * The repetition penalty was session-scoped, which was the right Phase 1 answer to the
     * right Phase 1 question — *no three churches in one five-item feed*. But a trip is not
     * a feed. Across a day of pulling the app out every hour, a session-scoped penalty
     * resets every time, and the traveller gets a church at ten, a church at noon and a
     * church at three, each of them individually well-behaved.
     *
     * Day-scoped, the count carries. The third church of the DAY is penalised like the
     * third church of a feed, because from the traveller's side of the screen it is the
     * same experience.
     *
     * Distinct PLACES, not rows: a card re-served across four pulls of the living feed is
     * one church, and counting it four times would blacklist a domain for the crime of the
     * user having refreshed.
     *
     * @return array<string, int> type_domain → how many distinct places of it we served today
     */
    private function domainsSeenToday(string $tripId, CarbonImmutable $at): array
    {
        $rows = Recommendation::query()
            ->where('trip_id', $tripId)
            ->whereNotNull('served_at')
            ->where('served_at', '>=', $at->startOfDay())
            /*
             * STRICTLY BEFORE this decision — not "today so far".
             *
             * The replayer found this, which is exactly what it is for. A replay re-ranks a
             * past serve at its original instant, and that serve's own rows are sitting in
             * the table with `served_at` equal to the instant we are ranking at. Counting
             * them would let the batch penalise itself: the replay would see three churches
             * it is in the middle of deciding to serve, and refuse to serve them — so a
             * pipeline with no changes in it would report a diff, and the one tool that
             * exists to tell us whether we broke ranking would have broken itself.
             *
             * "What had we already shown them at the moment of this decision" is both the
             * replayable definition and the true one.
             */
            ->where('served_at', '<', $at)
            ->get(['opportunity_id', 'score_inputs']);

        $seen = [];
        $counted = [];

        foreach ($rows as $row) {
            $candidate = $row->score_inputs['candidate'] ?? [];
            $domain = $candidate['type_domain'] ?? null;
            $placeId = $candidate['place_id'] ?? $row->opportunity_id;

            if ($domain === null || isset($counted[$placeId])) {
                continue;
            }

            $counted[$placeId] = true;
            $seen[$domain] = ($seen[$domain] ?? 0) + 1;
        }

        return $seen;
    }

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
