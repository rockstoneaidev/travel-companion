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
        // Stage 1 — explicit-ID join via the Wikidata concordance.
        $qid = $item->wikidataQid();

        if ($qid !== null) {
            $existing = PlaceSourceId::query()
                ->where('source', 'wikidata')
                ->where('external_id', $qid)
                ->first()?->place_id;

            $place = $existing === null ? null : $this->placeWithPoint($existing);

            if ($place !== null) {
                $distance = $this->scorer->distanceMeters($item->candidate(), $this->asCandidate($place));

                if ($distance <= (float) config('resolver.explicit_max_distance_m')) {
                    $this->resolve->intoPlace($item, $place, MatchBand::Explicit, null, ['explicit' => 'wikidata:'.$qid, 'distance_m' => round($distance)]);

                    return 'explicit';
                }

                // Sanity guard: joined points > 1 km apart → review, not merge.
                $this->resolve->asReviewOrDistinct($item, $place, MatchBand::Review, null, ['explicit' => 'wikidata:'.$qid, 'distance_m' => round($distance), 'guard' => 'explicit_distance']);

                return 'review';
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
