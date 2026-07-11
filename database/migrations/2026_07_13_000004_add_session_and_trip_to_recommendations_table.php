<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the TODO left by 2026_07_12_000008_create_recommendations_table:
 * "Session/trip columns are added by the Trips epic (E4) when those tables
 * exist." A recommendation carries `explore_session_id` + a denormalised
 * `trip_id` (PRD §14.2) — session scope drives `repetition_penalty`, trip scope
 * drives `novelty` (PRD §6.6).
 *
 * Both are nullable (migrations are append-only; rows may predate the columns)
 * and both cascade on delete: trip children go with the trip (conventions/03,
 * PRD §16).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->foreignUuid('explore_session_id')->nullable()->after('opportunity_id')
                ->constrained('explore_sessions')->cascadeOnDelete();
            $table->foreignUuid('trip_id')->nullable()->after('explore_session_id')
                ->constrained('trips')->cascadeOnDelete();

            $table->index(['explore_session_id', 'position']);
            $table->index(['trip_id', 'served_at']);
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropIndex(['explore_session_id', 'position']);
            $table->dropIndex(['trip_id', 'served_at']);
            $table->dropConstrainedForeignId('explore_session_id');
            $table->dropConstrainedForeignId('trip_id');
        });
    }
};
