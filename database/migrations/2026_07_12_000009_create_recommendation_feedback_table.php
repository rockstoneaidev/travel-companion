<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROPRIETARY-SHELL ZONE: the feedback stream — "this feedback is the moat"
 * (PRD §14.5). Events use the fixed FeedbackEvent vocabulary; learner
 * interpretation (η) lives in the learner, versioned separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_feedback', function (Blueprint $table) {
            $table->id();

            $table->foreignUuid('recommendation_id')->constrained('recommendations')->cascadeOnDelete();
            $table->string('event', 24);                // FeedbackEvent
            $table->jsonb('metadata')->default('{}');   // opened_map, started_navigation, …
            $table->timestampTz('occurred_at');

            $table->timestampsTz();

            $table->index(['recommendation_id', 'event']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_feedback');
    }
};
