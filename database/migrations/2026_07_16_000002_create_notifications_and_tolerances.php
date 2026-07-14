<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The notification ledger, and the tolerances that gate it (E30/E31; PRD §12).
 *
 * ## Why one table and not two
 *
 * PRD §14.2 names a `notification_budget` table. This is deliberately not it, and the
 * reason is the same reason the feedback ledger is append-only: **a counter can drift
 * from the thing it counts, and then it is worse than useless — it is confidently wrong.**
 *
 * Every decision the policy makes is written here, ALLOWED OR DENIED, with the gate that
 * stopped it. The budget is then a `count()` over what was actually sent today, which
 * cannot disagree with itself. And the same rows are the trace PRD §12.2 asks for:
 *
 *     "Every push records which notification_policy_version allowed it, enabling offline
 *      questions like *would policy_v3 have avoided the annoying push policy_v2 sent?*"
 *
 * A table that stored only the pushes we sent could never answer that. The interesting
 * half of a notification policy is the notifications it DIDN'T send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('trip_id')->nullable()->constrained('trips')->nullOnDelete();

            // What we wanted to say, and about what. The recommendation is the decision
            // trace it was drawn from; the deep link lands on the opportunity.
            $table->foreignUuid('recommendation_id')->nullable()->constrained('recommendations')->nullOnDelete();
            $table->foreignUuid('opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();

            /*
             * THE DECISION, and the gate that made it.
             *
             * `denied_by` is the whole point of writing the denials down. "We considered
             * interrupting you about the market and did not, because you were driving" is a
             * far more useful record than the market never appearing at all — for the
             * replayer, for the digest valve (§12.4), and for anyone who ever has to explain
             * this product to a regulator.
             */
            $table->boolean('allowed');
            $table->string('denied_by', 32)->nullable();     // NotificationGate

            // PRD §15: version everything. Without this the offline question is unaskable.
            $table->string('notification_policy_version', 32);

            // How it ranked WITHIN the allowed set (SCORING §5.3 — ordering, never gating).
            $table->decimal('priority', 6, 4)->nullable();
            $table->jsonb('trace')->nullable();               // gates evaluated, boosts, penalty inputs

            /*
             * DELIVERY (E31). Separate from the decision on purpose: "we decided to tell you"
             * and "your phone actually received it" are different facts, and conflating them
             * is how a delivery failure quietly becomes a policy that looks like it fired.
             */
            $table->foreignUuid('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->timestampTz('sent_at')->nullable();
            $table->string('delivery_error', 255)->nullable();

            // The receipt — and the moat. An opened push and an ignored one are the two most
            // honest labels this product will ever get about interruption quality (PRD §12).
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('dismissed_at')->nullable();

            $table->timestampsTz();

            // The budget query: "how many did we send this person today, and when was the
            // last one?" It runs on every candidate, so it had better be an index scan.
            $table->index(['user_id', 'sent_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            /*
             * The user's own tolerances (PRD §12.2 hard gates; SCORING §9.2).
             *
             * NAMED, BOUNDED, WHITELISTED — never a free-form constant edit. SCORING §9.2 is
             * explicit about why: "if every user can be an arbitrary model, acceptance data no
             * longer has a shared denominator to fit against". These are three enumerated
             * knobs, and they are the user saying something, not us inferring it.
             *
             * Quiet hours are stored as LOCAL HOURS, not timestamps: "don't wake me before 8"
             * is a fact about a person, not about a moment, and it must survive them crossing
             * a timezone — which, for a travel product, is the normal case rather than the
             * edge one.
             */
            $table->unsignedTinyInteger('quiet_hours_start')->nullable()->after('research_consent');
            $table->unsignedTinyInteger('quiet_hours_end')->nullable()->after('quiet_hours_start');
            $table->unsignedSmallInteger('max_detour_minutes')->nullable()->after('quiet_hours_end');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['quiet_hours_start', 'quiet_hours_end', 'max_detour_minutes']);
        });

        Schema::dropIfExists('notifications');
    }
};
