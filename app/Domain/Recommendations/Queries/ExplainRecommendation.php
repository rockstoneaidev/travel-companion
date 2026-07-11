<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Queries;

use App\Domain\Recommendations\Models\Recommendation;

/**
 * The "why did I get this?" payload (PRD §14.5) assembled from the stored
 * decision trace — template text over recorded inputs, never generated facts.
 * E12's Gemini voice layer replaces the phrasing; the trace inputs stay.
 */
final class ExplainRecommendation
{
    private const FACET_PHRASES = [
        'history' => 'places with real history',
        'architecture' => 'striking buildings',
        'scenic' => 'good views',
        'spiritual' => 'quiet, sacred rooms',
        'food_drink' => 'good food and drink',
        'local_life' => 'where locals actually go',
        'offbeat' => 'the offbeat and overlooked',
        'romantic' => 'atmospheric corners',
        'active' => 'getting out and moving',
        'educational' => 'learning something on the way',
        'family' => 'places that work for everyone',
        'photogenic' => 'photographs worth taking',
        'craft' => 'things made by hand',
        'nature' => 'green and wild places',
    ];

    /** @return array{why_you: ?string, evidence: list<array{text: string}>} */
    public function __invoke(Recommendation $recommendation): array
    {
        $candidate = $recommendation->score_inputs['candidate'] ?? [];
        $raw = $recommendation->score_inputs['raw'] ?? [];

        return [
            'why_you' => $this->whyYou($raw['personal_fit'] ?? []),
            'evidence' => $this->evidence($candidate, $raw),
        ];
    }

    /** @param array<string, mixed> $fitInputs */
    private function whyYou(array $fitInputs): ?string
    {
        $weights = $fitInputs['weights'] ?? [];
        arsort($weights);

        // Only facets your behavior has actually lifted above neutral — the
        // template never claims taste we have not observed.
        $lifted = array_keys(array_filter($weights, static fn (float $w): bool => $w > 0.55));
        if ($lifted === []) {
            return null;
        }

        $phrases = array_values(array_filter(array_map(
            static fn (string $f): ?string => self::FACET_PHRASES[$f] ?? null,
            array_slice($lifted, 0, 2),
        )));

        return match (count($phrases)) {
            0 => null,
            1 => "You've been saying yes to {$phrases[0]} on this trip.",
            default => "You've been saying yes to {$phrases[0]} and {$phrases[1]} on this trip.",
        };
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $raw
     * @return list<array{text: string}>
     */
    private function evidence(array $candidate, array $raw): array
    {
        $rows = [];

        $sources = $raw['confidence']['tiers'] ?? null;
        $scouts = $candidate['scouts'] ?? [];
        $sourceNames = ['osm' => 'OpenStreetMap', 'overture' => 'Overture Maps', 'wikidata' => 'Wikidata', 'curated' => 'our curators'];

        $named = array_values(array_filter(array_map(
            static fn (string $s): ?string => $sourceNames[$s] ?? null,
            array_keys(array_flip([...($candidate['sources'] ?? []), ...$scouts])),
        )));

        if ($named !== []) {
            $rows[] = ['text' => 'Listed by '.implode(', ', array_slice($named, 0, 3)).' — independently cross-checked'];
        }

        if (isset($raw['confidence']['age_over_ttl'])) {
            $days = (int) round((float) $raw['confidence']['age_over_ttl'] * 30);
            $rows[] = ['text' => 'World-model data refreshed '.($days <= 1 ? 'within the last day' : "{$days} days ago")];
        }

        if (isset($raw['friction']['walk_min'])) {
            $rows[] = ['text' => sprintf('%d min on foot — Stage-A estimate, straight-line corrected', (int) round((float) $raw['friction']['walk_min']))];
        }

        return $rows;
    }
}
