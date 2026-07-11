<?php

declare(strict_types=1);

use App\Domain\Trips\Enums\TripStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PROPRIETARY-SHELL ZONE: the implicit-first container that gives sessions
 * cross-session continuity (PRD §6.6) — novelty scoping, the digest, and
 * trip-level privacy deletion (PRD §16).
 *
 * `anchor_point` is the origin of the trip's first session. It is the region
 * key for the clustering (a PostGIS distance test, config/trips.php) and it is
 * NULLABLE *because it is erasable*: DELETE /trips/{trip}/location-history must
 * be able to null every raw coordinate the trip holds. A trip with a null
 * anchor no longer clusters — that is the intended cost of erasure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120)->nullable();              // user-visible; the planner path names it up front
            $table->string('status', 16)->default(TripStatus::Active->value)->index();
            $table->string('source', 8);                          // TripSource — auto | user

            $table->geography('anchor_point', subtype: 'point', srid: 4326)->nullable();
            $table->string('clustering_version', 32);             // which clustering produced this attribution (PRD §15.1)

            $table->timestampTz('started_at')->nullable();        // first session; null while `planned`
            $table->timestampTz('last_session_at')->nullable();   // the time half of the clustering test
            $table->timestampTz('ended_at')->nullable();

            $table->timestampsTz();

            $table->spatialIndex('anchor_point');
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'last_session_at']);
        });

        // "At most one active trip per user" (PRD §6.6) is a database invariant,
        // not just a domain one — the resolve-or-create action races with itself
        // the moment a user has two devices.
        DB::statement(
            'CREATE UNIQUE INDEX trips_one_active_per_user ON trips (user_id) WHERE status = \''.TripStatus::Active->value.'\''
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
