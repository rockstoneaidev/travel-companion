<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROPRIETARY-SHELL ZONE: what was actually served, with the full decision
 * trace (PRD §14.2/§15). The trace stores RAW sub-score inputs, not just the
 * scores, so the replayer can refit constants (SCORING §2.2). Session/trip
 * columns are added by the Trips epic (E4) when those tables exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();          // UUIDv7 via model

            $table->foreignUuid('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedSmallInteger('position');             // feed order (server order is the order — SCREENS S1)
            $table->jsonb('scores')->default('{}');               // sub-scores + composite
            $table->jsonb('score_inputs')->default('{}');         // RAW inputs per sub-score (SCORING §2.2)
            $table->jsonb('coverage_flags')->default('[]');       // coverage honesty (PRD §15.3)
            $table->jsonb('cost')->default('{}');                 // per-recommendation API/LLM cost (PRD §14.3)

            $table->string('scoring_model_version', 32);          // required version columns (conventions/03)
            $table->unsignedSmallInteger('taxonomy_version');
            $table->string('prompt_version', 32)->nullable();

            $table->timestampTz('served_at')->nullable();

            $table->timestampsTz();

            $table->index(['user_id', 'served_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
