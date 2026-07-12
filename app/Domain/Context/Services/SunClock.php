<?php

declare(strict_types=1);

namespace App\Domain\Context\Services;

use Carbon\CarbonImmutable;

/**
 * Where the sun is, computed — never fetched (E16, DATA-SOURCES §2-edge).
 *
 * There is no API here on purpose. Solar geometry is a closed-form function of
 * time and place: an API would add a network dependency, a cost line and a
 * failure mode to a question we can answer exactly, offline, in microseconds. It
 * is also the one "why now" signal that cannot go stale.
 *
 * PHP's date_sun_info() gives sunrise/sunset but not the sun's ELEVATION, and
 * elevation is what golden hour actually is. It matters most exactly where we are
 * going: at Stockholm's latitude in July the naive "golden hour = sunset − 60 min"
 * is badly wrong — the sun crawls along the horizon and the golden light lasts for
 * hours. So we compute elevation properly (NOAA solar position) and read the
 * windows off it.
 */
final class SunClock
{
    /**
     * Geometric sunset: the sun's centre at −0.833°, which accounts for refraction
     * and the solar disc's radius. This is "when the light goes", and it is what a
     * traveller standing at a viewpoint actually cares about.
     */
    private const SUNSET_ELEVATION = -0.833;

    /** Golden hour: warm, low, raking light — sun between 6° and −4° (the photographers' band). */
    private const GOLDEN_UPPER = 6.0;

    private const GOLDEN_LOWER = -4.0;

    /** The sun's elevation above the horizon, in degrees. */
    public function elevation(float $lat, float $lng, CarbonImmutable $at): float
    {
        $utc = $at->utc();

        // Fractional Julian day → the standard NOAA chain.
        $julian = $utc->getTimestamp() / 86400.0 + 2440587.5;
        $t = ($julian - 2451545.0) / 36525.0;   // Julian centuries since J2000

        $meanLong = fmod(280.46646 + $t * (36000.76983 + $t * 0.0003032), 360.0);
        $meanAnom = 357.52911 + $t * (35999.05029 - 0.0001537 * $t);

        $centre = sin(deg2rad($meanAnom)) * (1.914602 - $t * (0.004817 + 0.000014 * $t))
            + sin(deg2rad(2 * $meanAnom)) * (0.019993 - 0.000101 * $t)
            + sin(deg2rad(3 * $meanAnom)) * 0.000289;

        $trueLong = $meanLong + $centre;
        $apparentLong = $trueLong - 0.00569 - 0.00478 * sin(deg2rad(125.04 - 1934.136 * $t));

        $obliquity = 23.0 + (26.0 + (21.448 - $t * (46.815 + $t * (0.00059 - $t * 0.001813))) / 60.0) / 60.0;
        $obliquityCorrected = $obliquity + 0.00256 * cos(deg2rad(125.04 - 1934.136 * $t));

        $declination = rad2deg(asin(sin(deg2rad($obliquityCorrected)) * sin(deg2rad($apparentLong))));

        // Equation of time (minutes): the sun is not a good clock.
        $y = tan(deg2rad($obliquityCorrected / 2)) ** 2;
        $eccentricity = 0.016708634 - $t * (0.000042037 + 0.0000001267 * $t);

        $equationOfTime = 4 * rad2deg(
            $y * sin(2 * deg2rad($meanLong))
            - 2 * $eccentricity * sin(deg2rad($meanAnom))
            + 4 * $eccentricity * $y * sin(deg2rad($meanAnom)) * cos(2 * deg2rad($meanLong))
            - 0.5 * $y * $y * sin(4 * deg2rad($meanLong))
            - 1.25 * $eccentricity * $eccentricity * sin(2 * deg2rad($meanAnom)),
        );

        $minutesUtc = $utc->hour * 60 + $utc->minute + $utc->second / 60.0;
        $trueSolarTime = fmod($minutesUtc + $equationOfTime + 4 * $lng, 1440.0);

        $hourAngle = $trueSolarTime / 4.0 - 180.0;

        $zenith = rad2deg(acos(
            sin(deg2rad($lat)) * sin(deg2rad($declination))
            + cos(deg2rad($lat)) * cos(deg2rad($declination)) * cos(deg2rad($hourAngle)),
        ));

        return 90.0 - $zenith;
    }

    /** Is the sun up right now? */
    public function isDaylight(float $lat, float $lng, CarbonImmutable $at): bool
    {
        return $this->elevation($lat, $lng, $at) > self::SUNSET_ELEVATION;
    }

    /**
     * Minutes of usable light left — the honest number behind "~40 min of light left".
     *
     * Null when the sun is already down (there is no light to run out of) and null
     * in the polar summer, where it never sets: claiming "1,200 minutes of light
     * left" is technically true and useless, and pretending there is a deadline
     * when there isn't one is exactly the kind of manufactured urgency this product
     * refuses to trade in.
     */
    public function minutesOfLightLeft(float $lat, float $lng, CarbonImmutable $at, int $lookaheadMinutes = 16 * 60): ?int
    {
        if (! $this->isDaylight($lat, $lng, $at)) {
            return null;
        }

        $sunset = $this->nextCrossingBelow($lat, $lng, $at, self::SUNSET_ELEVATION, $lookaheadMinutes);

        return $sunset === null ? null : (int) round($at->diffInMinutes($sunset, false));
    }

    /**
     * The evening golden hour, if it is open right now (SCORING §4.3 special-moment
     * floor). Returns the minutes it has left to run, or null if it is not open.
     *
     * Deliberately evening-only: a sunrise at 03:40 in a Stockholm July is not a
     * moment anyone is going to be interrupted for.
     */
    public function goldenHourMinutesLeft(float $lat, float $lng, CarbonImmutable $at, int $lookaheadMinutes = 16 * 60): ?int
    {
        $elevation = $this->elevation($lat, $lng, $at);

        $open = $elevation <= self::GOLDEN_UPPER
            && $elevation >= self::GOLDEN_LOWER
            && $this->isDescending($lat, $lng, $at);

        if (! $open) {
            return null;
        }

        $ends = $this->nextCrossingBelow($lat, $lng, $at, self::GOLDEN_LOWER, $lookaheadMinutes);

        return $ends === null ? null : max(1, (int) round($at->diffInMinutes($ends, false)));
    }

    /** Evening, not morning: the sun is on its way down. */
    private function isDescending(float $lat, float $lng, CarbonImmutable $at): bool
    {
        return $this->elevation($lat, $lng, $at->addMinutes(10)) < $this->elevation($lat, $lng, $at);
    }

    /**
     * When the sun next drops below `$degrees`.
     *
     * Scans forward a minute at a time. Not clever, and deliberately so: a bisection
     * needs a bracketed root, and near the poles in summer there may be no root at
     * all — the naive version returns null there, which is the correct answer.
     */
    private function nextCrossingBelow(float $lat, float $lng, CarbonImmutable $at, float $degrees, int $lookaheadMinutes): ?CarbonImmutable
    {
        for ($minute = 1; $minute <= $lookaheadMinutes; $minute++) {
            $t = $at->addMinutes($minute);

            if ($this->elevation($lat, $lng, $t) <= $degrees) {
                return $t;
            }
        }

        return null;   // it does not set within the horizon — a Nordic summer
    }
}
