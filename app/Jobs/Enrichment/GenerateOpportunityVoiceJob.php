<?php

declare(strict_types=1);

namespace App\Jobs\Enrichment;

use App\Cost\Services\CostMeter;
use App\Domain\Agent\Data\ContextData;
use App\Domain\Agent\Services\AgentOrchestrator;
use App\Domain\Agent\Services\EvidenceBundleBuilder;
use App\Domain\Context\Enums\ContextSource;
use App\Domain\Opportunities\Actions\RecordOpportunityVoice;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Places\Contracts\PlaceLookup;
use App\Domain\Recommendations\Models\Recommendation;
use App\Enums\CostActorKind;
use App\Enums\QueueLane;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Gives one opportunity its voice (conventions/10: never call a model
 * synchronously inside a web request — a user waiting on a model is a user
 * watching a spinner).
 *
 * So the feed is served first, from the template, and the voice arrives after.
 * That ordering is the product decision, not a limitation: silence beats a
 * spinner, and a dull true line beats a beautiful late one.
 *
 * A thin wrapper, as jobs are (conventions/08): it holds no logic worth testing
 * on its own.
 */
final class GenerateOpportunityVoiceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 2;

    /**
     * The last three are the COST correlation (docs/COST.md §5, bug 3).
     *
     * This job is where the product's only real money is actually spent, and it runs
     * minutes after — and in a different process from — the request that dispatched it.
     * Without the ids travelling in the payload, the spend lands in the ledger with no
     * user, no session and no trip attached to it, and "cost per active trip-hour" is
     * unanswerable. They are nullable because a replay or a manual dispatch has no
     * session, and a job that refused to run without one would be a job that stops
     * working the first time you debug it.
     */
    public function __construct(
        public readonly string $opportunityId,
        public readonly string $partOfDay,
        public readonly string $travelMode,
        public readonly ?int $walkMinutes,
        public readonly ?int $forUserId = null,
        public readonly ?string $forSessionId = null,
        public readonly ?string $forTripId = null,
        /*
         * ...and the fourth thing the request could not carry: whether anyone was
         * actually standing there (ADMIN §6, E47).
         *
         * This job is where the product's only real money is spent — see above — so it
         * is also the biggest hole the emulator could have punched in the cost metrics.
         * It hardcoded `CostActorKind::User`, which meant an operator dragging a pin
         * across Stockholm billed their synthetic walk to a real traveller's usage, in
         * the one place where the LLM bill actually lands. The flag has to survive the
         * queue hop for the same reason the ids do.
         */
        public readonly ContextSource $contextSource = ContextSource::Device,
    ) {
        $this->onQueue(QueueLane::Voice->value);
    }

    /**
     * The recommendation this generation was caused by, if we can still name it.
     *
     * Nullable on purpose: a replay or a manual dispatch has no session, and a job that
     * refused to run without one would be a job that stops working the first time you
     * debug it — the same reasoning that made the correlation ids nullable above.
     */
    private function recommendationId(): ?string
    {
        if ($this->forSessionId === null) {
            return null;
        }

        return Recommendation::query()
            ->where('explore_session_id', $this->forSessionId)
            ->where('opportunity_id', $this->opportunityId)
            ->orderByDesc('served_at')
            ->value('id');
    }

    /** A five-item feed re-read on every poll must not queue the same generation five times. */
    public function uniqueId(): string
    {
        return "voice:{$this->opportunityId}";
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(
        EvidenceBundleBuilder $builder,
        AgentOrchestrator $agent,
        RecordOpportunityVoice $record,
        PlaceLookup $places,
        CostMeter $cost,
    ): void {
        // Re-establish the correlation the request could not carry across the queue.
        // Causal attribution (COST.md §2.2): the user whose feed lit the fuse pays, and
        // the fact that the next four travellers past this place will read the same
        // cached line for free is not this row's problem — it is what the amortised view
        // is for. One column never means two things.
        $cost->actingAs($this->contextSource->isReal() ? CostActorKind::User : CostActorKind::AdminEmulated, $this->forUserId)
            ->onTrip($this->forTripId)
            ->onSession($this->forSessionId)
            ->onOpportunity($this->opportunityId)
            /*
             * ...and the CARD, which is where the bill actually belongs.
             *
             * RankSession's cost comment has always promised that the money "ACCRETES to
             * this recommendation's id from whichever process spends it". It did not: the
             * LLM row — the single largest real cost in the product — landed with an
             * opportunity and no recommendation, so "what did this card cost me?" had no
             * answer at all. The emulator asked the question out loud and the column came
             * back zero for every item.
             *
             * The opportunity is shared across users; the recommendation is the one that
             * was served to THIS person in THIS session, which is the thing the spend was
             * caused by (COST.md §2.2, causal truth).
             */
            ->onRecommendation($this->recommendationId());

        $opportunity = Opportunity::query()->find($this->opportunityId);

        // Already speaking? Leave it. A reviewed curated claim outranks a model
        // that read the same evidence (CURATION §3).
        if ($opportunity === null || $opportunity->summary !== null) {
            return;
        }

        $place = $places->findMany([$opportunity->place_id])[$opportunity->place_id] ?? null;

        if ($place === null) {
            return;
        }

        $bundle = $builder->forPlace($opportunity->place_id, new ContextData(
            partOfDay: $this->partOfDay,
            travelMode: $this->travelMode,
            walkMinutes: $this->walkMinutes,
        ));

        $result = $agent->opportunitySummary($bundle, $place->name);

        // Nothing written about this place, or the model failed. Either way the
        // template stands, and the template is always true.
        if ($result === null) {
            return;
        }

        $record($opportunity, $bundle, $result);
    }
}
