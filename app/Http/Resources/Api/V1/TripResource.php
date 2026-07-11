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
            'created_at' => $this->created_at->toIso8601String(),

            'explore_sessions_count' => $this->whenCounted('exploreSessions'),
            'explore_sessions' => ExploreSessionResource::collection($this->whenLoaded('exploreSessions')),
        ];
    }
}
