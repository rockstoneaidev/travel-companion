<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Recommendations\Data\VisitPromptData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VisitPromptData */
final class VisitPromptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'recommendation_id' => $this->recommendationId,
            'place_name' => $this->placeName,
            'location' => ['lat' => $this->lat, 'lng' => $this->lng],
        ];
    }
}
