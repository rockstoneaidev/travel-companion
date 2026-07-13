<?php

declare(strict_types=1);

namespace App\Domain\Agent\Services;

use App\Domain\Agent\Data\ContextData;
use App\Domain\Agent\Data\EvidenceBundle;
use App\Domain\Agent\Data\EvidenceItem;
use App\Enums\CredibilityTier;
use App\Enums\SourceLicense;
use App\Support\PlainText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Assembles what the model is allowed to know about one place (conventions/10).
 *
 * This class is the wall. Everything the model can say has to come through here,
 * so what it excludes matters as much as what it includes:
 *
 *   · Only sources we are licensed to hold and quote. Google is edge-only and
 *     never persisted (non-negotiable #2), so it is not here and cannot be.
 *   · Only *prose* evidence — somebody's written claim. A place's type and
 *     coordinates are facts the app already knows and injects itself; feeding
 *     them to the model just invites it to paraphrase them back as discovery.
 *   · Nothing derived. No scores, no confidence, no taste weights. The model
 *     phrases evidence; it does not get to see, or leak, our judgement of it.
 *
 * An empty bundle is a normal outcome, not an error: most places in the world
 * model have nothing written about them, and the honest response to that is the
 * template, not a generated sentence.
 */
final class EvidenceBundleBuilder
{
    public function forPlace(string $placeId, ContextData $context): EvidenceBundle
    {
        return new EvidenceBundle(
            items: [
                ...$this->curatedClaims($placeId),
                ...$this->sourceDescriptions($placeId),
            ],
            context: $context,
        );
    }

    /**
     * A reviewed curated claim — Tier A, and the strongest thing we have. A human
     * read it and approved it (CURATION §3), so the model may lean on it.
     *
     * @return list<EvidenceItem>
     */
    private function curatedClaims(string $placeId): array
    {
        $rows = DB::table('curated_items')
            ->where('place_id', $placeId)
            ->where('status', 'approved')
            ->orderBy('id')
            ->get(['claim', 'evidence', 'updated_at']);

        $items = [];
        foreach ($rows as $row) {
            if (! is_string($row->claim) || trim($row->claim) === '') {
                continue;
            }

            $items[] = new EvidenceItem(
                source: 'curated',
                license: SourceLicense::Own,
                credibilityTier: CredibilityTier::Official,
                excerpt: (string) PlainText::clean($row->claim),
                url: null,
                attribution: null,
                retrievedAt: CarbonImmutable::parse($row->updated_at),
            );
        }

        return $items;
    }

    /**
     * Written descriptions carried by the open sources themselves — today that is
     * the tourism board's own text on a DATAtourisme POI, and Mérimée's dating and
     * protection record on a monument.
     *
     * Both are open-licensed, both have a named author who is not us, and neither
     * is a fact we are asserting. That is exactly what evidence is.
     *
     * @return list<EvidenceItem>
     */
    private function sourceDescriptions(string $placeId): array
    {
        $rows = DB::table('source_items')
            ->join('place_source_ids', function ($join): void {
                $join->on('place_source_ids.source', '=', 'source_items.source')
                    ->on('place_source_ids.external_id', '=', 'source_items.external_id');
            })
            ->where('place_source_ids.place_id', $placeId)
            ->orderBy('source_items.source')
            ->get(['source_items.source', 'source_items.payload', 'source_items.license', 'source_items.credibility_tier', 'source_items.attribution', 'source_items.updated_at']);

        $items = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $excerpt = $this->excerptFrom($row->source, $payload['source_tags'] ?? []);

            if ($excerpt === null) {
                continue;
            }

            $items[] = new EvidenceItem(
                source: (string) $row->source,
                license: SourceLicense::from((string) $row->license),
                credibilityTier: CredibilityTier::from((string) $row->credibility_tier),
                excerpt: $excerpt,
                url: null,
                attribution: $row->attribution,
                retrievedAt: CarbonImmutable::parse($row->updated_at),
            );
        }

        return $items;
    }

    /** @param array<string, mixed> $tags */
    private function excerptFrom(string $source, array $tags): ?string
    {
        $text = match ($source) {
            // The tourism board's own words about its own territory.
            'datatourisme' => $tags['description'] ?? null,

            /*
             * WIKIPEDIA — the narrative layer, and it was invisible here.
             *
             * PackCandidateSelector was taught to accept Wikipedia as evidence (its own
             * comment explains why: DATAtourisme and Mérimée are both FRENCH, so without
             * Wikipedia the home region could never produce a single candidate). This
             * builder never was. So the selector counted 562 Stockholm candidates "with
             * evidence", handed them to the drafter, and the drafter built an EMPTY bundle
             * for every one of them and skipped it as "evidence too thin".
             *
             * Half a fix, and the worst half: the two halves disagreed in a way that
             * reported success. 4,749 stored extracts — the entire narrative layer of every
             * region — could not reach a single draft, and both components were behaving
             * exactly as written.
             *
             * CC BY-SA: quotable WITH attribution, never merged into the core
             * (conventions/09). The attribution rides on the row and into the EvidenceItem.
             */
            'wikipedia' => $tags['description'] ?? null,

            // Mérimée has no prose, but its structured record IS a claim: what the
            // building is, when it was built, when it was protected. Rendered as a
            // sentence so the model reads evidence rather than a database row.
            'merimee' => $this->merimeeSentence($tags),

            default => null,
        };

        if (! is_string($text)) {
            return null;
        }

        // Strip the source's markup BEFORE truncating. Truncating first is how the
        // "[[water sports" bug happened: the cut landed mid-link and orphaned the
        // opening brackets, so nothing downstream could recognise them any more.
        $text = PlainText::clean($text);

        if ($text === null) {
            return null;
        }

        // Long tourism-board copy is mostly marketing; the first few hundred
        // characters carry the substance, and a shorter prompt is a cheaper one.
        return mb_substr($text, 0, 600);
    }

    /** @param array<string, mixed> $tags */
    private function merimeeSentence(array $tags): ?string
    {
        $parts = array_filter([
            is_string($tags['denomination'] ?? null) ? "A protected {$tags['denomination']}" : null,
            is_string($tags['datation'] ?? null) ? "dated {$tags['datation']}" : null,
            is_string($tags['auteur'] ?? null) ? "by {$tags['auteur']}" : null,
            is_string($tags['protection'] ?? null) ? "({$tags['protection']})" : null,
        ]);

        return count($parts) >= 2 ? implode(' ', $parts).'.' : null;
    }
}
