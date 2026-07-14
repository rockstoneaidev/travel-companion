<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop the regions the pin-centred scheme minted (E48 → grid-anchored identity).
 *
 * The old `DeriveRegionForPosition` centred a region box on the USER'S coordinate, so
 * identity slid around with whoever happened to arrive first. Two people in one town
 * produced two overlapping regions ingesting the same Overpass ground:
 *
 *     Skellefteå         skelleftea-6475-2095          200 places, box 41 of 55
 *     Skellefteå kommun  skelleftea-kommun-6488-2080     3 places, queued
 *
 * Regions are now H3 res-5 cells, which tile the plane: deterministic, and
 * self-deduplicating by construction. The old rows cannot be migrated into that scheme —
 * their boxes are not cells — so they go.
 *
 * NOTHING OF VALUE IS LOST. A derived region is a *request to ingest*, not data: the
 * places, source items and match decisions it produced are all keyed by geography and
 * stay exactly where they are. The next person to stand there mints the proper cell, and
 * the ingest — which is idempotent by design — simply covers ground it already knows.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Only the old shape. A grid-anchored key is `r5-…`; anything else predates it.
        DB::table('derived_regions')->where('key', 'not like', 'r5-%')->delete();
    }

    public function down(): void
    {
        // Deliberately irreversible: re-inventing pin-centred regions from a grid-anchored
        // world is not a migration, it is a regression.
    }
};
