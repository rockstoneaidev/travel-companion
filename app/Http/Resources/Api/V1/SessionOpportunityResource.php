<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Opportunities\Data\SessionOpportunityData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SessionOpportunityData
 *
 * No `scores` key, on purpose. The wire shape gains sub-scores + composite +
 * `scoring_model_version` when E7 lands (SCORING.md); shipping a placeholder now
 * would teach the client a shape we intend to change.
 */
final class SessionOpportunityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'title' => $this->title,
            'summary' => $this->summary,
            'distance_meters' => $this->distanceMeters,
            'time_window' => [
                'starts_at' => $this->windowStartsAt?->toIso8601String(),
                'ends_at' => $this->windowEndsAt?->toIso8601String(),
            ],
            // The GO NOW slot is the server's call, not the client's: at most one
            // per feed, already promoted to the top (SCREENS S1).
            'urgent' => $this->urgent,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'recommendation_id' => $this->recommendationId,
            'walk_minutes' => $this->walkMinutes,
            'image' => $this->image,
            'place' => new PlaceResource($this->place),
        ];
    }
}
