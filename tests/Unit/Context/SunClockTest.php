<?php

declare(strict_types=1);

use App\Domain\Context\Services\SunClock;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Solar geometry — computed, never fetched (E16)
|--------------------------------------------------------------------------
|
| Checked against PHP's own date_sun_info(), which is an independent
| implementation: if my NOAA chain and the C library agree on sunset to the
| minute, both are almost certainly right.
|
| This matters more than it looks. "~40 min of light left" is a factual claim
| the product makes to someone standing outdoors, and the LLM is never allowed
| to be the source of a fact (CLAUDE.md). So this has to actually be true.
|
*/

const STOCKHOLM = [59.3293, 18.0686];
const NICE = [43.7102, 7.2620];
const TROMSO = [69.6492, 18.9553];   // above the Arctic circle — the edge case

function sunsetFromPhp(float $lat, float $lng, CarbonImmutable $day): CarbonImmutable
{
    $info = date_sun_info($day->getTimestamp(), $lat, $lng);

    return CarbonImmutable::createFromTimestamp($info['sunset'], 'UTC');
}

it('agrees with the C library on when the sun sets', function (array $place, string $date) {
    [$lat, $lng] = $place;
    $day = CarbonImmutable::parse("{$date} 12:00:00", 'UTC');

    $clock = new SunClock;
    $expected = sunsetFromPhp($lat, $lng, $day);

    // Walk forward from noon and find where our elevation crosses the horizon.
    $minutesLeft = $clock->minutesOfLightLeft($lat, $lng, $day);
    expect($minutesLeft)->not->toBeNull();

    $ours = $day->addMinutes($minutesLeft);

    // Two independent implementations, within a minute of each other.
    expect(abs($ours->diffInMinutes($expected, false)))->toBeLessThanOrEqual(1.0);
})->with([
    'Stockholm, midsummer' => [STOCKHOLM, '2026-06-21'],
    'Stockholm, autumn' => [STOCKHOLM, '2026-10-15'],
    'Stockholm, the trip' => [STOCKHOLM, '2026-07-27'],
    'Nice, the trip' => [NICE, '2026-08-02'],
    'Nice, midwinter' => [NICE, '2026-12-21'],
]);

it('knows the sun is up at noon and down at midnight', function () {
    $clock = new SunClock;
    [$lat, $lng] = NICE;

    expect($clock->isDaylight($lat, $lng, CarbonImmutable::parse('2026-08-02 11:00', 'UTC')))->toBeTrue()
        ->and($clock->isDaylight($lat, $lng, CarbonImmutable::parse('2026-08-02 23:30', 'UTC')))->toBeFalse();
});

it('has no light left to report once the sun is already down', function () {
    $clock = new SunClock;
    [$lat, $lng] = NICE;

    // There is no deadline to be urgent about — the light is already gone.
    expect($clock->minutesOfLightLeft($lat, $lng, CarbonImmutable::parse('2026-08-02 23:30', 'UTC')))->toBeNull();
});

it('refuses to invent a deadline where the sun does not set', function () {
    $clock = new SunClock;
    [$lat, $lng] = TROMSO;

    // Midnight sun. "1,200 minutes of light left" is technically true and useless,
    // and manufacturing urgency is the one thing this product must never do.
    expect($clock->minutesOfLightLeft($lat, $lng, CarbonImmutable::parse('2026-06-21 12:00', 'UTC')))->toBeNull()
        ->and($clock->goldenHourMinutesLeft($lat, $lng, CarbonImmutable::parse('2026-06-21 12:00', 'UTC')))->toBeNull();
});

it('opens golden hour in the evening, not at dawn', function () {
    $clock = new SunClock;
    [$lat, $lng] = NICE;

    // Nice sunset on 2 Aug is ~20:50 local (18:50 UTC). Golden hour runs into it.
    $evening = CarbonImmutable::parse('2026-08-02 18:35', 'UTC');
    $dawn = CarbonImmutable::parse('2026-08-02 04:30', 'UTC');

    $eveningGolden = $clock->goldenHourMinutesLeft($lat, $lng, $evening);

    expect($eveningGolden)->not->toBeNull()
        ->and($eveningGolden)->toBeGreaterThan(0)
        // The sun is at the same height at dawn, but a 06:30-local golden hour is
        // not a moment anyone will be interrupted for.
        ->and($clock->goldenHourMinutesLeft($lat, $lng, $dawn))->toBeNull();
});

it('gives a Stockholm July evening a long golden hour, not sixty minutes', function () {
    $clock = new SunClock;
    [$lat, $lng] = STOCKHOLM;

    // 20:30 local, sun at 4.9° and falling. The naive "golden hour = sunset − 60 min"
    // is badly wrong this far north: the sun crawls along the horizon and the light
    // lasts 91 minutes. Computing elevation properly is what gets this right.
    $golden = $clock->goldenHourMinutesLeft($lat, $lng, CarbonImmutable::parse('2026-07-27 18:30', 'UTC'));

    expect($golden)->not->toBeNull()
        ->and($golden)->toBeGreaterThan(60);

    // ...and it has not opened yet an hour earlier, when the sun is still high (8.3°).
    // A "golden hour" that starts while the sun is high is just a clock, not light.
    expect($clock->goldenHourMinutesLeft($lat, $lng, CarbonImmutable::parse('2026-07-27 18:00', 'UTC')))->toBeNull();
});
