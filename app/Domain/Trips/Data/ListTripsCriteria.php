<?php

declare(strict_types=1);

namespace App\Domain\Trips\Data;

use App\Domain\Trips\Enums\TripSortField;
use App\Domain\Trips\Enums\TripStatus;
use App\Enums\SortDirection;

/**
 * `GET /api/v1/trips` (conventions/07).
 */
final readonly class ListTripsCriteria
{
    /** @param list<TripStatus> $statuses */
    public function __construct(
        public int $userId,
        public array $statuses = [],
        public TripSortField $sortBy = TripSortField::LastSessionAt,
        public SortDirection $sortDir = SortDirection::Desc,
        public int $perPage = 25,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => array_map(fn (TripStatus $status): string => $status->value, $this->statuses),
            'sort_by' => $this->sortBy->value,
            'sort_dir' => $this->sortDir->value,
            'per_page' => $this->perPage,
        ];
    }
}
