<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The machine reviewer's record (CURATION §4).
 *
 * An auto-approval that leaves no trace is not a review, it is a shrug. If a claim
 * is going to be spoken to a traveller as fact because a model said it was
 * supported, then the model's verdict, the evidence span it quoted, and the version
 * of the prompt that produced the verdict all have to be on the row — or we can
 * neither audit it later nor re-run it when the verifier changes (PRD §15: version
 * everything).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curated_items', function (Blueprint $table): void {
            // The full verdict: per-claim support, the quoted span, the gate failures.
            $table->jsonb('verdict')->nullable();

            $table->timestampTz('verified_at')->nullable();

            // Which verifier said so. A claim approved by claim_verification.v1 is not
            // the same fact as one approved by v2, and when v2 disagrees we need to
            // know exactly which rows to re-check.
            $table->string('verifier_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('curated_items', function (Blueprint $table): void {
            $table->dropColumn(['verdict', 'verified_at', 'verifier_version']);
        });
    }
};
