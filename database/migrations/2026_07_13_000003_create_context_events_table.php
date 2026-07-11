<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROPRIETARY-SHELL ZONE. Session-scoped context observations (PRD §6.6, §14.2)
 * — the wire payload is PRD §14.5, and "fields degrade gracefully when absent"
 * is why almost everything here is nullable.
 *
 * `bigIncrements`, not a uuid: high-volume, append-only, nobody links to a row
 * (conventions/03).
 *
 * `trip_id` is denormalised (as it is on `recommendations`, PRD §14.2) so the
 * Privacy module can erase a trip's location history without reaching into the
 * Trips module's tables — the module boundary is enforced in code, so it must be
 * satisfiable in SQL.
 *
 * PRIVACY (PRD §16): `location` holds raw precise coordinates. It is retained
 * 30 days, then coarsened to `h3_index` and hard-deleted — that retention job is
 * E17. Trip-level deletion nulls it immediately (implemented here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('context_events', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignUuid('explore_session_id')->constrained('explore_sessions')->cascadeOnDelete();
            $table->foreignUuid('trip_id')->constrained('trips')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestampTz('occurred_at')->index();

            $table->geography('location', subtype: 'point', srid: 4326)->nullable();
            $table->unsignedSmallInteger('accuracy_meters')->nullable();
            $table->string('h3_index', 20)->nullable()->index();          // the coarsening target (E17)

            $table->string('movement_mode', 16)->nullable();              // MovementMode (observed, ≠ the session's declared TravelMode)
            $table->decimal('speed_mps', 6, 2)->nullable();
            $table->unsignedSmallInteger('heading')->nullable();          // degrees 0–359

            $table->string('app_state', 16)->nullable();                  // AppState — Phase 1 only ever sends `foreground`

            $table->decimal('battery_level', 3, 2)->nullable();           // 0.00–1.00
            $table->boolean('is_low_power_mode')->nullable();

            $table->unsignedSmallInteger('available_minutes')->nullable();
            $table->jsonb('companions')->default('[]');

            $table->timestampsTz();

            $table->spatialIndex('location');
            $table->index(['explore_session_id', 'occurred_at']);         // the feed's cursor key (conventions/07)
            $table->index(['trip_id', 'occurred_at']);                    // the privacy erase / retention scan
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('context_events');
    }
};
