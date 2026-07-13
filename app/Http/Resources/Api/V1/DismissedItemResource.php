<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Recommendations\Data\DismissedItemData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DismissedItemData */
final class DismissedItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'recommendation_id' => $this->recommendationId,
            'title' => $this->title,
            'note' => $this->note,
            'dismissed_at' => $this->dismissedAt->toIso8601String(),
            'image' => $this->image,
        ];
    }
}
