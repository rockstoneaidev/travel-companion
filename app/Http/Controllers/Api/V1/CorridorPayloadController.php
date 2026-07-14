<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notifications\Services\BuildCorridorPayload;
use App\Domain\Trips\Models\Trip;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * The offline pre-download (E36): the phone grabs this before it loses signal.
 *
 * Serves the cached bundle `RegisterGeofencePayloadJob` built ahead of time, falling back
 * to building it live if the cache is cold. Either way the response is the same shape — a
 * set of pre-authorised geofences and the device budget that governs them.
 */
final class CorridorPayloadController extends Controller
{
    public function show(Trip $trip, BuildCorridorPayload $build): JsonResponse
    {
        $anchor = $trip->anchor_point;
        $payload = Cache::get("corridor-payload:{$trip->id}") ?? $build($anchor?->lat, $anchor?->lng);

        return response()->json($payload);
    }
}
