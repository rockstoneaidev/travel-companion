<?php

declare(strict_types=1);

namespace App\Domain\Context\Data;

use Carbon\CarbonImmutable;

/**
 * Verified opening hours, at the edge (E16, conventions/09).
 *
 * This object never reaches a database. It is fetched at recommendation time,
 * used to decide, and discarded — the only Google-derived value that may be
 * stored anywhere is the `place_id` string itself (conventions/09, ODBL-REVIEW
 * §6). Not the name, not the rating, and specifically not these hours, "just for
 * a day".
 *
 * `$known === false` is the common case and a perfectly good answer: most of the
 * long tail has no hours anywhere. Unknown must never be read as closed — that
 * would silently delete the entire OSM long tail from the feed, which is the
 * layer the product exists for.
 */
final readonly class OpeningHours
{
    public function __construct(
        public bool $known = false,
        public bool $openNow = false,
        public ?CarbonImmutable $closesAt = null,
    ) {}

    /** Do we know, for a fact, that this place is shut right now? */
    public function definitelyClosed(): bool
    {
        return $this->known && ! $this->openNow;
    }

    /** @return array<string, mixed> The decision trace (PRD §15) — times, not content. */
    public function toTrace(): array
    {
        return [
            'known' => $this->known,
            'open_now' => $this->known ? $this->openNow : null,
            'closes_at' => $this->closesAt?->toIso8601String(),
        ];
    }
}
