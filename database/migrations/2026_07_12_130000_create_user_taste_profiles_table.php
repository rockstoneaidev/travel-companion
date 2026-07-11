<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learned taste state (SCORING §9.3): profile DATA written by the learner,
 * never configuration. Facet weights ∈ [0,1] keyed by AppealFacet; event
 * counts feed the α cold-start interpolation. PROPRIETARY ZONE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_taste_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->jsonb('facet_weights')->default('{}');        // AppealFacet => w ∈ [0,1]; absent = 0.5 neutral
            $table->jsonb('event_counts')->default('{}');         // FeedbackEvent => count (α's n_eff inputs)
            $table->unsignedSmallInteger('walk_tolerance_minutes')->default(15);
            $table->unsignedTinyInteger('price_band')->default(2); // 1 cheap · 2 mid · 3 doesn't matter
            $table->timestampTz('calibration_completed_at')->nullable();

            $table->string('profile_model_version', 32);          // η table version (PRD §15.1)

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_taste_profiles');
    }
};
