<?php

declare(strict_types=1);

use App\Domain\Recommendations\Data\ScoringModel;
use App\Domain\Recommendations\Services\CompositeScorer;
use App\Domain\Recommendations\Services\FeedSelector;
use App\Domain\Recommendations\Services\SubScores;

/*
|--------------------------------------------------------------------------
| SCORING.md v1 — the model must reproduce the spec's own validation math
|--------------------------------------------------------------------------
*/

it('reproduces the PRD jazz-courtyard validation vector at ≈0.77 (SCORING §8)', function () {
    $model = ScoringModel::v1();
    $scorer = new CompositeScorer($model);
    $subScores = new SubScores($model);

    // The §14.2 example's sub-scores, verbatim.
    $jazz = [
        'personal_fit' => 0.86, 'uniqueness' => 0.74, 'temporal_urgency' => 0.91,
        'route_fit' => 0.70, 'novelty' => 0.80, 'confidence' => 0.82,
    ];

    // Its friction block: travel_minutes 7 vs default tolerance 15, queue low.
    $friction = $subScores->frictionRaw(7.0, 15.0, 0, 'low', 0.0, 0.0);
    expect($friction['value'])->toEqualWithDelta(0.155, 0.005);

    // Warm route vector (α = 1), no repetition.
    $result = $scorer->composite($jazz, 'route', 1.0, $friction['value'], 0.0);

    expect($result['weighted'])->toEqualWithDelta(0.8095, 0.0005)
        ->and($result['composite'])->toEqualWithDelta(0.77, 0.005);
});

it('interpolates cold → warm with α and floors α after calibration', function () {
    $model = ScoringModel::v1();
    $scorer = new CompositeScorer($model);

    // Fresh user, no calibration: fully cold.
    expect($scorer->alpha([], false))->toBe(0.0)
        // Calibration alone floors α at 0.4.
        ->and($scorer->alpha([], true))->toBe(0.4)
        // ~4 visits reach full warm weighting (n_eff 20).
        ->and($scorer->alpha(['visited' => 4], false))->toBe(1.0);

    $cold = $scorer->effectiveWeights('route', 0.0);
    $warm = $scorer->effectiveWeights('route', 1.0);

    expect($cold['personal_fit'])->toBe(0.05)->and($warm['personal_fit'])->toBe(0.30)
        ->and($cold['uniqueness'])->toBe(0.30)->and($warm['uniqueness'])->toBe(0.20)
        // route_fit is identical cold and warm: being on your way is not taste.
        ->and($cold['route_fit'])->toBe($warm['route_fit']);
});

it('renormalizes uniqueness over available signals and reports the gaps', function () {
    $subScores = new SubScores(ScoringModel::v1());

    $result = $subScores->uniqueness(['u1' => null, 'u2' => null, 'u3' => null, 'u4' => 0.0, 'u5' => null, 'u6' => 0.8]);

    // (.15×0 + .10×0.8) / .25 = 0.32
    expect($result['value'])->toEqualWithDelta(0.32, 0.001)
        ->and($result['missing'])->toBe(['u1', 'u2', 'u3', 'u5']);
});

it('caps Tier-D-only confidence at 0.40 and lets freshness multiply', function () {
    $subScores = new SubScores(ScoringModel::v1());

    $dOnly = $subScores->confidence(['community'], 0, [], 0.0);
    $fresh = $subScores->confidence(['open', 'reference'], 0, [], 0.0);
    $stale = $subScores->confidence(['open', 'reference'], 0, [], 1.0);

    expect($dOnly['value'])->toBe(0.4)
        ->and($fresh['value'])->toEqualWithDelta(0.9, 0.001)   // .85 + .05 corrob
        ->and($stale['value'])->toEqualWithDelta(0.45, 0.001); // × 0.5 freshness
});

it('selects a diverse feed: repetition bites and cold sessions probe new facets', function () {
    $model = ScoringModel::v1();
    $selector = new FeedSelector($model, new CompositeScorer($model));

    $mk = fn (string $name, string $domain, array $facets, float $fit): array => [
        'name' => $name, 'type_domain' => $domain, 'facets' => $facets, 'total_minutes' => 60.0,
        'friction_raw' => 0.1,
        'sub_scores' => ['personal_fit' => $fit, 'uniqueness' => .5, 'temporal_urgency' => .3, 'novelty' => 1.0, 'confidence' => .7],
    ];

    // Three near-identical churches and one distinct café: greedy + repetition
    // must not serve three churches in a row.
    $picked = $selector->select([
        $mk('Church A', 'religious_sacred', ['history'], .9),
        $mk('Church B', 'religious_sacred', ['history'], .89),
        $mk('Church C', 'religious_sacred', ['history'], .88),
        $mk('Café D', 'food_drink', ['food_drink'], .70),
    ], 'radius', 1.0, 3);

    expect($picked[0]['name'])->toBe('Church A')
        ->and(collect($picked)->pluck('name'))->toContain('Café D');

    // Cold session (α .2): a candidate whose facets are already covered is
    // skipped in favor of probing something new.
    $cold = $selector->select([
        $mk('Church A', 'religious_sacred', ['history'], .9),
        $mk('Chapel B', 'religious_sacred', ['history'], .89),
        $mk('Viewpoint C', 'nature_landscape', ['scenic'], .5),
        $mk('Café D', 'food_drink', ['food_drink'], .5),
    ], 'radius', 0.2, 3);

    expect(collect($cold)->pluck('name')->all())->toBe(['Church A', 'Viewpoint C', 'Café D']);
});
