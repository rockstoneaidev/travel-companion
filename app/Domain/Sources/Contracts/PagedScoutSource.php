<?php

declare(strict_types=1);

namespace App\Domain\Sources\Contracts;

use App\Domain\Sources\Data\ScoutRequest;

/**
 * A source that can hand its region over in BOUNDED PAGES instead of all at once.
 *
 * ===========================================================================
 *  Why this exists: the whole-region ingest was a memory bomb
 * ===========================================================================
 *
 * `ScoutSource::search()` returns `list<array>` — the entire region, in one array,
 * in memory. For a paginated API that means the adapter loops its pages and
 * accumulates: DATAtourisme did `$objects = [...$objects, ...$page]` up to 500 pages,
 * which is both 10,000 POIs resident at once AND an O(n²) copy on every iteration.
 * `RegionIngest` then built four more arrays on top of it (candidates, lat/lng pairs,
 * cells, and a zipped copy of the lot).
 *
 * On a 3.7 GB box with ~600 MB free, a Paris ingest killed the app container — and
 * because Horizon runs inside that container, it took the serving site with it. The
 * `BuildRegionWorldModelJob`s that had been failing as `MaxAttemptsExceeded` for a day
 * were the same thing: the worker was killed mid-job, the queue re-reserved the job,
 * and `tries = 1` wrote it off as dead. Exactly the failure the OSM boxing already
 * fixed — but only OSM was boxed, so the other three sources kept doing it.
 *
 * A page is the unit of MEMORY, exactly as a box is the unit of work and of
 * persistence (IngestRegionBoxJob): what a page fetches is normalized, written and
 * FREED before the next page is asked for, so peak memory is a page rather than a
 * region — and a region twice the size costs the same peak, which is what "durable"
 * means here.
 *
 * ---------------------------------------------------------------------------
 *  When a source may NOT implement this
 * ---------------------------------------------------------------------------
 *
 * Only if `normalize()` is PER-ROW. Wikidata's is not: it returns one binding row per
 * (item, class) and groups them by item, so an item whose rows straddle a page
 * boundary would be normalized twice with half its P31 classes each time — and since
 * the class list decides the place TYPE, that is a silently wrong type, not a missing
 * one. Wikidata is therefore chunked SPATIALLY (a box is a complete answer for its
 * bbox) rather than by offset. The rule: page a source only where a page is
 * semantically whole.
 */
interface PagedScoutSource extends ScoutSource
{
    /**
     * Bounded pages of raw rows, in order. Implemented as a generator — a `return`ed
     * array of pages would defeat the entire purpose.
     *
     * @return iterable<int, list<array<string, mixed>>>
     */
    public function pages(ScoutRequest $request): iterable;
}
