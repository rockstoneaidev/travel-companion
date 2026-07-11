<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GEO-CORE ZONE: the cross-source ID concordance (PRD §9.6,
 * ENTITY-RESOLUTION §2). One row per contributing source identity.
 * A Google place_id string may be stored here as an external identifier —
 * and nothing else from Google, anywhere (conventions/03 rule 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_source_ids', function (Blueprint $table) {
            $table->id();

            $table->foreignUuid('place_id')->constrained('places_core')->cascadeOnDelete();
            $table->string('source', 32);           // adapter key: osm, overture, wikidata, datatourisme, google…
            $table->string('external_id');

            $table->timestampsTz();

            $table->unique(['source', 'external_id']);
            $table->index('place_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_source_ids');
    }
};
