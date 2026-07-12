<?php

declare(strict_types=1);

namespace App\Domain\Context\Services;

use App\Domain\Context\Data\LightContext;
use App\Domain\Places\Enums\PlaceType;
use Carbon\CarbonImmutable;

/**
 * The sun, turned into scoring inputs (E16, SCORING §4.3).
 *
 * Computed per candidate, never stored: solar geometry is a pure function of time
 * and place, so caching it would be a cache with no upside and a staleness bug
 * waiting in it.
 */
final class LightContextResolver
{
    public function __construct(private readonly SunClock $sun) {}

    public function forCandidate(?PlaceType $type, float $lat, float $lng, CarbonImmutable $at): LightContext
    {
        // A museum does not care what the sun is doing, and neither should its score.
        if ($type === null || ! $type->needsDaylight()) {
            return new LightContext;
        }

        $lightLeft = $this->sun->minutesOfLightLeft($lat, $lng, $at);

        return new LightContext(
            // The place effectively closes when the light goes. Null in the polar
            // summer, and null after dark — in both cases there is no deadline, and
            // inventing one would be manufacturing urgency.
            closesAt: $lightLeft === null ? null : $at->addMinutes($lightLeft),
            minutesOfLightLeft: $lightLeft,
            goldenMinutesLeft: $this->sun->goldenHourMinutesLeft($lat, $lng, $at),
        );
    }
}
