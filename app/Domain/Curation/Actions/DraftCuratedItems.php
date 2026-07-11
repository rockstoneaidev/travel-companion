<?php

declare(strict_types=1);

namespace App\Domain\Curation\Actions;

use App\Domain\Curation\Enums\CurationStatus;
use App\Domain\Curation\Models\CuratedItem;
use App\Domain\Curation\Models\Pack;

/**
 * Pipeline step 2 (CURATION §3): drafts from harvested evidence bundles ONLY
 * — never from a blank page (conventions/10). Until E12's Gemini layer, the
 * "LLM" is a template pass over the harvest; either way the row says who
 * authored it and every draft dies in review unless a human approves it.
 */
final class DraftCuratedItems
{
    /**
     * @param  list<array{title: string, claim: string, facets: list<string>, evidence: list<array<string, mixed>>, language?: string}>  $harvested
     * @return list<CuratedItem>
     */
    public function __invoke(string $regionSlug, array $harvested, string $authoredBy = 'llm', ?string $promptVersion = 'template-v0'): array
    {
        $pack = Pack::query()->firstOrCreate(
            ['region_slug' => $regionSlug],
            ['name' => str($regionSlug)->replace('-', ' ')->title()->toString(), 'status' => 'draft'],
        );

        $items = [];
        foreach ($harvested as $candidate) {
            $items[] = CuratedItem::query()->create([
                'pack_id' => $pack->id,
                'title' => $candidate['title'],
                'claim' => $candidate['claim'],
                'facets' => $candidate['facets'],
                'evidence' => $candidate['evidence'],
                'status' => CurationStatus::Draft,
                'authored_by' => $authoredBy,
                'prompt_version' => $authoredBy === 'llm' ? $promptVersion : null,
                'language' => $candidate['language'] ?? 'en',
                'region_slug' => $regionSlug,
            ]);
        }

        return $items;
    }
}
