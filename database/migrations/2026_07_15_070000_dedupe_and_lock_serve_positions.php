<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A position in a serve batch is unique. It always was; nothing said so.
 *
 * Found by driving the emulator (2026-07-14): a batch came back holding TEN rows for a
 * five-item feed, every position duplicated — the same place, the same clock, the same
 * anchor, twice. The feed showed Centralbadsparken above Centralbadsparken.
 *
 * Two requests raced into `RankSession::serve()`. Both read `max(serve_group)` as 1,
 * both decided they were group 2, both planned, both persisted. Read-then-write with no
 * lock between the read and the write, in a method that takes four seconds to run — the
 * window is not small, it is enormous.
 *
 * The lock (E46/E47 fix) is the real repair. This index is the proof: with it, the
 * second writer cannot land the row even if the lock is ever bypassed, lost, or
 * refactored away. A constraint the database enforces outlives every assumption the
 * application makes about who is calling it.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Dedupe first, or the constraint cannot be created.
         *
         * Keeping one row of each (session, group, position) — the duplicates are
         * byte-identical decisions (same place, same scores, same `served_at`, same
         * anchor), so this removes a duplicated record, not a record. The trace still
         * says exactly what we served; it just stops saying it twice.
         *
         * Deduped on `ctid`, not on `created_at` or `id`. The first attempt used
         * `created_at`, which deleted NOTHING and failed the migration: the racing
         * writers landed in the same second, and `timestamptz` here is not fine-grained
         * enough to tell them apart. `ctid` is the physical row address — always
         * distinct, by definition, which is exactly the property this needs.
         */
        DB::statement(
            'DELETE FROM recommendations r
              USING recommendations keep
              WHERE r.explore_session_id IS NOT NULL
                AND r.explore_session_id = keep.explore_session_id
                AND r.serve_group = keep.serve_group
                AND r.position = keep.position
                AND r.ctid > keep.ctid'
        );

        Schema::table('recommendations', function (Blueprint $table) {
            $table->unique(['explore_session_id', 'serve_group', 'position'], 'recommendations_serve_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropUnique('recommendations_serve_slot_unique');
        });
    }
};
