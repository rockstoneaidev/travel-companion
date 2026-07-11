<?php

declare(strict_types=1);

namespace App\Domain\Sources\Data;

/**
 * What a scout is asked to look at: a bounding box and the region's language
 * (conventions/09 — scouts query in the local language; PRD §9.4).
 *
 * Phase 1 ingest passes a whole region's bbox; the E5 scout runner will pass
 * per-tile geometry through the same shape.
 */
final readonly class ScoutRequest
{
    public function __construct(
        public string $regionKey,
        public float $south,
        public float $west,
        public float $north,
        public float $east,
        public string $locale,
    ) {}

    public function bboxAsString(): string
    {
        return sprintf('%F,%F,%F,%F', $this->south, $this->west, $this->north, $this->east);
    }
}
