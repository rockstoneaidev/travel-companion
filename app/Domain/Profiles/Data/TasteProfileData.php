<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Data;

/**
 * The scorer's read-only view of a user's learned taste (SCORING §2.3 "user
 * scope") — Profiles' public shape, so other modules never touch the model.
 */
final readonly class TasteProfileData
{
    /**
     * @param  array<string, float>  $facetWeights
     * @param  array<string, int>  $eventCounts
     */
    public function __construct(
        public array $facetWeights,
        public array $eventCounts,
        public int $walkToleranceMinutes,
        public int $priceBand,
        public bool $calibrated,
    ) {}
}
