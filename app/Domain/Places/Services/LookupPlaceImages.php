<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Domain\Places\Contracts\PlaceImageLookup;
use App\Domain\Places\Models\PlaceImage;

/** Places' implementation of the photo lookup (conventions/01). */
final class LookupPlaceImages implements PlaceImageLookup
{
    public function forPlaces(array $placeIds): array
    {
        if ($placeIds === []) {
            return [];
        }

        return PlaceImage::query()
            ->whereIn('place_id', $placeIds)
            ->where('url', '!=', '')
            ->orderBy('id')
            ->get()
            ->groupBy('place_id')
            ->map(static fn ($group): array => [
                'url' => (string) $group->first()->url,
                // Attribution travels WITH the image, never as a page footer somebody
                // eventually forgets to render (ODBL-REVIEW §6, conventions/06).
                'attribution' => $group->first()->attribution,
                'license' => $group->first()->license,
            ])
            ->all();
    }
}
