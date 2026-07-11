<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EVIDENCE-STORE ZONE (conventions/03): raw normalized candidates, one row
 * per source item, each carrying its own license metadata. Never merged into
 * the geo-core. EdgeOnly data never lands here at all — the SourceItem model
 * guard throws (StoragePolicyViolation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('source', 32)->index();               // adapter key
            $table->string('external_id')->nullable();
            $table->string('license', 32);                       // SourceLicense
            $table->string('storage_policy', 32);                // StoragePolicy — guard enforced in the model
            $table->string('credibility_tier', 16);              // CredibilityTier

            $table->jsonb('payload')->default('{}');             // normalized candidate: names, tags, claims
            $table->geography('location', subtype: 'point', srid: 4326)->nullable();
            $table->string('h3_index', 20)->nullable()->index();

            $table->string('source_adapter_version', 32);        // required version column (conventions/03)
            $table->string('attribution')->nullable();
            $table->timestampTz('retrieved_at');

            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX source_items_source_external_unique ON source_items (source, external_id) WHERE external_id IS NOT NULL');
        DB::statement('CREATE INDEX source_items_location_gist ON source_items USING gist (location)');
    }

    public function down(): void
    {
        Schema::dropIfExists('source_items');
    }
};
