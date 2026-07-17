<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The gazetteer — a global INDEX of place names, so the planner can search a place before
 * anyone has walked into it (PLAN-DRIVEN-INGESTION §3).
 *
 * Deliberately separate from `places_core`. This is not the explorable world model: it has no
 * hours, no evidence, no scores, and it is NEVER served as an opportunity. It answers one
 * question — "where is the place called X" — so a trip can be anchored anywhere, which then
 * drives ingestion of the detailed layer (E48). Keeping it in its own table leaves the
 * ODbL-publishable boundary of `places_core` exactly where ODBL-REVIEW §6 drew it.
 *
 * Source is OSM `place=*` settlement nodes (ODbL, same license as the core). Loaded
 * country-scoped and settlement-focused (no `locality`/`isolated_dwelling` long tail), which
 * keeps it to tens of MB per country rather than the multi-GB global-everything load.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gazetteer_places', function (Blueprint $table): void {
            $table->id();

            // OSM node id — provenance and the idempotent upsert key. A re-load updates in place.
            $table->unsignedBigInteger('osm_id')->unique();

            $table->string('name');

            // city | town | village | hamlet | suburb | neighbourhood — the `place=*` value,
            // kept as a plain string (this is not a PlaceType: a settlement is not a venue).
            $table->string('place_type', 24);

            $table->geography('location', subtype: 'point', srid: 4326);

            // For importance ranking (a city named X above a hamlet named X) where OSM tags it.
            $table->unsignedInteger('population')->nullable();

            // ISO 3166-1 alpha-2 — the load scope, and a filter for expanding country by country.
            $table->char('country_code', 2);

            // A coarse label to disambiguate same-named places in the list ("Kusmark, Skellefteå").
            $table->string('admin_label')->nullable();

            $table->timestamps();

            $table->spatialIndex('location');
            $table->index('country_code');
        });

        // Fuzzy name search, same as places_core (pg_trgm already enabled). This is the index
        // that makes "kusmark" find Kusmark and tolerate a typo.
        DB::statement('CREATE INDEX gazetteer_places_name_trgm ON gazetteer_places USING gin (name gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS gazetteer_places_name_trgm');
        Schema::dropIfExists('gazetteer_places');
    }
};
