<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The daily rollup (docs/COST.md §7.1) — where the ledger's causal truth becomes the
 * three numbers a human actually asks for.
 *
 * `cost_events` records what was SPENT and who lit the fuse. That is the only thing
 * knowable at write time, and it is deliberately not the same as what a user is
 * "worth" (COST.md §2.2):
 *
 *   · causal      — the first traveller into a cold region pays for the tile; the next
 *                   forty read it free. True, and useless as a bill.
 *   · amortised   — split a cached artifact's cost across everyone who consumed it.
 *                   The unit-economics number, and NOT knowable at request time,
 *                   because the denominator has not happened yet.
 *   · capex       — region packs and world-model builds are spent on nobody's behalf
 *                   and belong to the REGION, spread over that region's users.
 *
 * So this table is derived, disposable and rebuildable: drop it and re-run the job and
 * you get it back. It exists because those three numbers cannot be a column on an
 * append-only ledger without one column meaning two things.
 *
 * NO FK to `users` — same reason as `cost_events` (COST.md §10): erasure must detach
 * the person without deleting the accounting. `user_id` is nulled, the money stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_daily', function (Blueprint $table): void {
            $table->id();

            $table->date('day')->index();

            // Nullable: system/ingest capex belongs to a region, not a person, and after
            // de-identification (90 days) nobody's rows belong to anyone.
            $table->unsignedBigInteger('user_id')->nullable()->index();

            $table->string('actor_kind', 32);
            $table->string('category', 16);
            $table->string('vendor', 64);

            // What actually left the bank account for this bucket. Sums to the ledger.
            $table->bigInteger('billed_usd_micros')->default(0);

            /*
             * The share of the day's shared costs this user is fairly asked to carry
             * (COST.md §2.2). This does NOT sum to the ledger, and must not: it moves
             * money from the person who happened to be first into a region onto everyone
             * who benefited from what they paid for.
             *
             * The two columns disagreeing is the system working, not a bug — and the E25
             * acceptance test asserts precisely that they disagree.
             */
            $table->bigInteger('amortized_usd_micros')->default(0);

            // Region capex (packs, world-model builds) spread over the region's active
            // users, so a €12 pack does not read as one admin costing €12.
            $table->bigInteger('capex_share_usd_micros')->default(0);

            // What the caches saved this bucket: Σ(would_have_billed − billed).
            $table->bigInteger('saved_usd_micros')->default(0);

            // Units, never money (COST.md §2.1) — the denominator for the infra
            // allocation rate, which is applied at REPORT time, not stored here.
            $table->bigInteger('cpu_ms')->default(0);

            $table->integer('calls')->default(0);
            $table->bigInteger('input_tokens')->default(0);
            $table->bigInteger('output_tokens')->default(0);

            // Product denominators (ADMIN §7.1's first view: cost per active trip-hour).
            $table->integer('active_trip_minutes')->default(0);
            $table->integer('recommendations_served')->default(0);

            $table->timestamps();

            // One row per bucket per day; the rollup upserts, so it is safe to re-run for
            // a day that was already rolled up (and it will be, every time a price sheet
            // is corrected).
            $table->unique(['day', 'user_id', 'actor_kind', 'category', 'vendor'], 'cost_daily_bucket_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_daily');
    }
};
