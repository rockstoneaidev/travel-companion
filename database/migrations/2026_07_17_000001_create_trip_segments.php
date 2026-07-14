<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trip segments — the trip model gets thick (E38; PRD §6.6, §14.2).
 *
 * ## What a segment is, and what it is emphatically not
 *
 * PRD §6.6 draws the line and it is worth restating, because the two words sound alike:
 *
 *   - an **explore session** is a REQUEST THE USER MADE — "I have three hours from here".
 *   - a **segment** is an INFERENCE WE DREW — "the 14th looks like a travel day".
 *
 * Orthogonal. Nobody creates a segment; nobody sees one directly. It exists so that
 * scoring can ask *where in the journey is this person* without every sub-score having
 * to re-derive it from raw pings.
 *
 * ## Why this is a table and not a computed property
 *
 * Because it is a JUDGEMENT, and judgements need a version stamp (PRD §15). Tempo
 * inference is a heuristic over movement, and the heuristic will change. When it does,
 * `inference_version` is what lets the replayer answer "would v2 have called the 14th a
 * relaxation day?" — a question that has no meaning if the classification is recomputed
 * from current code every time it is read.
 *
 * It is also cheap. A trip has days, not millions of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_segments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained('trips')->cascadeOnDelete();

            /*
             * The tempo (PRD §6.6): travel / sightseeing / relaxation.
             *
             * A route-leg is not a fourth kind. A travel day IS a route leg — the day you
             * ended up somewhere else — and inventing a separate concept for it would mean
             * two tables disagreeing about the same Tuesday.
             */
            $table->string('kind', 24);              // TripSegmentKind

            $table->date('day');                     // the local day this segment classifies
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');

            /*
             * The evidence the call was made from — kept because a classification you
             * cannot argue with is a classification you cannot debug.
             */
            $table->unsignedInteger('net_displacement_m');   // start of day → end of day
            $table->unsignedInteger('span_m');               // the widest the day got
            $table->unsignedSmallInteger('distinct_cells');  // res-8 tiles touched
            $table->unsignedSmallInteger('events');          // how much we actually saw

            $table->decimal('confidence', 4, 3);
            $table->string('inference_version', 32);

            $table->timestampsTz();

            // One classification per (trip, day, version): re-running the inference
            // updates the day rather than growing a pile of opinions about it.
            $table->unique(['trip_id', 'day', 'inference_version']);
            $table->index(['trip_id', 'day']);
        });

        Schema::table('trips', function (Blueprint $table) {
            /*
             * THE STAY-AWARE HORIZON (SCORING §4.3).
             *
             * The single most valuable thing a trip can know about itself. With a departure
             * time, two behaviours the product has always wanted fall out **for free**, with
             * no special-casing anywhere:
             *
             *   - an evergreen place gets slack ≈ the length of the stay → urgency ≈ 0. It
             *     will still be there on Thursday, and the feed stops pretending otherwise.
             *   - the LAST DAY makes everything urgent, because it is genuinely the last
             *     chance. Not a rule we wrote — a consequence of the horizon shrinking.
             *
             * Nullable, and it must stay nullable: Phase 1 trips are implicit (§6.6) and have
             * no declared departure. `NULL` means "we do not know", and the scorer falls back
             * to the Phase 1 horizon (end of day) rather than guessing.
             */
            $table->timestampTz('departs_at')->nullable()->after('ended_at');

            // Whether `departs_at` was told to us or inferred. A guess and a fact must never
            // be indistinguishable in a column the ranker leans on this hard.
            $table->string('departure_source', 16)->nullable()->after('departs_at');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['departs_at', 'departure_source']);
        });

        Schema::dropIfExists('trip_segments');
    }
};
