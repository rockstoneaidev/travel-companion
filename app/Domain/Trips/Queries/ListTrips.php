<?php

declare(strict_types=1);

namespace App\Domain\Trips\Queries;

use App\Domain\Trips\Data\ListTripsCriteria;
use App\Domain\Trips\Enums\TripStatus;
use App\Domain\Trips\Models\Trip;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * `GET /api/v1/trips` (conventions/07). Scoped to the caller — a trip list is
 * never global.
 */
final class ListTrips
{
    /** @return LengthAwarePaginator<int, Trip> */
    public function __invoke(ListTripsCriteria $criteria): LengthAwarePaginator
    {
        return Trip::query()
            ->where('user_id', $criteria->userId)
            ->when(
                $criteria->statuses !== [],
                fn ($query) => $query->whereIn('status', array_map(
                    fn (TripStatus $status): string => $status->value,
                    $criteria->statuses,
                )),
            )
            ->withCount('exploreSessions')
            ->orderBy($criteria->sortBy->column(), $criteria->sortDir->value)
            ->orderBy('id')                                  // stable tiebreak (conventions/07)
            ->paginate($criteria->perPage)
            ->withQueryString();
    }
}
