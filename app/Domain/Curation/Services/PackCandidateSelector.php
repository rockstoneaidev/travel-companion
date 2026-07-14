<?php

declare(strict_types=1);

namespace App\Domain\Curation\Services;

use App\Domain\Curation\Data\PackCandidate;
use App\Domain\Sources\Services\RegionCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Chooses which places are worth a founder's review hour (CURATION §4).
 *
 * This is the scarcest resource in the project: seven packs at 4–6 hours each is
 * roughly thirty hours of one person's attention. So selection is not "top N by
 * some score" — it is an argument about what deserves that attention.
 *
 * Three rules, in order:
 *
 *   1. EVIDENCE IS MANDATORY. A place nothing is written about cannot be drafted
 *      from evidence, and a draft not written from evidence is a hallucination
 *      with a review queue in front of it (conventions/10). This also happens to
 *      be a quality filter: if no tourism board, ministry or encyclopaedia ever
 *      wrote a sentence about it, it is probably not the thing to walk to.
 *
 *   2. WEIGHT TOWARD WHAT GOOGLE IS WORST AT (CURATION §4): `offbeat`,
 *      `local_life`, `food_drink`, `craft`. Curating the Louvre is a waste of a
 *      review hour — the traveller will find the Louvre. The pack earns its keep
 *      on the workshop, the covered market, the chapel with the fresco.
 *
 *   3. SPREAD ACROSS THE CITY. A greedy score takes forty items from one dense
 *      arrondissement and calls it Paris. Candidates are capped per H3 tile so a
 *      pack covers the places a traveller might actually be standing in.
 */
final class PackCandidateSelector
{
    /** The facets a curated pack exists to cover. */
    private const PRIORITY_FACETS = ['offbeat', 'local_life', 'food_drink', 'craft'];

    /** No more than this many from any one res-8 tile — a pack is a city, not a block. */
    private const PER_TILE_CAP = 3;

    /*
     * Domain balance is enforced by ROUND-ROBIN, not by a cap — see forRegion().
     *
     * Learned the hard way, twice. The first Paris draft came back as eight
     * bistros: the priority score is a count of matching facets, DATAtourisme is
     * dense with described restaurants, and `food_drink` swept the board. A cap
     * plus a fill-the-shortfall pass then produced 20 bistros of 40, because the
     * leftover pool was ALSO bistros, so the fill pass handed the pack straight
     * back to the domain the cap had held back.
     *
     * Each individual draft was good. The SET was useless — a curator's hour spent
     * approving twenty restaurants is an hour not spent on the chapel, the
     * workshop and the covered market. A pack is a portrait of a city, and no city
     * is one domain.
     */

    /** @return list<PackCandidate> */
    public function forRegion(string $regionKey, int $limit): array
    {
        $region = app(RegionCatalog::class)->named($regionKey);

        $rows = DB::table('places_core as p')
            ->select(['p.id', 'p.name', 'p.type', 'p.type_domain', 'p.facets', 'p.h3_index'])
            ->whereRaw(
                'ST_Intersects(p.location::geometry, ST_MakeEnvelope(?, ?, ?, ?, 4326))',
                [$region->west, $region->south, $region->east, $region->north],
            )
            // Rule 1: evidence, or no draft. A DATAtourisme description or a
            // Mérimée record is a sentence somebody else wrote and we may quote.
            ->whereExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('place_source_ids as psi')
                    ->join('source_items as si', function ($join): void {
                        $join->on('si.source', '=', 'psi.source')->on('si.external_id', '=', 'psi.external_id');
                    })
                    ->whereColumn('psi.place_id', 'p.id')
                    ->where(function ($w): void {
                        $w->whereRaw("si.source = 'datatourisme' AND si.payload->'source_tags'->>'description' IS NOT NULL")
                            ->orWhereRaw("si.source = 'merimee' AND si.payload->'source_tags'->>'datation' IS NOT NULL")
                            // Wikipedia — the narrative layer (DATA-SOURCES §2, P1).
                            //
                            // Without it this rule accepted only DATAtourisme and Mérimée,
                            // which are both FRENCH — so Stockholm, the home region, could
                            // never produce a single candidate however many times its world
                            // model was rebuilt. "OSM has no stories", as DATA-SOURCES puts
                            // it, and Wikidata's p31 is a type code, not prose.
                            //
                            // CC BY-SA: quotable WITH attribution, never merged into the
                            // core (conventions/09).
                            ->orWhereRaw("si.source = 'wikipedia' AND si.payload->'source_tags'->>'description' IS NOT NULL");
                    });
            })
            // Never re-draft what a human has already ruled on.
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('curated_items as ci')
                    ->whereColumn('ci.place_id', 'p.id');
            })
            // Does this place have PROSE somebody wrote, or only a database record?
            // It is the difference between a draft worth reading and a formulaic one
            // — see richness() below.
            ->selectRaw(
                "EXISTS (
                    SELECT 1 FROM place_source_ids psi
                    JOIN source_items si ON si.source = psi.source AND si.external_id = psi.external_id
                    WHERE psi.place_id = p.id
                      AND si.source IN ('datatourisme', 'wikipedia')
                      AND si.payload->'source_tags'->>'description' IS NOT NULL
                ) AS has_prose",
            )
            ->get();

        $scored = [];
        foreach ($rows as $row) {
            $facets = json_decode((string) $row->facets, true) ?: [];

            $scored[] = [
                'row' => $row,
                'facets' => $facets,
                'score' => $this->priority($facets) + $this->richness($row),
            ];
        }

        // Highest priority first; stable by id so a re-run picks the same set.
        usort($scored, static fn (array $a, array $b): int => [$b['score'], $a['row']->id] <=> [$a['score'], $b['row']->id]);

        // Round-robin across domains, best-first within each.
        //
        // A cap plus a "fill the shortfall" pass does NOT work, and it is worth
        // saying why: the leftover pool is dense in exactly the domain the cap was
        // holding back, so the fill pass hands the pack straight back to it. The
        // first real Paris run came out 20 bistros of 40 that way.
        //
        // Taking turns fixes it at the root. The pack ends up as balanced as the
        // city's evidence allows, and still fills, because a domain that runs out
        // simply stops taking turns.
        $byDomain = [];
        foreach ($scored as $candidate) {
            $byDomain[(string) $candidate['row']->type_domain][] = $candidate;
        }

        // Start with the domain that has the strongest single candidate, so a city
        // known for one thing still leads with it.
        uasort($byDomain, static fn (array $a, array $b): int => $b[0]['score'] <=> $a[0]['score']);

        $picked = [];
        $perTile = [];
        $cursor = array_fill_keys(array_keys($byDomain), 0);

        while (count($picked) < $limit) {
            $tookOne = false;

            foreach ($byDomain as $domain => $candidates) {
                if (count($picked) >= $limit) {
                    break;
                }

                // Advance this domain's cursor to its next tile-legal candidate.
                while ($cursor[$domain] < count($candidates)) {
                    $candidate = $candidates[$cursor[$domain]];
                    $cursor[$domain]++;

                    $tile = (string) $candidate['row']->h3_index;

                    if (($perTile[$tile] ?? 0) >= self::PER_TILE_CAP) {
                        continue;   // a pack is a city, not a block
                    }

                    $perTile[$tile] = ($perTile[$tile] ?? 0) + 1;

                    $picked[] = new PackCandidate(
                        placeId: (string) $candidate['row']->id,
                        name: (string) $candidate['row']->name,
                        type: (string) $candidate['row']->type,
                        facets: $candidate['facets'],
                    );

                    $tookOne = true;

                    break;
                }
            }

            if (! $tookOne) {
                break;   // every domain is exhausted
            }
        }

        return $picked;
    }

    /** @param list<string> $facets */
    private function priority(array $facets): int
    {
        return count(array_intersect($facets, self::PRIORITY_FACETS));
    }

    /**
     * Prefer places somebody has actually WRITTEN about.
     *
     * A draft is only as good as the evidence under it, and our two French sources
     * are not equivalent. DATAtourisme carries a tourism board's prose — "housed in
     * a former 1920s butcher shop that still preserves its original period decor".
     * Mérimée carries a structured protection record and no prose at all, so the
     * best a draft can honestly do with it is "a protected fountain, dated 1710".
     *
     * That second kind is TRUE, and it is nearly worthless: the first Paris pack had
     * a run of town halls and fountains all saying the same formulaic thing, and
     * every one of them was a review minute spent to reach a rejection.
     *
     * So prose outranks a record. Mérimée-only places still get drafted — they are
     * the chapels and dolmens Google does not have — but they queue behind the ones
     * a curator is likely to keep.
     */
    private function richness(object $row): int
    {
        return $row->has_prose ? 2 : 0;
    }
}
