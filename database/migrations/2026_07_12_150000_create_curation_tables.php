<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The curated layer (CURATION §2) — PROPRIETARY ZONE keyed by place_id,
 * never merged into the ODbL core (ODBL-REVIEW §6). Evidence excerpts carry
 * their own source licenses per row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('region_slug', 64)->unique();
            $table->string('name');
            $table->string('status', 24)->default('draft');       // draft | published
            $table->unsignedSmallInteger('pack_version')->default(0);
            $table->jsonb('h3_set')->default('[]');                // coverage cells (geometry alt.)
            $table->unsignedInteger('effort_minutes')->default(0); // the Phase 3 cost model (CURATION §5)
            $table->timestampsTz();
        });

        Schema::create('curated_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('place_id')->nullable()->constrained('places_core')->nullOnDelete();
            $table->foreignUuid('pack_id')->nullable()->constrained('packs')->nullOnDelete();

            $table->string('title');
            $table->text('claim');                                 // "why this is special", 1–3 sentences
            $table->jsonb('facets')->default('[]');                // AppealFacet[]
            $table->jsonb('evidence')->default('[]');              // url, source_type, license, excerpt, retrieved_at
            $table->string('status', 24)->index();                 // CurationStatus
            $table->string('authored_by', 8);                      // human | llm
            $table->string('prompt_version', 32)->nullable();      // required when authored_by = llm
            $table->string('language', 8)->default('en');
            $table->string('region_slug', 64)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'region_slug']);
        });

        Schema::create('pack_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pack_id')->constrained('packs')->cascadeOnDelete();
            $table->string('section', 24);                         // PackSection
            $table->jsonb('content')->default('{}');               // structured claims grounded to place_ids/evidence
            $table->string('ttl_class', 16);
            $table->timestampsTz();

            $table->unique(['pack_id', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pack_sections');
        Schema::dropIfExists('curated_items');
        Schema::dropIfExists('packs');
    }
};
