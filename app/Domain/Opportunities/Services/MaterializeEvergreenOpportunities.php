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
     * @param  list<array{place_id: string, name: string, h3_index: string, walk_minutes?: float, closes_at?: ?string}>  $candidates
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
                    // A daylight place genuinely closes when the light goes (E16). This
                    // is what lets the card count down honestly, and what stops KEPT
                    // from offering to walk you to a viewpoint at midnight.
                    'window_ends_at' => $candidate['closes_at'] ?? null,
                    'expires_at' => now()->addDay(),
                ]);
            } else {
                // The window MOVES: the row was materialised earlier today, and "when
                // the light goes" is a fact about today, not about the row. A stale
                // window is worse than none — it is a countdown to the wrong moment.
                $closesAt = $candidate['closes_at'] ?? null;

                if ($closesAt !== null && (string) $existing->window_ends_at !== (string) $closesAt) {
                    $existing->forceFill(['window_ends_at' => $closesAt])->save();
                }
            }

            if ($existing->summary === null && ($candidate['summary'] ?? null) !== null) {
                // A live opportunity predates the pack: it was materialized before
                // this place had a reviewed claim, and reusing it verbatim would
                // keep serving the place mute until the row expired. Publishing a
                // pack has to show up in the feed now, not tomorrow.
                $existing->forceFill(['summary' => $candidate['summary']])->save();
            }

            $map[$candidate['place_id']] = $existing->id;
        }

        return $map;
    }
}
