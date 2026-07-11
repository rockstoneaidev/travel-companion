<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PROPRIETARY-SHELL ZONE: short-lived, context-bound, TTL'd (PRD §14.2 —
 * "short-lived" is the table's property; `ephemeral` is an OpportunityKind
 * value, don't confuse them). Cheap to discard and regenerate; a pruning job
 * reads expires_at and nothing else decides expiry (conventions/03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->uuid('id')->primary();          // UUIDv7 via model (insert-order locality)

            $table->foreignUuid('place_id')->constrained('places_core')->cascadeOnDelete();
            $table->string('kind', 24)->index();                  // OpportunityKind
            $table->string('status', 24)->index();                // OpportunityStatus (PRD §10 state machine)

            $table->string('title')->nullable();                  // LLM/template-written (E12); place name until then
            $table->text('summary')->nullable();                  // the "why now" — evidence-grounded
            $table->string('prompt_version', 32)->nullable();     // required when summary is LLM-generated

            $table->timestampTz('window_starts_at')->nullable();
            $table->timestampTz('window_ends_at')->nullable();
            $table->jsonb('friction')->default('{}');             // walk_minutes, detour_minutes, price band, queue risk

            $table->string('h3_index', 20)->index();              // the tile this opportunity was generated for
            $table->timestampTz('expires_at')->index();

            $table->timestampsTz();
        });

        // Hot filter: live opportunities per tile (conventions/03 partial-index rule).
        DB::statement('CREATE INDEX opportunities_live_per_tile ON opportunities (h3_index, expires_at) WHERE status NOT IN (\'discarded\', \'expired\')');
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};
