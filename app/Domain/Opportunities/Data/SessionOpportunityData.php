<?php

declare(strict_types=1);

namespace App\Domain\Opportunities\Data;

use App\Domain\Opportunities\Enums\OpportunityKind;
use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Places\Data\PlaceData;
use Carbon\CarbonImmutable;

/**
 * One item of a session's feed, as the delivery layer sees it.
 *
 * There is deliberately **no score on this DTO.** Scoring and feed selection are
 * E7 (SCORING.md); until then the feed is ordered by distance and says so. When
 * E7 lands it adds the sub-scores and the `scoring_model_version` here, and
 * `ListOpportunitiesForSession` stops being an ordering and starts being a
 * ranking.
 */
final readonly class SessionOpportunityData
{
    public function __construct(
        public string $id,
        public OpportunityKind $kind,
        public OpportunityStatus $status,
        public ?string $title,
        public ?string $summary,
        public PlaceData $place,
        public ?int $distanceMeters,
        public ?CarbonImmutable $windowStartsAt,
        public ?CarbonImmutable $windowEndsAt,
        public CarbonImmutable $expiresAt,
        public ?string $recommendationId = null,   // E7: the trace row feedback posts against
        public ?float $walkMinutes = null,         // Stage-A final approach (reachability trace)
    ) {}
}
