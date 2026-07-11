<?php

declare(strict_types=1);

use App\Domain\Trips\Enums\ExploreSessionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROPRIETARY-SHELL ZONE. The atomic Phase 1 unit and the only thing the user
 * initiates (PRD §6.6): "I have 3 hours from here, heading that way."
 *
 * The table is `explore_sessions`, never `sessions` — Laravel owns that name for
 * its session store (PRD §6.6).
 *
 * `origin` / `destination_point` are nullable for the same reason as
 * trips.anchor_point: trip-level location deletion (PRD §16) nulls them. The
 * domain requires an origin at creation.
 *
 * `origin_h3_index` is the seam for E5: the res-8 cell (conventions/12) is the
 * scout/cache key, and the coarsening target for the 30-day retention job (E17).
 * There is no H3 binding in the runtime yet, so it stays null for now and the
 * reach/coverage math is done in PostGIS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('explore_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();          // UUIDv7 via model (insert-order locality)

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('trip_id')->constrained('trips')->cascadeOnDelete();   // trip children cascade (conventions/03, PRD §16)

            $table->geography('origin', subtype: 'point', srid: 4326)->nullable();
            $table->string('origin_h3_index', 20)->nullable()->index();                // E5 fills this; E17 coarsens to it

            $table->unsignedSmallInteger('time_budget_minutes');
            $table->string('travel_mode', 8);                                          // TravelMode — walk | bike | drive

            $table->unsignedSmallInteger('heading')->nullable();                       // degrees 0–359; cone shape (conventions/12)
            $table->geography('destination_point', subtype: 'point', srid: 4326)->nullable();   // corridor shape

            $table->string('status', 16)->default(ExploreSessionStatus::Active->value)->index();

            $table->timestampTz('started_at');
            $table->timestampTz('expires_at')->index();     // started_at + time_budget_minutes; the reaper reads this and nothing else
            $table->timestampTz('ended_at')->nullable();

            $table->timestampsTz();

            $table->spatialIndex('origin');
            $table->index(['user_id', 'status']);
            $table->index(['trip_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('explore_sessions');
    }
};
