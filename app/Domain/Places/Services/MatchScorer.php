<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Domain\Places\Data\ResolutionCandidate;
use App\Domain\Places\Enums\MatchBand;
use App\Domain\Places\Enums\PlaceTypeDomain;

/**
 * Stage 3+4 of the resolver (ENTITY-RESOLUTION §3): a pure, recorded match
 * score and its threshold band. All constants come from config/resolver.php —
 * changing any of them mints a new resolver_version.
 */
final class MatchScorer
{
    /** @var array<string, mixed> */
    private readonly array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('resolver');
    }

    /**
     * @return array{score: float, band: MatchBand, signals: array<string, float>}
     */
    public function score(ResolutionCandidate $a, ResolutionCandidate $b): array
    {
        $signals = [
            'name_sim' => $this->nameSimilarity($a->names, $b->names),
            'proximity' => $this->proximity($a, $b),
            'type_compat' => $this->typeCompat($a, $b),
            // embed_cos: no embeddings in v1 — absent signals are dropped and
            // the remaining weights renormalized (SCORING §2.5 discipline).
        ];

        $weights = array_intersect_key($this->config['weights'], $signals);
        $totalWeight = array_sum($weights);

        $score = 0.0;
        foreach ($signals as $key => $value) {
            $score += ($weights[$key] / $totalWeight) * $value;
        }

        return ['score' => round($score, 4), 'band' => $this->band($score, $a, $b, $signals), 'signals' => $signals];
    }

    private function band(float $score, ResolutionCandidate $a, ResolutionCandidate $b, array $signals): MatchBand
    {
        $band = match (true) {
            $score >= $this->config['bands']['auto_merge'] => MatchBand::High,
            $score >= $this->config['bands']['review'] => MatchBand::Review,
            default => MatchBand::Distinct,
        };

        // Chain guard (§3 stage 4): identical names > 250 m apart in
        // chain-prone types never auto-merge on name alone.
        if ($band === MatchBand::High && $this->chainGuardApplies($a, $b)) {
            $band = MatchBand::Review;
        }

        return $band;
    }

    private function chainGuardApplies(ResolutionCandidate $a, ResolutionCandidate $b): bool
    {
        $chainTypes = $this->config['chain_guard']['types'];

        if (! in_array($a->type?->value, $chainTypes, true) && ! in_array($b->type?->value, $chainTypes, true)) {
            return false;
        }

        if ($this->distanceMeters($a, $b) <= $this->config['chain_guard']['min_distance_m']) {
            return false;
        }

        foreach ($a->names as $nameA) {
            foreach ($b->names as $nameB) {
                if (self::fold($nameA) === self::fold($nameB)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Max over all name pairs, each compared raw and diacritic-folded
     * (folding is a second comparison, never a replacement — §3 stage 0).
     * Jaro-Winkler primary, trigram as floor.
     *
     * @param  list<string>  $namesA
     * @param  list<string>  $namesB
     */
    public function nameSimilarity(array $namesA, array $namesB): float
    {
        $best = 0.0;

        foreach ($namesA as $nameA) {
            foreach ($namesB as $nameB) {
                $rawA = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $nameA) ?? $nameA));
                $rawB = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $nameB) ?? $nameB));

                $sim = max(
                    self::jaroWinkler($rawA, $rawB),
                    self::jaroWinkler(self::fold($rawA), self::fold($rawB)),
                    self::trigram($rawA, $rawB),
                );

                $best = max($best, $sim);
            }
        }

        return round($best, 4);
    }

    private function proximity(ResolutionCandidate $a, ResolutionCandidate $b): float
    {
        $radius = $this->radiusFor($a, $b);
        $distance = $this->distanceMeters($a, $b);

        return round(max(0.0, 1.0 - min(1.0, $distance / $radius)), 4);
    }

    private function radiusFor(ResolutionCandidate $a, ResolutionCandidate $b): float
    {
        $domain = $a->type?->domain() ?? $b->type?->domain();

        return (float) match ($domain) {
            PlaceTypeDomain::ReligiousSacred, PlaceTypeDomain::HistoricHeritage,
            PlaceTypeDomain::MuseumGallery, PlaceTypeDomain::ArchitectureUrban => $this->config['proximity_radius']['building_scale'],
            PlaceTypeDomain::NatureLandscape, PlaceTypeDomain::ActivityRecreation => $this->config['proximity_radius']['nature_scale'],
            default => $this->config['proximity_radius']['default'],
        };
    }

    private function typeCompat(ResolutionCandidate $a, ResolutionCandidate $b): float
    {
        if ($a->type === null || $b->type === null) {
            return 0.0;
        }

        if ($a->type === $b->type) {
            return (float) $this->config['type_compat']['same_type'];
        }

        if ($a->type->domain() === $b->type->domain()) {
            return (float) $this->config['type_compat']['same_domain'];
        }

        $pair = [$a->type->value, $b->type->value];
        foreach ($this->config['compatible_pairs'] as $compatible) {
            if ($pair === $compatible || array_reverse($pair) === $compatible) {
                return (float) $this->config['type_compat']['compatible_pair'];
            }
        }

        return 0.0;
    }

    public function distanceMeters(ResolutionCandidate $a, ResolutionCandidate $b): float
    {
        $earth = 6371000.0;
        $dLat = deg2rad($b->lat - $a->lat);
        $dLng = deg2rad($b->lng - $a->lng);

        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a->lat)) * cos(deg2rad($b->lat)) * sin($dLng / 2) ** 2;

        return 2 * $earth * asin(min(1.0, sqrt($h)));
    }

    public static function fold(string $value): string
    {
        $folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower($value));

        return $folded === false ? mb_strtolower($value) : strtolower($folded);
    }

    /** Jaro-Winkler similarity, standard 0.1 prefix scale capped at 4 chars. */
    public static function jaroWinkler(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        $lenA = mb_strlen($a);
        $lenB = mb_strlen($b);
        if ($lenA === 0 || $lenB === 0) {
            return 0.0;
        }

        $chairsA = mb_str_split($a);
        $chairsB = mb_str_split($b);
        $window = max(0, intdiv(max($lenA, $lenB), 2) - 1);

        $matchesA = [];
        $matchesB = array_fill(0, $lenB, false);

        foreach ($chairsA as $i => $char) {
            $from = max(0, $i - $window);
            $to = min($lenB - 1, $i + $window);
            for ($j = $from; $j <= $to; $j++) {
                if (! $matchesB[$j] && $chairsB[$j] === $char) {
                    $matchesA[$i] = $char;
                    $matchesB[$j] = true;
                    break;
                }
            }
        }

        $m = count($matchesA);
        if ($m === 0) {
            return 0.0;
        }

        $matchedB = [];
        foreach ($matchesB as $j => $matched) {
            if ($matched) {
                $matchedB[] = $chairsB[$j];
            }
        }

        $transpositions = 0;
        foreach (array_values($matchesA) as $k => $char) {
            if ($char !== $matchedB[$k]) {
                $transpositions++;
            }
        }
        $transpositions = intdiv($transpositions, 2);

        $jaro = ($m / $lenA + $m / $lenB + ($m - $transpositions) / $m) / 3;

        $prefix = 0;
        for ($i = 0; $i < min(4, $lenA, $lenB); $i++) {
            if ($chairsA[$i] === $chairsB[$i]) {
                $prefix++;
            } else {
                break;
            }
        }

        return $jaro + $prefix * 0.1 * (1 - $jaro);
    }

    /** pg_trgm-style trigram similarity (padded, set-based). */
    public static function trigram(string $a, string $b): float
    {
        $grams = static function (string $s): array {
            $padded = '  '.$s.' ';
            $out = [];
            for ($i = 0, $n = mb_strlen($padded) - 2; $i < $n; $i++) {
                $out[mb_substr($padded, $i, 3)] = true;
            }

            return $out;
        };

        $gA = $grams(self::fold($a));
        $gB = $grams(self::fold($b));

        if ($gA === [] || $gB === []) {
            return 0.0;
        }

        $intersection = count(array_intersect_key($gA, $gB));
        $union = count($gA) + count($gB) - $intersection;

        return $union === 0 ? 0.0 : $intersection / $union;
    }
}
