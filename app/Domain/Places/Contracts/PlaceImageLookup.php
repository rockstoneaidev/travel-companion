<?php

declare(strict_types=1);

namespace App\Domain\Places\Contracts;

/**
 * Photos, as other modules may see them (conventions/01).
 *
 * Places owns `place_images`. The feed needs a photo per card and may hold the URL
 * — but it may not reach into another module's Eloquent models to get it, and the
 * arch test caught me doing exactly that (for the third time, which is how you know
 * it is worth having).
 */
interface PlaceImageLookup
{
    /**
     * One photo per place, in ONE query — a card is not worth an N+1.
     *
     * @param  list<string>  $placeIds
     * @return array<string, array{url: string, attribution: ?string, license: ?string}>
     */
    public function forPlaces(array $placeIds): array;
}
