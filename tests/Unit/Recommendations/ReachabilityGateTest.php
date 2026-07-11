<?php

declare(strict_types=1);

use App\Domain\Recommendations\Services\ReachabilityGate;
use App\Domain\Recommendations\Services\TravelTimeEstimator;
use App\Domain\Trips\Enums\TravelMode;

/*
|--------------------------------------------------------------------------
| Reachability gate — PRD §10 step 8, SCORING §2.1: membership, not ranking
|--------------------------------------------------------------------------
*/

function gate(): ReachabilityGate
{
    $config = require __DIR__.'/../../../config/scoring.php';

    return new ReachabilityGate(new TravelTimeEstimator($config['stage_a']));
}

// Gamla stan → Djurgården: ~2.6 km crow-flies.
const ORIGIN = [59.3255, 18.0705];
const DJURGARDEN = ['lat' => 59.3260, 'lng' => 18.1150, 'type' => 'local_museum'];

it('passes and excludes candidates per the budget/mode matrix', function (string $mode, float $remaining, bool $expected) {
    $result = gate()->evaluate(DJURGARDEN, ORIGIN[0], ORIGIN[1], TravelMode::from($mode), $remaining);

    expect($result->reachable)->toBe($expected);
})->with([
    // walk: ~2.6 km × 1.3 = ~3.4 km ≈ 45 min each way + 60 min museum dwell ≈ 150 min total
    'walk, generous budget' => ['walk', 180.0, true],
    'walk, tight budget' => ['walk', 120.0, false],
    // bike: ~11 min each way + 60 dwell ≈ 82 min
    'bike, fits where walking cannot' => ['bike', 120.0, true],
    'bike, too tight' => ['bike', 70.0, false],
    // drive: ~5 min each way + 60 dwell ≈ 70 min
    'drive, fits' => ['drive', 75.0, true],
]);

it('uses the opportunity dwell override over the type default', function () {
    $quick = [...DJURGARDEN, 'dwell_minutes' => 10];

    // 45 + 10 + 45 ≈ 100 min — the override makes a 120-min walk feasible.
    expect(gate()->evaluate($quick, ORIGIN[0], ORIGIN[1], TravelMode::Walk, 120.0)->reachable)->toBeTrue()
        ->and(gate()->evaluate(DJURGARDEN, ORIGIN[0], ORIGIN[1], TravelMode::Walk, 120.0)->reachable)->toBeFalse();
});

it('continues to the destination instead of returning in destination mode', function () {
    // Candidate sits right next to the destination: continue-onward is nearly
    // free, while a pure-radius return would double the cost.
    $nearDest = ['lat' => 59.3260, 'lng' => 18.1140, 'type' => 'viewpoint']; // dwell 15

    $pureRadius = gate()->evaluate($nearDest, ORIGIN[0], ORIGIN[1], TravelMode::Walk, 75.0);
    $withDest = gate()->evaluate($nearDest, ORIGIN[0], ORIGIN[1], TravelMode::Walk, 75.0, destLat: 59.3260, destLng: 18.1150);

    expect($pureRadius->reachable)->toBeFalse()
        ->and($withDest->reachable)->toBeTrue()
        ->and($withDest->returnMinutes)->toBeLessThan(2.0);
});

it('filters lists and keeps excluded candidates with their trace breakdowns', function () {
    $near = ['lat' => 59.3250, 'lng' => 18.0720, 'type' => 'cafe'];

    $result = gate()->filter([$near, DJURGARDEN], ORIGIN[0], ORIGIN[1], TravelMode::Walk, 60.0);

    expect($result['kept'])->toHaveCount(1)
        ->and($result['excluded'])->toHaveCount(1)
        ->and($result['excluded'][0]['reachability']['reachable'])->toBeFalse()
        ->and($result['excluded'][0]['reachability']['total_min'])->toBeGreaterThan(60);
});

it('depletes the remaining budget with the session clock', function () {
    $start = new DateTimeImmutable('2026-07-12 10:00:00');

    expect(ReachabilityGate::remainingMinutes($start, 180, new DateTimeImmutable('2026-07-12 10:00:00')))->toBe(180.0)
        ->and(ReachabilityGate::remainingMinutes($start, 180, new DateTimeImmutable('2026-07-12 12:00:00')))->toBe(60.0)
        ->and(ReachabilityGate::remainingMinutes($start, 180, new DateTimeImmutable('2026-07-12 14:00:00')))->toBe(0.0);
});
