<?php

declare(strict_types=1);

use App\Domain\Recommendations\Data\ScoringModel;
use App\Domain\Recommendations\Services\EvidenceGate;

/*
|--------------------------------------------------------------------------
| The Decide evidence gates (SCORING §2.1)
|--------------------------------------------------------------------------
|
| Membership rules, not soft scores. A candidate that fails either gate is
| never served — however well it would otherwise rank.
|
*/

function evidenceGate(): EvidenceGate
{
    return new EvidenceGate(ScoringModel::v1());
}

/** @param list<string> $tiers */
function evidenceCandidate(string $name, float $confidence, array $tiers): array
{
    return [
        'place_id' => $name,
        'name' => $name,
        'tiers' => $tiers,
        'sub_scores' => ['confidence' => $confidence, 'personal_fit' => 0.9],
    ];
}

it('serves a candidate that clears both gates', function () {
    $result = evidenceGate()->partition([evidenceCandidate('Färgfabriken', 0.82, ['open', 'reference'])]);

    expect($result['served'])->toHaveCount(1)
        ->and($result['held'])->toBeEmpty();
});

it('holds a candidate below the 0.25 confidence floor, however well it scores', function () {
    // personal_fit 0.9 — this would rank near the top if confidence were a soft score.
    $result = evidenceGate()->partition([evidenceCandidate('Rumoured speakeasy', 0.24, ['open'])]);

    expect($result['served'])->toBeEmpty()
        ->and($result['held'])->toHaveCount(1)
        ->and($result['held'][0]['hold']['reason'])->toBe('below_confidence_floor')
        ->and($result['held'][0]['hold']['status'])->toBe('watching');
});

it('serves a candidate exactly at the floor — the gate is strictly below', function () {
    $result = evidenceGate()->partition([evidenceCandidate('Borderline', 0.25, ['open'])]);

    expect($result['served'])->toHaveCount(1);
});

it('routes a Tier-D-only candidate to the corroboration queue as a lead', function () {
    // A blog post is the only thing claiming this exists. It is a hypothesis,
    // not an item — no matter how confident the blog sounds.
    $result = evidenceGate()->partition([evidenceCandidate('Reddit-only ruin', 0.40, ['community'])]);

    expect($result['served'])->toBeEmpty()
        ->and($result['held'][0]['hold']['reason'])->toBe('tier_d_only')
        ->and($result['held'][0]['hold']['status'])->toBe('corroboration_queue');
});

it('serves a Tier-D-boosted candidate once a non-D source establishes it', function () {
    // Tier-D still enriches and boosts — it just cannot originate.
    $result = evidenceGate()->partition([evidenceCandidate('Blogged, but also in OSM', 0.61, ['community', 'open'])]);

    expect($result['served'])->toHaveCount(1)
        ->and($result['held'])->toBeEmpty();
});

it('holds a candidate with no evidence at all, and says so', function () {
    $result = evidenceGate()->partition([evidenceCandidate('Ghost', 0.0, [])]);

    expect($result['held'][0]['hold']['reason'])->toBe('no_evidence');
});

it('treats an unclassified source as Tier-D rather than trusting it', function () {
    $result = evidenceGate()->partition([evidenceCandidate('Unknown provenance', 0.9, ['some-new-scraper'])]);

    expect($result['served'])->toBeEmpty()
        ->and($result['held'][0]['hold']['reason'])->toBe('tier_d_only');
});

it('partitions a mixed set without losing anyone', function () {
    $result = evidenceGate()->partition([
        evidenceCandidate('good', 0.80, ['open']),
        evidenceCandidate('lowconf', 0.10, ['reference']),
        evidenceCandidate('donly', 0.40, ['community']),
        evidenceCandidate('official', 0.95, ['official']),
    ]);

    expect(array_column($result['served'], 'name'))->toBe(['good', 'official'])
        ->and(array_column($result['held'], 'name'))->toBe(['lowconf', 'donly']);
});
