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
        /*
         * The weekday list needed `s?`, and the omission was not academic. The Musée de
         * la Carte à Jouer draft said "open Wednesdays through Sundays and an admission
         * fee is charged" — two perishable facts in one sentence — and this guard, the
         * one with teeth, matched NEITHER. `\bsunday\b` does not match "Sundays": the
         * word boundary after "sunday" fails against the trailing "s".
         *
         * Only the LLM verifier caught it. The deterministic rule that exists precisely
         * so we do not have to trust a model waved it straight through.
         */
        '/\b(open|opens|opening|closed|closes|closing)\b.{0,25}\b(at|from|until|till|monday|tuesday|wednesday|thursday|friday|saturday|sunday|daily|weekday|weekend)s?\b/i',
        '/\b\d{1,2}(:\d{2})?\s?(am|pm)\b/i',
        '/\b\d{1,2}[:.]\d{2}\b/',
        '/(€|£|\$)\s?\d/',
        '/\b\d+\s?(euros?|pounds?|dollars?|kronor|sek|eur)\b/i',
        '/\b(free entry|free admission|no admission|admission is|entry is|costs?|priced?|ticket price)\b/i',

        // "an admission fee is charged", "entrance fee", "tickets are free" — the same
        // fact wearing a noun instead of a verb.
        '/\b(admission|entrance|entry|ticket)s?\b.{0,20}\b(fee|fees|price|prices|charged?|free)\b/i',
    ];

    /**
     * Cut the perishable SENTENCE out, and keep the rest of the claim.
     *
     * The guard below can only say no, and saying no throws away the place along with
     * the sentence. "Decorated with salvaged sofas and school chairs, this relaxed bistro
     * serves market-fresh lunch dishes. A coffee is €1." is a good claim with a bad
     * sentence stapled to it — rejecting it loses a bistro nobody else will tell you
     * about, over a price we were never allowed to say.
     *
     * So the perishable clause is REMOVED and the claim stands. Deterministic, sentence
     * by sentence, no model involved: we are deleting, not rewriting, and a rewrite is
     * where a model would start inventing again.
     *
     * Returns '' when nothing survives — a "claim" that was only ever a price is not a
     * claim, and the caller drops it.
     */
    public function trimPerishable(string $claim): string
    {
        $claim = trim($claim);

        /*
         * Nothing to cut, nothing to judge.
         *
         * The length floor below exists to catch a FRAGMENT left behind by the cut — it
         * must never be applied to a claim we did not touch. It was, and it silently
         * dropped "A bookbinder still working by hand." for being thirty-four characters
         * long: a perfectly good claim, killed by a rule that had no business reading it.
         */
        if (! $this->isPerishable($claim)) {
            return $claim;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $claim, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $kept = array_values(array_filter(
            $sentences,
            fn (string $sentence): bool => ! $this->isPerishable($sentence),
        ));

        $trimmed = trim(implode(' ', $kept));

        /*
         * A fragment is not a claim.
         *
         * Sentence-splitting on "." is a heuristic, and it mis-splits on abbreviations
         * ("St. Paul's is open at 10am" → "St." + the rest). Dropping the perishable half
         * would leave "St." behind — a stub that reads as a bug and would be spoken to a
         * traveller. Anything this short is discarded rather than served.
         */
        return mb_strlen($trimmed) < self::MIN_CLAIM_CHARS ? '' : $trimmed;
    }

    public function isPerishable(string $text): bool
    {
        foreach (self::PERISHABLE as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Below this, what the CUT left behind is a fragment rather than a sentence.
     *
     * Only ever applied to a claim we trimmed — sentence-splitting on "." mis-handles
     * abbreviations ("St. Paul's is open at 10am" → "St." + the rest), and dropping the
     * perishable half would leave "St." to be read aloud to a traveller. It is not a
     * minimum length for claims in general; a short true claim is still a claim.
     */
    private const MIN_CLAIM_CHARS = 15;

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

        if ($this->isPerishable($claim)) {
            $violations[] = 'perishable fact (hours, price or availability) — these come from live sources, never from a claim';
        }

        return $violations;
    }
}
