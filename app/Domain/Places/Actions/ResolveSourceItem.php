<?php

declare(strict_types=1);

namespace App\Domain\Places\Actions;

use App\Domain\Places\Data\ResolutionCandidate;
use App\Domain\Places\Data\ResolvableItem;
use App\Domain\Places\Enums\MatchBand;
use App\Domain\Places\Enums\PlaceType;
use App\Domain\Places\Models\Place;
use App\Domain\Places\Models\PlaceMatchDecision;
use App\Domain\Places\Models\PlaceSourceId;
use App\Enums\CredibilityTier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The write path for one resolver decision (ENTITY-RESOLUTION §7): create a
 * canonical place from a source item, or merge the item into an existing one
 * with field-level survivorship (§3 stage 5). Every call records its decision.
 */
final class ResolveSourceItem
{
    /**
     * Type survivorship order (TAXONOMY §3 — mapping inputs in credibility
     * order: Overture primary, then OSM, then Wikidata).
     */
    private const TYPE_PRIORITY = ['overture' => 3, 'osm' => 2, 'wikidata' => 1];

    /** Geometry precision order (§3 stage 5: OSM/Overture first). */
    private const GEOMETRY_PRIORITY = ['osm' => 3, 'overture' => 2, 'wikidata' => 1];

    public function asNewPlace(ResolvableItem $item, MatchBand $band, ?Place $comparedTo, ?float $score, array $signals): Place
    {
        $payload = $item->payload;
        $candidate = ResolutionCandidate::fromPayload($payload);

        $place = new Place([
            'name' => $payload['name'],
            'alt_names' => array_values($payload['alt_names'] ?? []),
            'h3_index' => $item->h3Index,
            'type' => $payload['type'],
            'type_domain' => $payload['type_domain'],
            'facets' => $payload['facets'] ?? [],
            'source_tags' => [$item->source => $payload['source_tags'] ?? []],
            'taxonomy_version' => $payload['taxonomy_version'] ?? 1,
            'source' => $item->source,
            'attribute_sources' => [
                'name' => $item->source,
                'geometry' => $item->source,
                'type' => $item->source,
            ],
        ]);
        $place->id = (string) Str::uuid7();

        DB::table('places_core')->insert([
            ...$place->attributesToArray(),
            'alt_names' => json_encode($place->alt_names, JSON_UNESCAPED_UNICODE),
            'facets' => json_encode($payload['facets'] ?? []),
            'source_tags' => json_encode($place->source_tags, JSON_UNESCAPED_UNICODE),
            'attribute_sources' => json_encode($place->attribute_sources),
            'type' => $payload['type'],
            'type_domain' => $payload['type_domain'],
            'location' => DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography', $candidate->lng, $candidate->lat)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->recordContribution($item, $place->id);
        $this->recordDecision($item, $comparedTo?->id, $band, $score, $signals);

        return Place::query()->findOrFail($place->id);
    }

    public function intoPlace(ResolvableItem $item, Place $place, MatchBand $band, ?float $score, array $signals): Place
    {
        $this->applySurvivorship($item, $place);
        $this->recordContribution($item, $place->id);
        $this->recordDecision($item, $place->id, $band, $score, $signals);

        return $place;
    }

    public function asReviewOrDistinct(ResolvableItem $item, ?Place $comparedTo, MatchBand $band, ?float $score, array $signals): Place
    {
        // Review-band items are serveable as separate places meanwhile
        // (§3 stage 4): a duplicate is annoying, a false merge is corruption.
        return $this->asNewPlace($item, $band, $comparedTo, $score, $signals);
    }

    /** Field-level survivorship by source credibility (§3 stage 5). */
    private function applySurvivorship(ResolvableItem $item, Place $place): void
    {
        $payload = $item->payload;
        $sources = $place->attribute_sources ?? [];
        $conflicts = $sources['conflicts'] ?? [];

        // name: Tier A (official) beats everything; OSM beats the rest.
        // Losing names become alternates — never discarded.
        $names = collect([$place->name, ...$place->alt_names, $payload['name'], ...($payload['alt_names'] ?? [])])
            ->filter()->unique()->values();

        if ($this->nameOutranks($item, $place)) {
            $place->name = $payload['name'];
            $sources['name'] = $item->source;
        }
        $place->alt_names = $names->reject(fn (string $n): bool => $n === $place->name)->values()->all();

        // geometry: most precise open geometry wins; >150 m disagreement is a
        // recorded conflict (lowers confidence downstream, SCORING §4.6).
        $distance = $this->distanceToPlace($payload, $place);
        if ($distance > (float) config('resolver.survivorship.geometry_conflict_m')) {
            $conflicts['geometry'][] = [$item->source, round($distance)];
        }
        if ((self::GEOMETRY_PRIORITY[$item->source] ?? 0) > (self::GEOMETRY_PRIORITY[$sources['geometry'] ?? ''] ?? 0)) {
            DB::table('places_core')->where('id', $place->id)->update([
                'location' => DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography', $payload['lng'], $payload['lat'])),
                'h3_index' => $item->h3Index,
            ]);
            $place->h3_index = $item->h3Index;
            $sources['geometry'] = $item->source;
        }

        // type: TAXONOMY §3 credibility order; disagreement recorded.
        $incomingType = $payload['type'] ?? null;
        if ($incomingType !== null) {
            if ($place->type === null || (self::TYPE_PRIORITY[$item->source] ?? 0) > (self::TYPE_PRIORITY[$sources['type'] ?? ''] ?? 0)) {
                if ($place->type !== null && $place->type->value !== $incomingType) {
                    $conflicts['type'][] = [$item->source, $incomingType, 'was', $place->type->value];
                }
                $place->type = PlaceType::from($incomingType);
                $place->type_domain = $place->type->domain();
                $sources['type'] = $item->source;
            } elseif ($place->type !== null && $place->type->value !== $incomingType) {
                $conflicts['type'][] = [$item->source, $incomingType, 'kept', $place->type->value];
            }
        }

        // facets: union of rule-based priors (LLM pass extends later).
        $place->facets = collect([...$place->facets->pluck('value')->all(), ...($payload['facets'] ?? [])])->unique()->values()->all();

        // source_tags: union per source — never discarded (TAXONOMY §3).
        $tags = $place->source_tags;
        $tags[$item->source] = $payload['source_tags'] ?? [];
        $place->source_tags = $tags;

        if ($conflicts !== []) {
            $sources['conflicts'] = $conflicts;
        }
        $place->attribute_sources = $sources;
        $place->save();
    }

    private function nameOutranks(ResolvableItem $item, Place $place): bool
    {
        $currentSource = ($place->attribute_sources ?? [])['name'] ?? $place->source;

        $rank = fn (string $source, ?CredibilityTier $tier): int => match (true) {
            $tier === CredibilityTier::Official => 3,
            $source === 'osm' => 2,
            default => 1,
        };

        $currentTier = $currentSource === $item->source ? $item->credibilityTier : null;

        return $rank($item->source, $item->credibilityTier) > $rank($currentSource, $currentTier);
    }

    private function distanceToPlace(array $payload, Place $place): float
    {
        $row = DB::selectOne(
            'SELECT ST_Distance(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS d FROM places_core WHERE id = ?',
            [$payload['lng'], $payload['lat'], $place->id],
        );

        return (float) ($row->d ?? 0.0);
    }

    private function recordContribution(ResolvableItem $item, string $placeId): void
    {
        PlaceSourceId::query()->upsert(
            [[
                'place_id' => $placeId,
                'source' => $item->source,
                'external_id' => (string) $item->externalId,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            uniqueBy: ['source', 'external_id'],
            update: ['place_id', 'updated_at'],
        );

        // Wikidata QIDs referenced by other sources also land in the
        // concordance, so later explicit joins are one indexed lookup.
        $qid = $item->payload['external_refs']['wikidata'] ?? null;
        if ($qid !== null && $item->source !== 'wikidata') {
            PlaceSourceId::query()->upsert(
                [[
                    'place_id' => $placeId,
                    'source' => 'wikidata',
                    'external_id' => $qid,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                uniqueBy: ['source', 'external_id'],
                update: ['updated_at'], // an existing direct wikidata row wins; do not repoint it
            );
        }
    }

    private function recordDecision(ResolvableItem $item, ?string $placeId, MatchBand $band, ?float $score, array $signals): void
    {
        PlaceMatchDecision::query()->create([
            'source_item_id' => $item->id,
            'place_id' => $placeId,
            'score' => $score,
            'band' => $band,
            'signals' => $signals,
            'resolver_version' => config('resolver.version'),
            'decided_by' => 'auto',
        ]);
    }
}
