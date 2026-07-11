<?php

declare(strict_types=1);

use App\Domain\Places\Data\ResolutionCandidate;
use App\Domain\Places\Enums\MatchBand;
use App\Domain\Places\Enums\PlaceType;
use App\Domain\Places\Services\MatchScorer;

/*
|--------------------------------------------------------------------------
| Match scorer — ENTITY-RESOLUTION §3 stages 3+4, pure and deterministic
|--------------------------------------------------------------------------
*/

function scorer(): MatchScorer
{
    return new MatchScorer(require __DIR__.'/../../../config/resolver.php');
}

function cand(string $name, float $lat, float $lng, ?PlaceType $type, array $alts = []): ResolutionCandidate
{
    return new ResolutionCandidate([$name, ...$alts], $lat, $lng, $type);
}

it('scores an identical church at the same point as a perfect match', function () {
    $result = scorer()->score(
        cand('Storkyrkan', 59.3255, 18.0705, PlaceType::Church),
        cand('Storkyrkan', 59.3255, 18.0705, PlaceType::Church),
    );

    expect($result['score'])->toBe(1.0)
        ->and($result['band'])->toBe(MatchBand::High);
});

it('auto-merges the same place across sources with a name variant and small drift', function () {
    // OSM "Storkyrkan" vs Wikidata "Sankt Nikolai kyrka (Storkyrkan)" — the
    // alternate-name max is what makes multilingual variants match.
    $result = scorer()->score(
        cand('Storkyrkan', 59.32555, 18.07055, PlaceType::Church, ['Stockholms domkyrka']),
        cand('Sankt Nikolai kyrka', 59.32550, 18.07080, PlaceType::Church, ['Storkyrkan']),
    );

    expect($result['band'])->toBe(MatchBand::High)
        ->and($result['signals']['name_sim'])->toBe(1.0);
});

it('sends plausible-but-uncertain pairs to review, not merge', function () {
    // Same domain, similar-ish names, ~80 m apart: the asymmetric bands
    // exist exactly for this — a false merge is worse than a duplicate.
    $result = scorer()->score(
        cand('Galleri Duerr', 59.3200, 18.0700, PlaceType::Gallery),
        cand('Galleri Dover', 59.3205, 18.0710, PlaceType::Gallery),
    );

    expect($result['band'])->toBe(MatchBand::Review);
});

it('keeps different places distinct', function () {
    $result = scorer()->score(
        cand('Kaffebar Norr', 59.3200, 18.0700, PlaceType::Cafe),
        cand('Antikvariat Söder', 59.3210, 18.0730, PlaceType::AntiqueShop),
    );

    expect($result['band'])->toBe(MatchBand::Distinct);
});

it('never auto-merges identically named chain cafés far apart (chain guard)', function () {
    // Two "Espresso House" 400 m apart are franchises, not one place.
    $a = cand('Espresso House', 59.3200, 18.0700, PlaceType::Cafe);
    $b = cand('Espresso House', 59.3236, 18.0700, PlaceType::Cafe); // ~400 m north

    $result = scorer()->score($a, $b);

    expect($result['band'])->not->toBe(MatchBand::High)
        ->and($result['signals']['name_sim'])->toBe(1.0);
});

it('compares diacritic-folded names as a second signal, not a replacement', function () {
    expect(scorer()->nameSimilarity(['Café Sten Sture'], ['Cafe Sten Sture']))->toBeGreaterThan(0.95);
});

it('renormalizes weights when the embedding signal is absent (SCORING §2.5)', function () {
    // With embed_cos absent, name+proximity+type carry 0.45/0.25/0.15 of an
    // 0.85 pool — a perfect three-signal pair must still reach exactly 1.0.
    $result = scorer()->score(
        cand('Järnpojken', 59.3251, 18.0745, PlaceType::Monument),
        cand('Järnpojken', 59.3251, 18.0745, PlaceType::Monument),
    );

    expect($result['score'])->toBe(1.0);
});

it('computes Jaro-Winkler and trigram reference values', function () {
    expect(round(MatchScorer::jaroWinkler('martha', 'marhta'), 3))->toBe(0.961)
        ->and(MatchScorer::jaroWinkler('abc', 'xyz'))->toBe(0.0)
        ->and(MatchScorer::trigram('same', 'same'))->toBe(1.0)
        ->and(MatchScorer::trigram('word', 'entirely'))->toBe(0.0);
});
