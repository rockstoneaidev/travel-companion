<?php

declare(strict_types=1);

use App\Domain\Context\Enums\ContextSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `context_source` — the flag that keeps our own testing out of our own numbers (E47).
 *
 * The position emulator (ADMIN §6) drives the REAL pipeline from a fabricated position:
 * same ingestion boundary, same scouts, same scoring, same feed. That is the entire
 * point of it, and it is exactly why it is dangerous — an operator walking a synthetic
 * path through Stockholm produces feedback, spend and decision traces indistinguishable
 * from a real traveller's. ADMIN §14 calls keeping them apart "a CLAUDE.md-grade
 * invariant", and this column is how it is kept.
 *
 * Three tables, because the flag has to survive every hop the pipeline makes:
 *
 *   - `explore_sessions` — the ROOT. Provenance is a property of the session; a client
 *     can never assert it in a request body, so no phone can be mislabelled and no
 *     operator can forge a real one.
 *   - `context_events`   — inherited from the session at write time (§6: "every context
 *     event records context_source").
 *   - `recommendations`  — inherited at serve time. §6: the value "propagates onto the
 *     decision trace of everything downstream". This is the column the learner and the
 *     gold-trace recorder actually read.
 *
 * Default `device`, so every row that predates the emulator is what it has always been:
 * real. Backfilling anything else would be inventing history.
 */
return new class extends Migration
{
    public function up(): void
    {
        $device = ContextSource::Device->value;

        Schema::table('explore_sessions', function (Blueprint $table) use ($device) {
            $table->string('context_source', 16)->default($device)->after('travel_mode');

            // The index exists for one query that has to be fast and correct: "the
            // operator's ACTIVE session" — which must never hand someone their emulator
            // session by mistake (FindActiveExploreSessionForUser).
            $table->index(['user_id', 'context_source', 'status']);
        });

        Schema::table('context_events', function (Blueprint $table) use ($device) {
            $table->string('context_source', 16)->default($device)->after('user_id');
        });

        Schema::table('recommendations', function (Blueprint $table) use ($device) {
            $table->string('context_source', 16)->default($device)->after('serve_reason');

            // The learner, the digest and the gold-trace recorder all ask "was this
            // real?"; none of them should have to table-scan to find out.
            $table->index(['context_source', 'served_at']);
        });
    }

    public function down(): void
    {
        Schema::table('explore_sessions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'context_source', 'status']);
            $table->dropColumn('context_source');
        });

        Schema::table('context_events', function (Blueprint $table) {
            $table->dropColumn('context_source');
        });

        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropIndex(['context_source', 'served_at']);
            $table->dropColumn('context_source');
        });
    }
};
