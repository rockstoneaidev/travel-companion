<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Trips\Actions\CreateTrip;
use App\Domain\Trips\Actions\StartPlannedTrip;
use App\Domain\Trips\Actions\UpdateTrip;
use App\Domain\Trips\Enums\TravelMode;
use App\Domain\Trips\Models\Trip;
use App\Domain\Trips\Queries\ListTrips;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Trips\IndexTripRequest;
use App\Http\Requests\Api\V1\Trips\StoreTripRequest;
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

    /**
     * The planner path (PRD §6.6), which existed in the domain and on /api/v1 and had
     * no door on the web: `CreateTrip` was written, tested and unreachable, and the
     * Trips screen told the user "you never create one" — true of the IMPLICIT path,
     * and false of the product. You could not pre-create the France trip, which is
     * the one trip anybody actually plans in advance.
     *
     * Thin, like every Inertia controller here: same FormRequest, same action, same
     * validation as its /api/v1 twin (CLAUDE.md). A planned trip opens as `planned`,
     * never `active` — "active" begins at the first session, and that is the implicit
     * clustering's decision to make, guarded by a unique index.
     */
    public function store(StoreTripRequest $request, CreateTrip $createTrip): RedirectResponse
    {
        $trip = $createTrip($request->toData());

        return to_route('trips.show', $trip)->with('status', "\"{$trip->name}\" is planned. It becomes active at your first session there.");
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

    /** "Start exploring" — activate the planned trip and drop the user into a live session there. */
    public function start(Trip $trip, StartPlannedTrip $startPlannedTrip): RedirectResponse
    {
        if ($trip->anchor_point === null) {
            return back()->with('error', 'This trip has no location yet — add one before starting.');
        }

        $session = $startPlannedTrip(
            $trip,
            (int) config('trips.session.default_time_budget_minutes', 180),
            TravelMode::Walk,
        );

        return to_route('explore.show', $session->id);
    }
}
