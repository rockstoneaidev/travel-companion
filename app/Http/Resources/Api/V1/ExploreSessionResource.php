<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExploreSession
 *
 * `origin` and `destination_point` ARE exposed here, unlike a trip's anchor:
 * the client sent them in this same session and the map has to draw them back.
 * They are the user's own declared inputs, not observed location history.
 */
final class ExploreSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trip_id' => $this->trip_id,
            'status' => $this->status->value,
            'origin' => $this->origin?->toArray(),
            'destination_point' => $this->destination_point?->toArray(),
            'time_budget_minutes' => $this->time_budget_minutes,
            'travel_mode' => $this->travel_mode->value,
            // Real phone, or a pin on an operator's map (ADMIN §6)? The client needs to
            // know, because an emulated session must never be told where the BROWSER is.
            'context_source' => $this->context_source->value,
            'heading' => $this->heading,
            'reach_meters' => ExploreSessionData::fromModel($this->resource)->reachMeters(),
            'started_at' => $this->started_at->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),

            'trip' => new TripResource($this->whenLoaded('trip')),
        ];
    }
}
