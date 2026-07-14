<?php

declare(strict_types=1);

namespace App\Domain\Trips\Data;

use App\Domain\Context\Enums\ContextSource;
use App\Domain\Trips\Enums\TripStatus;
use App\Domain\Trips\Models\Trip;

/**
 * The trip as other modules see it (conventions/01: another module may hold a trip id and
 * this DTO, never the Trip model).
 */
final readonly class TripData
{
    public function __construct(
        public string $id,
        public int $userId,
        public TripStatus $status,
        public bool $inTripMode,
        public ContextSource $contextSource,
    ) {}

    public static function fromModel(Trip $trip): self
    {
        return new self(
            id: $trip->id,
            userId: (int) $trip->user_id,
            status: $trip->status,
            inTripMode: $trip->inTripMode(),
            contextSource: $trip->context_source,
        );
    }
}
