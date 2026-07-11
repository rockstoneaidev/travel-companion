<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Privacy\Actions\DeleteTripLocationHistory;
use App\Domain\Trips\Models\Trip;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * `DELETE /api/v1/trips/{trip}/location-history` (PRD §14.5, §16).
 *
 * Returns the erasure report rather than a bare 204: GDPR Article 5
 * accountability means a deletion should be able to say what it deleted.
 */
final class TripLocationHistoryController extends Controller
{
    public function destroy(Trip $trip, DeleteTripLocationHistory $deleteLocationHistory): JsonResponse
    {
        return new JsonResponse(['data' => $deleteLocationHistory($trip->id)->toArray()]);
    }
}
