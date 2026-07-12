<?php

declare(strict_types=1);

use App\Domain\Context\Services\LightContextResolver;
use App\Domain\Places\Enums\PlaceType;
use App\Domain\Recommendations\Data\ScoringModel;
use App\Domain\Recommendations\Services\SubScores;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| E16 — the sun, as a scoring input (SCORING §4.3)
|--------------------------------------------------------------------------
|
| Before this, `slack` was always "end of day minus travel". Every candidate got
| the same horizon, so a viewpoint forty minutes before dark scored exactly the
| same urgency as a park that never closes — the GO NOW slot was structurally
| incapable of being RIGHT, which is what E16 exists to fix.
|
| Daylight is the first real closing time in the system: no API, no staleness,
| and simply true.
|
*/

const NICE_LAT = 43.7102;
const NICE_LNG = 7.2620;

it('gives a viewpoint a real closing time — the moment the light goes', function () {
    $resolver = app(LightContextResolver::class);

    // 17:30 UTC = 19:30 local in Nice, early August. Sunset is around 20:50 local.
    $at = CarbonImmutable::parse('2026-08-02 17:30', 'UTC');

    $viewpoint = $resolver->forCandidate(PlaceType::Viewpoint, NICE_LAT, NICE_LNG, $at);

    expect($viewpoint->closesAt)->not->toBeNull()
        ->and($viewpoint->minutesOfLightLeft)->toBeGreaterThan(0)
        ->and($viewpoint->minutesOfLightLeft)->toBeLessThan(120);

    // A museum's evening is not governed by the sun, and its score must not be.
    $museum = $resolver->forCandidate(PlaceType::ArtMuseum, NICE_LAT, NICE_LNG, $at);

    expect($museum->closesAt)->toBeNull()
        ->and($museum->minutesOfLightLeft)->toBeNull()
        ->and($museum->goldenHourOpen())->toBeFalse();
});

it('makes the dusk viewpoint urgent and the noon viewpoint calm — the same place', function () {
    $resolver = app(LightContextResolver::class);
    $subScores = new SubScores(ScoringModel::v1());

    $dusk = CarbonImmutable::parse('2026-08-02 18:20', 'UTC');   // ~20:20 local, light running out
    $noon = CarbonImmutable::parse('2026-08-02 10:00', 'UTC');   // ~12:00 local, all day ahead

    $urgencyAt = function (CarbonImmutable $at) use ($resolver, $subScores): float {
        $light = $resolver->forCandidate(PlaceType::Viewpoint, NICE_LAT, NICE_LNG, $at);

        $closesAt = $light->closesAt !== null && $light->closesAt->isBefore($at->endOfDay())
            ? $light->closesAt
            : $at->endOfDay();

        $slack = max(0.0, $at->diffInMinutes($closesAt, false) - 10.0);   // 10 min walk

        return $subScores->temporalUrgency($slack, specialMomentOpen: $light->goldenHourOpen())['value'];
    };

    // THE POINT OF E16. Same viewpoint, same walk. What differs is the sun.
    expect($urgencyAt($dusk))->toBeGreaterThan($urgencyAt($noon))
        ->and($urgencyAt($dusk))->toBeGreaterThanOrEqual(0.7);   // the special-moment floor
});

it('has something true to say on the card', function () {
    $resolver = app(LightContextResolver::class);

    // Golden hour: a reason, not a deadline.
    $golden = $resolver->forCandidate(PlaceType::Viewpoint, NICE_LAT, NICE_LNG, CarbonImmutable::parse('2026-08-02 18:20', 'UTC'));
    expect($golden->note())->toContain('the light is good');

    // Nothing worth saying at noon — and it says nothing, rather than inventing.
    $noon = $resolver->forCandidate(PlaceType::Viewpoint, NICE_LAT, NICE_LNG, CarbonImmutable::parse('2026-08-02 10:00', 'UTC'));
    expect($noon->note())->toBeNull();
});

it('never manufactures urgency after dark, or where the sun does not set', function () {
    $resolver = app(LightContextResolver::class);

    // The light is already gone. There is no deadline left to be urgent about, and
    // a "0 minutes of light left" countdown would be a nag, not a service.
    $night = $resolver->forCandidate(PlaceType::Viewpoint, NICE_LAT, NICE_LNG, CarbonImmutable::parse('2026-08-02 22:30', 'UTC'));

    expect($night->closesAt)->toBeNull()
        ->and($night->note())->toBeNull()
        ->and($night->goldenHourOpen())->toBeFalse();

    // Tromsø in June: the sun never sets. Manufacturing a deadline is the one thing
    // this product must never do.
    $midnightSun = $resolver->forCandidate(PlaceType::Viewpoint, 69.6492, 18.9553, CarbonImmutable::parse('2026-06-21 12:00', 'UTC'));

    expect($midnightSun->closesAt)->toBeNull()
        ->and($midnightSun->note())->toBeNull();
});
