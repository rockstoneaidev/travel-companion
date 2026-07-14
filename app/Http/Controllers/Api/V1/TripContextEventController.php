<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Context\Actions\RecordTripContext;
use App\Domain\Trips\Models\Trip;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Trips\StoreTripContextEventRequest;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/trips/{trip}/context-events` — the background stream (PRD §13.4, E29).
 *
 * Answers 202 whether or not the event was kept, and SAYS WHICH. A client told "fine"
 * while the server quietly bins its events will keep sending them forever, at whatever
 * interval its summarizer fancies, burning the battery it thinks it is saving. Telling it
 * `not_meaningful` is how it learns to stop — and telling it `home_zone` is not leaking a
 * secret to the user's own phone, it is the promise working where they can see it.
 */
final class TripContextEventController extends Controller
{
    public function store(
        StoreTripContextEventRequest $request,
        Trip $trip,
        RecordTripContext $record,
    ): JsonResponse {
        $result = $record($trip->id, $request->toData());

        return new JsonResponse([
            'recorded' => $result->recorded,
            'reason' => $result->reason,
        ], JsonResponse::HTTP_ACCEPTED);
    }
}
