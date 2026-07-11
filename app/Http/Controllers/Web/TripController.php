<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Trips\Actions\UpdateTrip;
use App\Domain\Trips\Models\Trip;
use App\Domain\Trips\Queries\ListTrips;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Trips\IndexTripRequest;
use App\Http\Requests\Api\V1\Trips\UpdateTripRequest;
use App\Http\Resources\Api\V1\TripResource;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class TripController extends Controller
{
    public function index(IndexTripRequest $request, ListTrips $listTrips): Response
    {
        $criteria = $request->toCriteria();

        return Inertia::render('trips/index', [
            'trips' => TripResource::collection($listTrips($criteria)),
            'filters' => $criteria->toArray(),
        ]);
    }

    public function show(Trip $trip): Response
    {
        return Inertia::render('trips/show', [
            'trip' => new TripResource($trip->load('exploreSessions')->loadCount('exploreSessions')),
        ]);
    }

    public function update(UpdateTripRequest $request, Trip $trip, UpdateTrip $updateTrip): RedirectResponse
    {
        $updateTrip($trip, $request->toData());

        return to_route('trips.show', $trip)->with('status', 'trip-updated');
    }
}
