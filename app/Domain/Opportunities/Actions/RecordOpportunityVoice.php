<?php

declare(strict_types=1);

namespace App\Domain\Opportunities\Actions;

use App\Domain\Agent\Data\EvidenceBundle;
use App\Domain\Agent\Data\GenerationResult;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Opportunities\Models\OpportunityEvidence;
use Illuminate\Support\Facades\DB;

/**
 * Persists a generated voice together with the evidence that produced it
 * (conventions/10, PRD §12).
 *
 * The two are written in one transaction on purpose. A summary without its bundle
 * is a sentence nobody can answer for — and the whole promise of PRD §15 is that,
 * given a recommendation, you can always ask "what did the model actually see?"
 * and get a straight answer.
 */
final class RecordOpportunityVoice
{
    public function __invoke(Opportunity $opportunity, EvidenceBundle $bundle, GenerationResult $result): void
    {
        DB::transaction(function () use ($opportunity, $bundle, $result): void {
            $opportunity->forceFill([
                'summary' => trim((string) $result->output['summary']),
                'prompt_version' => $result->promptVersion,
            ])->save();

            // Replaced wholesale: the evidence that produced the LIVE summary is
            // the evidence we keep. Accumulating stale bundles would make the
            // trace a guess about which one was used.
            OpportunityEvidence::query()->where('opportunity_id', $opportunity->id)->delete();

            foreach ($bundle->items as $item) {
                OpportunityEvidence::query()->create([
                    'opportunity_id' => $opportunity->id,
                    'source' => $item->source,
                    'license' => $item->license->value,
                    'credibility_tier' => $item->credibilityTier->value,
                    'url' => $item->url,
                    'excerpt' => $item->excerpt,
                    'attribution' => $item->attribution,
                    'retrieved_at' => $item->retrievedAt,
                ]);
            }
        });
    }
}
