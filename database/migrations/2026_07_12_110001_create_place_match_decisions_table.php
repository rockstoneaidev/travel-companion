<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The resolver's audit trail (ENTITY-RESOLUTION §2): every decision records
 * its score, band, and per-signal evidence — what makes false merges
 * debuggable and the gold set fittable. PROPRIETARY ZONE (conventions/03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_match_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('source_item_id')->constrained('source_items')->cascadeOnDelete();
            $table->foreignUuid('place_id')->nullable()->constrained('places_core')->nullOnDelete();

            $table->decimal('score', 5, 4)->nullable();          // null for explicit-ID joins
            $table->string('band', 16)->index();                 // MatchBand
            $table->jsonb('signals')->default('{}');             // per-signal scores (name_sim, proximity, …)
            $table->string('resolver_version', 32)->index();     // versioned (conventions/03)
            $table->string('decided_by', 16)->default('auto');   // auto | reviewer

            $table->timestampsTz();

            $table->index(['band', 'created_at']);               // review queue reads (ADMIN.md)
            $table->index('source_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_match_decisions');
    }
};
