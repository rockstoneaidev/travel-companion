<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The moat must outlive the thing it was about.
 *
 * `opportunities` are ephemeral and TTL'd; `recommendations` are the permanent
 * decision trace (PRD §15) and `recommendation_feedback` is the moat (PRD §14.5).
 * But recommendations cascade-deleted from opportunities, and feedback cascades
 * from recommendations — so the first TTL reaper anyone writes would silently
 * delete the full trace of every expired opportunity *and* every accept, keep and
 * "I was here" attached to it. The replayer's gold traces would go with them.
 *
 * Nothing reaps opportunities today, so no data has been lost. This closes the
 * trapdoor before someone builds the reaper that `expires_at` is asking for.
 *
 * After this: reaping an opportunity nulls the link and keeps the trace. A
 * recommendation carries its own candidate snapshot in `score_inputs`, so it
 * still renders (SCREENS S6) — it simply stops claiming to be still possible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table): void {
            $table->dropForeign(['opportunity_id']);
            $table->uuid('opportunity_id')->nullable()->change();

            $table->foreign('opportunity_id')
                ->references('id')
                ->on('opportunities')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table): void {
            $table->dropForeign(['opportunity_id']);
            $table->uuid('opportunity_id')->nullable(false)->change();

            $table->foreign('opportunity_id')
                ->references('id')
                ->on('opportunities')
                ->cascadeOnDelete();
        });
    }
};
