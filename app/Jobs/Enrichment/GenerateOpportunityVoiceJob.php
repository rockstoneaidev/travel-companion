<?php

declare(strict_types=1);

namespace App\Jobs\Enrichment;

use App\Domain\Agent\Data\ContextData;
use App\Domain\Agent\Services\AgentOrchestrator;
use App\Domain\Agent\Services\EvidenceBundleBuilder;
use App\Domain\Opportunities\Actions\RecordOpportunityVoice;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Places\Contracts\PlaceLookup;
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

    public function __construct(
        public readonly string $opportunityId,
        public readonly string $partOfDay,
        public readonly string $travelMode,
        public readonly ?int $walkMinutes,
    ) {}

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
    ): void {
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
