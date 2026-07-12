<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Data;

/**
 * One pending "Were you there?" question (SCREENS S4). Carries the place's
 * coordinates so the client can apply the proximity half of the rule.
 */
final readonly class VisitPromptData
{
    public function __construct(
        public string $recommendationId,
        public string $placeName,
        public float $lat,
        public float $lng,
    ) {}
}
