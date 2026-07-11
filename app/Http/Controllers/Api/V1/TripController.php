<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Trips\Actions\CreateTrip;
use App\Domain\Trips\Actions\UpdateTrip;
use App\Domain\Trips\Models\Trip;
use App\Domain\Trips\Queries\ListTrips;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Trips\IndexTripRequest;
use App\Http\Requests\Api\V1\Trips\StoreTripRequest;
use App\Http\Requests\Api\V1\Trips\UpdateTripRequest;
use App\Http\Resources\Api\V1\TripResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Trips are read / renamed / ended — and only optionally created (PRD §14.5).
 * There is no `POST /trips/{trip}/start`: in pull-only Phase 1, the first
 * session IS the start.
 */
final class TripController extends Controller
{
    public function index(IndexTripRequest $request, ListTrips $listTrips): AnonymousResourceCollection
    {
        $criteria = $request->toCriteria();

        return TripResource::collection($listTrips($criteria))->additional([
            'meta' => ['filters' => $criteria->toArray()],
        ]);
    }

    public function show(Trip $trip): TripResource
    {
        return new TripResource($trip->loadCount('exploreSessions'));
    }

    public function store(StoreTripRequest $request, CreateTrip $createTrip): TripResource
    {
        return new TripResource($createTrip($request->toData()));
    }

    public function update(UpdateTripRequest $request, Trip $trip, UpdateTrip $updateTrip): TripResource
    {
        return new TripResource($updateTrip($trip, $request->toData()));
    }
}
