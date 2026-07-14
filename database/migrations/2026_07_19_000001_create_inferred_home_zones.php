<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inferred home zones — a PROPOSAL, awaiting the user's word (E40; PRD §16).
 *
 * ## The single most important column is the one that is NOT here
 *
 * There is no coordinate in this table. Not a lat, not a lng, not a geography point — a
 * res-8 H3 CELL and nothing finer. That is the whole design, not an optimisation.
 *
 * The declared home zone (ROPA §8) makes a promise that "cannot be retrofitted": inside it
 * the precise coordinate is *never written* — because "we delete it on schedule" and "we
 * never had it" are different promises and only one survives a breach. An inference feature
 * that wrote down "we think you sleep at 59.31203, 18.02887" would break that promise on the
 * way to honouring it — we would have derived and stored the most sensitive coordinate the
 * user owns, and one they never chose to give us.
 *
 * So the proposal is a hexagon (~0.7 km²), coarse by construction. It is precise enough to
 * say "we think you're based around Södermalm — is that right?" and far too coarse to be an
 * address. On confirmation the home zone activates on the CELL CENTROID — so even the active
 * zone's centre is a hexagon's middle, deliberately not the user's doorstep. The promise
 * doesn't just survive inference; inference is built so it cannot be the thing that breaks it.
 *
 * ## Why it needs the user's word at all
 *
 * Because a home zone SUPPRESSES — no serving, no learning inside it. Getting it wrong by
 * inference would silently blind the product to a whole neighbourhood the user actually
 * wanted recommendations in. Suppression is a thing you opt into, never a thing we assume.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inferred_home_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The proposal, coarse by design. A CELL, never a coordinate — see the docblock.
            $table->string('h3_index', 20);

            // The evidence: how many distinct days this cell was where the user's day began
            // and ended (i.e. where they slept). This is the whole argument for calling it
            // home, kept so the proposal can be explained rather than merely asserted.
            $table->unsignedSmallInteger('nights_observed');
            $table->decimal('confidence', 4, 3);
            $table->string('inference_version', 32);

            // proposed → {confirmed | rejected}. A rejected cell is remembered so we do not
            // pester somebody about a hotel they stayed in for a week.
            $table->string('status', 16)->default('proposed');
            $table->timestampTz('decided_at')->nullable();

            $table->timestampsTz();

            // One live proposal per (user, cell): re-running the inference updates the
            // evidence on the standing proposal rather than stacking duplicates.
            $table->unique(['user_id', 'h3_index']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inferred_home_zones');
    }
};
