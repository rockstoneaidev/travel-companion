<?php

declare(strict_types=1);

namespace App\Domain\Curation\Actions;

use App\Domain\Agent\Data\ContextData;
use App\Domain\Agent\Services\AgentOrchestrator;
use App\Domain\Agent\Services\EvidenceBundleBuilder;
use App\Domain\Curation\Enums\CurationStatus;
use App\Domain\Curation\Models\CuratedItem;
use App\Domain\Curation\Models\Pack;
use App\Domain\Curation\Services\PackCandidateSelector;
use App\Support\PlainText;

/**
 * Fills a pack's review queue from the world model (CURATION §3 steps 2–3, E14).
 *
 * The Stockholm path needed a hand-made harvest file. That does not scale to
 * seven French cities, and it never needed to: E13 put real French evidence into
 * the world model — tourism-board descriptions, ministry protection records — and
 * E12 built the machinery to phrase evidence without inventing.
 *
 * So the harvest IS the world model, and every draft is:
 *
 *   · grounded by construction — the candidate came out of places_core, so
 *     `place_id` is known and there is no fuzzy re-match to get wrong;
 *   · drafted only from stored evidence, with `prompt_version` recorded;
 *   · parked in `in_review`, where it dies unless a human approves it.
 *
 * Nothing this creates can reach a traveller without somebody reading it.
 */
final class DraftPackFromWorldModel
{
    public function __construct(
        private readonly PackCandidateSelector $selector,
        private readonly EvidenceBundleBuilder $bundles,
        private readonly AgentOrchestrator $agent,
        private readonly AutoReviewCuratedItem $autoReview,
    ) {}

    /**
     * @return array{drafted: int, auto_approved: int, skipped: int, considered: int}
     */
    public function __invoke(string $regionKey, int $target): array
    {
        $pack = Pack::query()->firstOrCreate(
            ['region_slug' => $regionKey],
            ['name' => str($regionKey)->replace('-', ' ')->title()->toString(), 'status' => 'draft'],
        );

        // Ask for more than the target: some candidates will yield nothing the
        // model can honestly say, and a short queue is a short pack.
        $candidates = $this->selector->forRegion($regionKey, (int) ceil($target * 1.4));

        $drafted = 0;
        $approved = 0;
        $skipped = 0;

        foreach ($candidates as $candidate) {
            if ($drafted >= $target) {
                break;
            }

            // A pack draft has no situation — it is written once and read for
            // months, so "afternoon" would be a lie by tomorrow.
            $bundle = $this->bundles->forPlace($candidate->placeId, new ContextData(
                partOfDay: 'any',
                travelMode: 'walk',
                walkMinutes: null,
            ));

            $result = $this->agent->curatedClaim($bundle, $candidate->name, $candidate->type);

            if ($result === null) {
                $skipped++;   // nothing the model could say from this evidence

                continue;
            }

            $item = CuratedItem::query()->create([
                'pack_id' => $pack->id,
                'place_id' => $candidate->placeId,   // grounded by construction
                'region_slug' => $regionKey,
                'title' => PlainText::clean((string) $result->output['title']),
                'claim' => PlainText::clean((string) $result->output['claim']),
                'facets' => $result->output['facets'] ?? [],
                'evidence' => $bundle->toArray(),    // what the model actually saw
                'status' => CurationStatus::InReview,
                'authored_by' => 'llm',
                'prompt_version' => $result->promptVersion,
                'language' => 'en',
            ]);

            /*
             * The machine reviewer, immediately (CURATION §4).
             *
             * A draft lands in_review; the verifier reads it against the very evidence
             * bundle the writer saw and either waves it through or leaves it for a human.
             * The human gate had approved 149 items and rejected zero — that is not a
             * gate, it is a queue, and the work it was doing (does the claim say only
             * what the evidence says?) is mechanical enough to be done properly by
             * something that does not get tired at midnight.
             */
            $verdict = ($this->autoReview)($item);

            if ($verdict['status'] === CurationStatus::Approved) {
                $approved++;
            }

            $drafted++;
        }

        return [
            'drafted' => $drafted,
            'auto_approved' => $approved,
            'skipped' => $skipped,
            'considered' => count($candidates),
        ];
    }
}
