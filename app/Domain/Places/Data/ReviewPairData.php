<?php

declare(strict_types=1);

namespace App\Domain\Places\Data;

/**
 * One undecided review-band pair (ENTITY-RESOLUTION §3 stage 4).
 *
 * The resolver deliberately refuses to guess in this band: a duplicate is
 * annoying, a false merge is corruption. So both places are live and serveable,
 * and a human breaks the tie.
 */
final readonly class ReviewPairData
{
    /** @param array<string, mixed> $signals */
    public function __construct(
        public string $decisionId,
        public string $candidatePlaceId,
        public string $candidatePlaceName,
        public string $candidateSource,
        public string $comparedPlaceId,
        public string $comparedPlaceName,
        public string $comparedSource,
        public ?float $score,
        public ?int $distanceMeters,
        public array $signals,
    ) {}
}
