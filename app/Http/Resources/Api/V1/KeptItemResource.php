<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Recommendations\Data\KeptItemData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin KeptItemData */
final class KeptItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'recommendation_id' => $this->recommendationId,
            'opportunity_id' => $this->opportunityId,
            'title' => $this->title,
            'note' => $this->note,
            'location' => ['lat' => $this->lat, 'lng' => $this->lng],
            'kept_at' => $this->keptAt->toIso8601String(),
            'window_ends_at' => $this->windowEndsAt?->toIso8601String(),
            'still_possible' => $this->stillPossible,
            'image' => $this->image,
        ];
    }
}
