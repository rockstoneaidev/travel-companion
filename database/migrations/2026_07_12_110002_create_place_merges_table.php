<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The redirect table (ENTITY-RESOLUTION §2): canonical ids are never deleted,
 * only redirected — user data referencing a merged-away place survives, and
 * un-merge is a supported operation. Reads resolve redirects at the
 * PlaceLookup boundary (conventions/01).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_merges', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('old_place_id')->unique();
            $table->foreignUuid('canonical_place_id')->constrained('places_core')->cascadeOnDelete();

            $table->string('resolver_version', 32);
            $table->timestampTz('merged_at');

            $table->timestampsTz();

            $table->index('canonical_place_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_merges');
    }
};
