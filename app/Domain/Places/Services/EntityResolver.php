<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Domain\Places\Actions\ResolveSourceItem;
use App\Domain\Places\Contracts\ResolvableItems;
use App\Domain\Places\Data\ResolutionCandidate;
use App\Domain\Places\Data\ResolvableItem;
use App\Domain\Places\Enums\MatchBand;
use App\Domain\Places\Models\Place;
use App\Domain\Places\Models\PlaceSourceId;
use Illuminate\Support\Facades\DB;

/**
 * The v1 resolver pipeline (ENTITY-RESOLUTION §3), run per res-8 tile.
 * Deterministic: items stream in credibility order and the same inputs under
 * the same resolver_version produce the same places.
 *
 * Blocking note: the spec's Stage-2 block (res-9 cell + k-ring 1 ≈ 350 m,
 * trigram ≥ 0.3 OR same domain) is implemented as an ST_DWithin 350 m gate
 * plus the same name/domain predicate — equivalent semantics at tile scale,
 * one indexed query instead of an H3 join.
 */
final class EntityResolver
{
    public function __construct(
        private readonly MatchScorer $scorer,
        private readonly ResolveSourceItem $resolve,
        private readonly ResolvableItems $items,
    ) {}

    private const BLOCK_RADIUS_M = 350;

    /**
     * @return array{items: int, created: int, merged: int, review: int, explicit: int}
     */
    public function resolveTile(string $h3Index): array
    {
        $items = $this->items->unresolvedInTile($h3Index, (string) config('resolver.version'));
        $stats = ['items' => count($items), 'created' => 0, 'merged' => 0, 'review' => 0, 'explicit' => 0];

        foreach ($items as $item) {
            $outcome = DB::transaction(fn (): string => $this->resolveItem($item));
            $stats[$outcome]++;
        }

        return $stats;
    }

    private function resolveItem(ResolvableItem $item): string
    {
        // Stage 1 — explicit-ID joins, strongest identifier first
        // (ENTITY-RESOLUTION §3): a shared Wikidata QID, else a shared Wikipedia
        // article. Both are assertions of identity by a human, which is why they
        // outrank any amount of fuzzy name similarity.
        $identifiers = array_filter([
            'wikidata' => $item->wikidataQid(),
            'wikipedia' => $item->wikipediaSitelink(),
        ]);

        foreach ($identifiers as $source => $externalId) {
            $outcome = $this->joinOnExplicitId($item, (string) $source, (string) $externalId);

            if ($outcome !== null) {
                return $outcome;
            }
        }

        // Stage 2+3 — blocked fuzzy match against nearby canonical places.
        $candidate = $item->candidate();
        [$best, $bestPlace] = $this->bestMatch($candidate);

        if ($best === null) {
            $this->resolve->asNewPlace($item, MatchBand::Distinct, null, null, []);

            return 'created';
        }

        switch ($best['band']) {
            case MatchBand::High:
                $this->resolve->intoPlace($item, $bestPlace, MatchBand::High, $best['score'], $best['signals']);

                return 'merged';
            case MatchBand::Review:
                $this->resolve->asReviewOrDistinct($item, $bestPlace, MatchBand::Review, $best['score'], $best['signals']);

                return 'review';
            default:
                $this->resolve->asNewPlace($item, MatchBand::Distinct, $bestPlace, $best['score'], $best['signals']);

                return 'created';
        }
    }

    /**
     * One explicit-ID join attempt. Returns the outcome, or null if this
     * identifier points at nothing we hold yet (the caller then tries the next
     * identifier, and finally the fuzzy stages).
     */
    private function joinOnExplicitId(ResolvableItem $item, string $source, string $externalId): ?string
    {
        $existing = PlaceSourceId::query()
            ->where('source', $source)
            ->where('external_id', $externalId)
            ->first()?->place_id;

        $place = $existing === null ? null : $this->placeWithPoint($existing);

        if ($place === null) {
            return null;
        }

        $distance = $this->scorer->distanceMeters($item->candidate(), $this->asCandidate($place));
        $signals = ['explicit' => "{$source}:{$externalId}", 'distance_m' => round($distance)];

        if ($distance <= (float) config('resolver.explicit_max_distance_m')) {
            $this->resolve->intoPlace($item, $place, MatchBand::Explicit, null, $signals);

            return 'explicit';
        }

        // Sanity guard: two sources claiming the same identifier from > 1 km
        // apart are more likely a bad tag than the same place. Review, never merge.
        $this->resolve->asReviewOrDistinct($item, $place, MatchBand::Review, null, [...$signals, 'guard' => 'explicit_distance']);

        return 'review';
    }

    /**
     * @return array{0: ?array{score: float, band: MatchBand, signals: array}, 1: ?Place}
     */
    private function bestMatch(ResolutionCandidate $candidate): array
    {
        $rows = Place::query()
            ->select('places_core.*')
            ->selectRaw('ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng')
            ->whereRaw(
                'ST_DWithin(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
                [$candidate->lng, $candidate->lat, self::BLOCK_RADIUS_M],
            )
            ->limit(100)
            ->get();

        $best = null;
        $bestPlace = null;

        foreach ($rows as $place) {
            $other = $this->asCandidate($place);

            // Stage-2 block predicate: name trigram ≥ 0.3 OR same domain.
            $sameDomain = $candidate->type !== null && $place->type_domain === $candidate->type->domain();
            if (! $sameDomain && ! $this->passesTrigramFloor($candidate, $other)) {
                continue;
            }

            $result = $this->scorer->score($candidate, $other);

            if ($best === null || $result['score'] > $best['score']) {
                $best = $result;
                $bestPlace = $place;
            }
        }

        return [$best, $bestPlace];
    }

    private function passesTrigramFloor(ResolutionCandidate $a, ResolutionCandidate $b): bool
    {
        $floor = (float) config('resolver.blocking.trigram_floor');

        foreach ($a->names as $nameA) {
            foreach ($b->names as $nameB) {
                if (MatchScorer::trigram($nameA, $nameB) >= $floor) {
                    return true;
                }
            }
        }

        return false;
    }

    private function placeWithPoint(string $id): ?Place
    {
        return Place::query()
            ->select('places_core.*')
            ->selectRaw('ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng')
            ->find($id);
    }

    private function asCandidate(Place $place): ResolutionCandidate
    {
        return new ResolutionCandidate(
            names: array_values(array_filter([$place->name, ...$place->alt_names])),
            lat: (float) $place->getAttribute('lat'),
            lng: (float) $place->getAttribute('lng'),
            type: $place->type,
        );
    }
}
