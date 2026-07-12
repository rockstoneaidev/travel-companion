<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calibration answers, one row per pair (ONBOARDING §1, PRD §13.2).
 *
 * Append-only, and versioned with `calibration_version`. Two reasons this exists
 * rather than only mutating the facet weights:
 *
 *   1. **Resumability.** The flow is interruptible by design (SCREENS S9) —
 *      choices post as they are made, and killing the app mid-flow resumes at the
 *      next unanswered pair. That is only possible if each answer is a row.
 *   2. **Explicability.** A facet weight is a number with no history. When the
 *      pair set changes (`calibration_version` v2), we need to know what each
 *      profile was actually asked — otherwise every profile calibrated under v1
 *      becomes unexplainable, and we can never re-fit.
 *
 * A skipped pair is a row too: "they were shown this and declined" is a different
 * fact from "they were never shown this", and only one of them means the flow is
 * unfinished.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('calibration_version', 16);
            $table->unsignedSmallInteger('pair_number');

            // 'a' | 'b' | null when skipped (a skip applies no update — ONBOARDING §4).
            $table->string('chosen_side', 1)->nullable();

            // What the answer TAUGHT, frozen at answer time. The content service is
            // versioned, but freezing the vectors here means a trace stays readable
            // even if v1's definition is later archived.
            $table->jsonb('chosen_facets')->default('[]');
            $table->jsonb('rejected_facets')->default('[]');

            $table->timestamps();

            // One answer per pair per user: re-answering updates, never duplicates.
            $table->unique(['user_id', 'calibration_version', 'pair_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_signals');
    }
};
