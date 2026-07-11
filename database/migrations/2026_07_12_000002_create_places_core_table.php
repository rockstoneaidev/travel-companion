<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GEO-CORE ZONE (conventions/03): ODbL, publishable. Only ODbL-compatible
 * open data lands here — names, geometry, categories, raw source tags.
 * No proprietary column, ever; attach proprietary value in a new table
 * keyed by place_id. No Google-derived value, ever (ODBL-REVIEW §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('places_core', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name');
            $table->jsonb('alt_names')->default('[]');           // multilingual alternates — never pick one "true" name at ingest

            $table->geography('location', subtype: 'point', srid: 4326);
            $table->string('h3_index', 20)->index();             // res-8 cell (conventions/12)

            $table->string('type', 48)->index();                 // PlaceType
            $table->string('type_domain', 32)->index();          // PlaceTypeDomain — denormalised from type, never independently authored
            $table->jsonb('facets')->default('[]');              // list<AppealFacet> — rule-based priors + reviewed assignments
            $table->jsonb('source_tags')->default('{}');         // raw OSM/Overture/Wikidata tags per source — never discarded (TAXONOMY §3)
            $table->unsignedSmallInteger('taxonomy_version')->index();

            $table->string('source', 32);                        // seed adapter key (conventions/03 rule 3)
            $table->jsonb('attribute_sources')->default('{}');   // field-level provenance, written by survivorship (ENTITY-RESOLUTION §3.5)

            $table->timestampsTz();

            $table->spatialIndex('location');
        });

        DB::statement('CREATE INDEX places_core_facets_gin ON places_core USING gin (facets)');
        DB::statement('CREATE INDEX places_core_type_domain_h3_idx ON places_core (type_domain, h3_index)');
    }

    public function down(): void
    {
        Schema::dropIfExists('places_core');
    }
};
