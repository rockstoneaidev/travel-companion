<?php

declare(strict_types=1);

namespace App\Domain\Opportunities\Services;

use App\Domain\Opportunities\Enums\OpportunityKind;
use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Opportunities\Models\Opportunity;

/**
 * Opportunities' public seam for the ranking pipeline (E7): gives each gated
 * place candidate a live evergreen opportunity row to recommend against
 * (recommendations FK opportunities, PRD §14.2). Idempotent per (place, kind)
 * while live; richer kinds (events, ephemeral) arrive with their own scouts.
 */
final class MaterializeEvergreenOpportunities
{
    /**
     * @param  list<array{place_id: string, name: string, h3_index: string, walk_minutes?: float}>  $candidates
     * @return array<string, string> place_id => opportunity_id
     */
    public function __invoke(array $candidates): array
    {
        $map = [];

        foreach ($candidates as $candidate) {
            $existing = Opportunity::query()
                ->where('place_id', $candidate['place_id'])
                ->where('kind', OpportunityKind::Evergreen)
                ->whereNotIn('status', array_map(
                    static fn (OpportunityStatus $s): string => $s->value,
                    OpportunityStatus::terminal(),
                ))
                ->where('expires_at', '>', now())
                ->first();

            if ($existing === null) {
                $existing = Opportunity::query()->create([
                    'place_id' => $candidate['place_id'],
                    'kind' => OpportunityKind::Evergreen,
                    'status' => OpportunityStatus::Scored,
                    'title' => $candidate['name'],       // LLM/template title arrives with E12
                    'summary' => $candidate['summary'] ?? null,   // reviewed curated claim, when one exists
                    'friction' => ['walk_minutes' => $candidate['walk_minutes'] ?? null],
                    'h3_index' => $candidate['h3_index'],
                    'expires_at' => now()->addDay(),
                ]);
            }

            $map[$candidate['place_id']] = $existing->id;
        }

        return $map;
    }
}
