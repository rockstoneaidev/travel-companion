<?php

declare(strict_types=1);

use App\Domain\Recommendations\Enums\ServeReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Serve batches (E46) — the schema that lets a session's feed be served more than once.
 *
 * A session used to be ranked exactly once: `RankSession::feedFor()` treated "any
 * recommendation row exists for this session" as "already served", and every later
 * read replayed those rows. So a session needed no way to say WHICH serve a row
 * belonged to, or where it was ranked FROM — the answers were "the only one" and
 * "`explore_sessions.origin`". Both stop being true here.
 *
 * - `serve_group` — 1-based batch number. The feed is the LATEST group; earlier
 *   groups are frozen and stay readable as decision traces (PRD §15.1). We never
 *   mutate or delete a superseded serve, because what we served is a fact.
 * - `serve_reason` — initial | move_reanchor | manual_refresh | dismiss_backfill.
 * - `anchor` / `anchor_h3_index` — where we ranked FROM. This is now per-batch and
 *   is no longer implied by the session origin, which keeps meaning "where the
 *   session started" and stays immutable.
 *
 * PRIVACY: `anchor` is precise location on a trace, so it is subject to PRD §16's
 * 30-day coarsening exactly like the coordinates inside `score_inputs.candidate` —
 * `CoarsenExpiredTraces` nulls it and `anchor_h3_index` carries the geography from
 * then on. `DeleteTripLocationHistory` erases it on demand. This column is the
 * reason the `RecommendationTraceEraser` seam that file left open is now closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->unsignedSmallInteger('serve_group')->default(1)->after('position');
            $table->string('serve_reason', 24)->default(ServeReason::Initial->value)->after('serve_group');

            $table->geography('anchor', subtype: 'point', srid: 4326)->nullable()->after('serve_reason');
            $table->string('anchor_h3_index', 20)->nullable()->after('anchor');
        });

        /*
         * Backfill the rows that predate batches. Every one of them was, by
         * definition, the session's only serve: group 1, reason `initial`, ranked
         * from the session origin. Copying the origin across is what keeps the
         * replayer honest on old sessions — it now reads the anchor from the row,
         * and a null there would silently re-rank history from nowhere.
         *
         * `origin_h3_index` may itself be null on the oldest rows (it was backfilled
         * separately); that is fine — a null H3 is honest about not knowing.
         */
        DB::statement(
            'UPDATE recommendations r
                SET anchor = s.origin,
                    anchor_h3_index = s.origin_h3_index
               FROM explore_sessions s
              WHERE s.id = r.explore_session_id
                AND r.anchor IS NULL'
        );

        Schema::table('recommendations', function (Blueprint $table) {
            // The replay query: "the latest group of this session, in order".
            $table->index(['explore_session_id', 'serve_group', 'position']);
            $table->spatialIndex('anchor');
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropIndex(['explore_session_id', 'serve_group', 'position']);
            $table->dropSpatialIndex(['anchor']);
            $table->dropColumn(['serve_group', 'serve_reason', 'anchor', 'anchor_h3_index']);
        });
    }
};
