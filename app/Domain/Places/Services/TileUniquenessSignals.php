<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Domain\Places\Models\Place;
use App\Domain\Recommendations\Support\Num;
use DateInterval;

/**
 * The tile-relative uniqueness signals (SCORING §4.2).
 *
 * "Unusual" means unusual *here*, so every signal is normalized against the
 * tile — which is also what makes them user-independent and cacheable per tile
 * (SCORING §2.3). They are computed over EVERY place in the tile, not over one
 * scout's slice of it: a scout that filters by domain sees a fraction of the
 * tile, and a percentile taken over a fraction is not a percentile.
 *
 * Implemented here, from our own world model:
 *
 *   u3  local specificity        local evidence / (local + global), Laplace-smoothed
 *   u5  historical/cultural      pct_tile(wikidata + wikipedia + curated-layer flag)
 *   u6  facet-combination rarity 1 − share of tile places with Jaccard(facets) ≥ 0.5
 *
 * Deliberately absent, with reasons (each one drops out of the weighted sum and
 * discounts confidence — SCORING §2.5, which is exactly what should happen):
 *
 *   u1  tourist saturation       needs mainstream review counts. Those come from
 *                                Google, which is edge-only and NEVER persisted
 *                                (CLAUDE.md non-negotiable #2), so it cannot be
 *                                tile-cached. Arrives with the E16 edge pass, if
 *                                at all.
 *   u2  semantic distinctiveness needs pgvector embeddings. None exist yet — the
 *                                resolver renormalizes without embed_cos too.
 *   u4  temporal rarity          evergreen ⇒ 0.0 by definition. Not missing: the
 *                                only opportunities M1 produces ARE evergreen, so
 *                                0.0 is the right answer, not a gap.
 */
final class TileUniquenessSignals
{
    public const VERSION = 'v1';

    /** Local mappers vs global aggregators (SCORING §4.2 u3). */
    private const LOCAL_SOURCES = ['osm', 'curated'];

    private const GLOBAL_SOURCES = ['wikidata', 'overture'];

    public function __construct(private readonly TileCache $cache) {}

    /**
     * @return array<string, array{u3: ?float, u5: ?float, u6: ?float}> keyed by place_id
     */
    public function forTile(string $h3Index): array
    {
        [$rows] = $this->cache->remember(
            'uniqueness', $h3Index, self::VERSION, new DateInterval('P1D'),
            fn (): array => $this->compute($h3Index),
        );

        $out = [];
        foreach ($rows as $row) {
            $out[$row['place_id']] = ['u3' => $row['u3'], 'u5' => $row['u5'], 'u6' => $row['u6']];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function compute(string $h3Index): array
    {
        $places = Place::query()
            ->select(['id', 'facets', 'source_tags'])
            ->selectRaw('EXISTS (SELECT 1 FROM curated_items ci WHERE ci.place_id = places_core.id AND ci.status = ?) AS curated', ['approved'])
            ->selectRaw('EXISTS (SELECT 1 FROM place_source_ids psi WHERE psi.place_id = places_core.id AND psi.source = ?) AS has_wikipedia', ['wikipedia'])
            ->where('h3_index', $h3Index)
            ->orderBy('id')
            ->get();

        if ($places->isEmpty()) {
            return [];
        }

        $facetsById = [];
        $culturalById = [];
        $u3ById = [];

        foreach ($places as $place) {
            $id = (string) $place->id;
            $sources = array_keys($place->source_tags ?? []);

            $facetsById[$id] = $place->facets->pluck('value')->all();

            // u5 — cultural markers. The spec also wants Wikipedia article
            // length; we do not store article bodies, so the flag stands in for
            // it. Recorded as a known approximation rather than a silent one.
            $culturalById[$id] =
                (int) in_array('wikidata', $sources, true)
                + (int) (bool) $place->getAttribute('has_wikipedia')
                + (int) (bool) $place->getAttribute('curated');

            // u3 — local specificity, Laplace-smoothed so a place with a single
            // source is not pinned at 0 or 1.
            $local = count(array_intersect($sources, self::LOCAL_SOURCES));
            $global = count(array_intersect($sources, self::GLOBAL_SOURCES));
            $u3ById[$id] = round(($local + 1) / ($local + $global + 2), 4);
        }

        $out = [];
        foreach ($facetsById as $id => $facets) {
            $out[] = [
                'place_id' => $id,
                'u3' => $u3ById[$id],
                'u5' => $this->percentile($culturalById[$id], $culturalById),
                'u6' => $this->facetRarity($id, $facetsById),
            ];
        }

        return $out;
    }

    /**
     * Share of the tile scoring strictly lower. A tile where everything is
     * equally (un)remarkable carries no information, so the signal drops out
     * rather than asserting 0.5 about everyone.
     *
     * @param  array<string, int>  $all
     */
    private function percentile(int $value, array $all): ?float
    {
        if (count($all) < 2 || count(array_unique($all)) === 1) {
            return null;
        }

        $below = count(array_filter($all, static fn (int $v): bool => $v < $value));

        return round(Num::clamp($below / (count($all) - 1)), 4);
    }

    /**
     * u6 — 1 − share of tile places whose facet set overlaps this one at
     * Jaccard ≥ 0.5 (SCORING §4.2).
     *
     * @param  array<string, list<string>>  $facetsById
     */
    private function facetRarity(string $id, array $facetsById): ?float
    {
        $mine = $facetsById[$id];

        if ($mine === [] || count($facetsById) < 2) {
            return null;
        }

        $similar = 0;
        foreach ($facetsById as $theirs) {
            $union = count(array_unique([...$mine, ...$theirs]));

            if ($union > 0 && count(array_intersect($mine, $theirs)) / $union >= 0.5) {
                $similar++;
            }
        }

        return round(Num::clamp(1.0 - $similar / count($facetsById)), 4);
    }
}
