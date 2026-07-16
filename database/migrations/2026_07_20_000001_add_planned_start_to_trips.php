<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a planned trip is planned to BEGIN.
 *
 * `started_at` is the activation timestamp — set when the first session actually opens.
 * A *planned* trip has no session yet, so it needs a separate "we intend to go on this
 * date" field. `departs_at` (added with E38 for the stay-aware urgency horizon) is the
 * other end. Both nullable: an implicit trip has neither, and a planner may set one, both,
 * or none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->timestampTz('planned_start_at')->nullable()->after('anchor_point');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('planned_start_at');
        });
    }
};
