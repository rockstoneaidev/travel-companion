<?php

declare(strict_types=1);

use App\Domain\Places\Data\ResolutionCandidate;
use App\Domain\Places\Enums\MatchBand;
use App\Domain\Places\Enums\PlaceType;
use App\Domain\Places\Services\MatchScorer;

/*
|--------------------------------------------------------------------------
| resolver_version v2 — refit against the gold set (ENTITY-RESOLUTION §6)
|--------------------------------------------------------------------------
|
| v1 left 39 real duplicates unmerged, all of them large buildings whose two
| sources sit 100–150 m apart. v2 widens the building-scale proximity ramp.
| Every version stays reproducible: a stored v1 decision must still be
| explicable by v1's constants.
|
*/

function scorerFor(string $version): MatchScorer
{
    return new MatchScorer(config("resolver.versions.{$version}"));
}

function museum(string $name, float $lat, float $lng): ResolutionCandidate
{
    return new ResolutionCandidate(names: [$name], lat: $lat, lng: $lng, type: PlaceType::ArtMuseum);
}

it('keeps every resolver version reproducible, not just the active one', function () {
    expect(config('resolver.version'))->toBe('v2')
        // v1 had no exact-name rule at all, and stays that way forever.
        ->and(config('resolver.versions.v1.proximity_radius.exact_name_building_scale'))->toBeNull()
        ->and(config('resolver.versions.v2.proximity_radius.exact_name_building_scale'))
        ->toBe(['min_name_sim' => 0.95, 'radius_m' => 250])
        // The base ramp is untouched by v2 — only the exact-name case widens.
        ->and(config('resolver.versions.v2.proximity_radius.building_scale'))->toBe(150)
        // The active version is flattened to the top level for callers.
        ->and(config('resolver.proximity_radius.exact_name_building_scale.radius_m'))->toBe(250);
});

it('v1 refused to merge the same museum described 140 m apart', function () {
    // ~140 m apart: one source has the centroid, the other the entrance.
    $score = scorerFor('v1')->score(
        museum('Moderna museet', 59.32700, 18.08400),
        museum('Moderna Museet', 59.32700, 18.08650),
    );

    expect($score['band'])->not->toBe(MatchBand::High);
});

it('v2 merges it, because a big building is not two places', function () {
    $score = scorerFor('v2')->score(
        museum('Moderna museet', 59.32700, 18.08400),
        museum('Moderna Museet', 59.32700, 18.08650),
    );

    expect($score['band'])->toBe(MatchBand::High)
        ->and($score['score'])->toBeGreaterThanOrEqual(0.82);
});

it('v2 still keeps two similarly-named galleries 80 m apart in review', function () {
    // The trap. Widening the building-scale ramp *unconditionally* lifts this
    // pair from 0.78 to 0.84 and auto-merges two different galleries. That is
    // why the wider radius is anchored to an essentially exact name (0.95) —
    // these score ~0.88, and stay on the tight ramp.
    $a = new ResolutionCandidate(names: ['Galleri Duerr'], lat: 59.3200, lng: 18.0700, type: PlaceType::Gallery);
    $b = new ResolutionCandidate(names: ['Galleri Dover'], lat: 59.3205, lng: 18.0710, type: PlaceType::Gallery);

    expect(scorerFor('v2')->score($a, $b)['band'])->toBe(MatchBand::Review);
});

it('v2 still refuses to merge two different pharmacies standing next to each other', function () {
    // The dangerous case, and the reason v2 widened a radius rather than adding
    // an "identical name ⇒ merge" rule. These are 2 m apart, so proximity was
    // already 1.0 — v2 changes nothing about them. Only the name keeps them apart.
    $a = new ResolutionCandidate(names: ['Apoteket'], lat: 59.31000, lng: 18.02000, type: PlaceType::Pharmacy);
    $b = new ResolutionCandidate(names: ['Kronans Apotek'], lat: 59.31001, lng: 18.02001, type: PlaceType::Pharmacy);

    expect(scorerFor('v2')->score($a, $b)['band'])->not->toBe(MatchBand::High);
});

it('v2 still refuses to merge two identically named branches 200 m apart', function () {
    // Stockholm is full of generic names — "Apoteket" is simply "the pharmacy".
    // An "identical name + close ⇒ merge" rule would have collapsed these two
    // branches into one place. v2 will not: it widened only the building-scale
    // radius, and a pharmacy runs on the default 100 m ramp.
    $a = new ResolutionCandidate(names: ['Apoteket'], lat: 59.31000, lng: 18.02000, type: PlaceType::Pharmacy);
    $b = new ResolutionCandidate(names: ['Apoteket'], lat: 59.31180, lng: 18.02000, type: PlaceType::Pharmacy);

    expect(scorerFor('v2')->score($a, $b)['band'])->not->toBe(MatchBand::High);
});
