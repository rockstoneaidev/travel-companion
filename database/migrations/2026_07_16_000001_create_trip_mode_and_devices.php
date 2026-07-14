<?php

declare(strict_types=1);

use App\Domain\Context\Enums\ContextSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trip Mode — the server side of the proactive companion (E29; PRD §8.2, §13.4, §14.5).
 *
 * Three moves, and the third is the one with teeth.
 *
 * 1. **Trip Mode is a lifecycle on the trip, not a flag.** `trip_mode_started_at` /
 *    `trip_mode_ended_at`, so the record says *when someone agreed to be followed and
 *    when they stopped agreeing* — not merely that they once did. PRD §16 calls the
 *    modes explicit; a boolean cannot be explicit about a moment.
 *
 * 2. **A device registry.** A push token is the address of a person's pocket. It is
 *    personal data, it goes in ROPA, and it cascades on account deletion.
 *
 * 3. **`context_events.explore_session_id` becomes NULLABLE**, and that is the load-bearing
 *    change in this whole epic. Phase 1's context event is a child of an *explore session*
 *    — "I have 3 hours from here". A Trip Mode event has no session: the app is in the
 *    user's pocket and the phone noticed they moved. Its parent is the TRIP. The column
 *    was `NOT NULL` and every background event would have needed a fake session to hang
 *    off, which is how you end up with a table of lies about what people were doing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            /*
             * WHEN they opted in, not merely THAT they did.
             *
             * "Explicit modes — no passive companionship unless the user turns it on"
             * (PRD §16). The day someone asks "why did this thing follow me across
             * France?", the answer has to be a timestamp, and it has to be one we can
             * show them.
             */
            $table->timestampTz('trip_mode_started_at')->nullable()->after('ended_at');
            $table->timestampTz('trip_mode_ended_at')->nullable()->after('trip_mode_started_at');

            /*
             * Provenance, rooted on the trip exactly as it is rooted on the session
             * (ADMIN §6, E47). A background event inherits it from the trip, so an
             * emulated walk driven from /admin/emulator can never teach a taste profile
             * or poison a cost metric — no matter which door it came in through.
             */
            $table->string('context_source', 16)->default(ContextSource::Device->value)->after('clustering_version');

            // "Do I have a live Trip Mode?" — asked on every background event.
            $table->index(['user_id', 'trip_mode_started_at']);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('platform', 16);          // DevicePlatform — ios | android | web

            /*
             * THE ADDRESS OF SOMEONE'S POCKET.
             *
             * A push token is personal data under any reading — it is an identifier that
             * delivers a message to a specific human's specific phone — so it is in ROPA,
             * it cascades on account deletion, and it comes back in the data export.
             *
             * Unique, because FCM/APNs reissue tokens and the same physical phone must not
             * accumulate rows; a stale token is a push sent into the void, and a duplicate
             * one is the same notification twice, which is the thing PRD §12 exists to
             * prevent.
             */
            $table->string('push_token', 512)->unique();

            $table->string('app_version', 32)->nullable();
            $table->timestampTz('last_seen_at');

            // Revoked, not deleted: "this phone stopped being reachable" is a fact worth
            // keeping until retention takes it, and a deleted row cannot explain a silence.
            $table->timestampTz('revoked_at')->nullable();

            $table->timestampsTz();

            $table->index(['user_id', 'revoked_at']);
        });

        Schema::table('context_events', function (Blueprint $table) {
            /*
             * The change this epic turns on.
             *
             * A Phase-1 context event is a child of an EXPLORE SESSION. A Trip Mode event
             * has no session — the phone is in a pocket and it noticed the user moved. Its
             * parent is the trip, which is already denormalised onto this table for the
             * privacy erase, and which is now doing real work.
             */
            $table->foreignUuid('explore_session_id')->nullable()->change();

            // Which battery tier caught this (PRD §13.4). A "low" event is a
            // significant-change ping; a "high" one means the app was open. The
            // difference is most of what the notification policy will reason about.
            $table->string('power_tier', 16)->nullable()->after('app_state');
        });
    }

    public function down(): void
    {
        Schema::table('context_events', function (Blueprint $table) {
            $table->dropColumn('power_tier');
            // Deliberately NOT restoring NOT NULL: by now there are rows without a session,
            // and re-imposing it would delete real trips' history to satisfy a rollback.
        });

        Schema::dropIfExists('devices');

        Schema::table('trips', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'trip_mode_started_at']);
            $table->dropColumn(['trip_mode_started_at', 'trip_mode_ended_at', 'context_source']);
        });
    }
};
