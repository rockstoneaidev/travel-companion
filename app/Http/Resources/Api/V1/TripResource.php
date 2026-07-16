<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Trips\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Trip
 *
 * Note what is NOT here: `anchor_point`. It is a raw coordinate of the user's
 * own movement and nothing in the UI needs it (conventions/06 — never expose raw
 * location). `clustering_version` is an internal trace field and stays internal.
 */
final class TripResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'source' => $this->source->value,
            'started_at' => $this->started_at?->toIso8601String(),
            'last_session_at' => $this->last_session_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'planned_start_at' => $this->planned_start_at?->toIso8601String(),
            'departs_at' => $this->departs_at?->toIso8601String(),
            // Whether a session can be started here — a boolean, never the raw coordinate
            // (which stays unexposed, per the note above and PRD §16).
            'has_location' => $this->anchor_point !== null,
            'created_at' => $this->created_at->toIso8601String(),

            /*
             * Trip Mode (E29). The client MUST be able to see this and act on it: an app
             * that thinks the companion is on when the server thinks it is off will happily
             * burn a battery in the background for nothing — and, far worse, an app that
             * thinks it is OFF when the server thinks it is on is a consent failure wearing
             * a UI bug's clothing.
             *
             * `trip_mode_started_at` is exposed, not just the boolean, because "since when"
             * is the question a privacy screen has to answer.
             */
            'trip_mode' => $this->inTripMode(),
            'trip_mode_started_at' => $this->trip_mode_started_at?->toIso8601String(),

            'explore_sessions_count' => $this->whenCounted('exploreSessions'),
            'explore_sessions' => ExploreSessionResource::collection($this->whenLoaded('exploreSessions')),
        ];
    }
}
