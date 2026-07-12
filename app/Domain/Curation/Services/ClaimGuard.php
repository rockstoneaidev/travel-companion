<?php

declare(strict_types=1);

namespace App\Domain\Curation\Services;

use App\Domain\Curation\Models\CuratedItem;

/**
 * The rules a machine can check without asking a machine (CURATION §4).
 *
 * These run BEFORE the verifier, and they are deterministic on purpose. A rule that
 * can be decided by looking at the row should never be delegated to a model: the
 * model costs money, can be talked out of its answer, and gives a different verdict
 * on Tuesday. `place_id === null` is not a judgement call.
 *
 * They are also the rules with teeth. The claim prompt already TELLS the model never
 * to state opening hours or prices — and an instruction is not an enforcement. This
 * is the enforcement: a draft that names a price does not reach a traveller no matter
 * how well the verifier likes its prose, because prices go stale and a stale price
 * spoken as fact is the product lying with confidence.
 */
final class ClaimGuard
{
    /**
     * Claims we will not serve regardless of what the evidence says, because they
     * decay: the evidence was true when it was retrieved and is not true now.
     * Hours, prices and "currently open" belong to live sources at the edge
     * (Google, at recommendation time), never to a claim written weeks ago.
     */
    private const PERISHABLE = [
        '/\b(open|opens|opening|closed|closes|closing)\b.{0,20}\b(at|from|until|till|monday|tuesday|wednesday|thursday|friday|saturday|sunday|daily|weekday|weekend)\b/i',
        '/\b\d{1,2}(:\d{2})?\s?(am|pm)\b/i',
        '/\b\d{1,2}[:.]\d{2}\b/',
        '/(€|£|\$)\s?\d/',
        '/\b\d+\s?(euros?|pounds?|dollars?|kronor|sek|eur)\b/i',
        '/\b(free entry|free admission|no admission|admission is|entry is|costs?|priced?|ticket price)\b/i',
    ];

    /**
     * @return list<string> the violations; empty means the row is fit to be verified
     */
    public function violations(CuratedItem $item): array
    {
        $violations = [];

        // Approving an ungrounded claim would serve a place we cannot point at. The
        // human gate already refused this (ReviewCuratedItem); so does the machine.
        if ($item->place_id === null) {
            $violations[] = 'ungrounded: no canonical place';
        }

        $evidence = is_array($item->evidence) ? $item->evidence : [];

        if ($evidence === []) {
            // A claim with no evidence is the model speaking from memory, which is the
            // one thing this architecture exists to prevent (CLAUDE.md, non-negotiable 3).
            $violations[] = 'no evidence: the claim has no source to be traced to';
        }

        foreach ($evidence as $source) {
            if (! is_array($source)) {
                continue;
            }

            // CC BY-SA and ODbL require attribution, and an un-attributable claim cannot
            // be published — this is a licence condition, not a preference.
            if (($source['attribution'] ?? null) === null || ($source['license'] ?? null) === null) {
                $violations[] = 'evidence without licence or attribution';
                break;
            }
        }

        $claim = (string) $item->claim;

        if (trim($claim) === '') {
            $violations[] = 'empty claim';
        }

        foreach (self::PERISHABLE as $pattern) {
            if (preg_match($pattern, $claim) === 1) {
                $violations[] = 'perishable fact (hours, price or availability) — these come from live sources, never from a claim';
                break;
            }
        }

        return $violations;
    }
}
