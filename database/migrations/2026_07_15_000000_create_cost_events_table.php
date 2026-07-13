<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The cost ledger (docs/COST.md §5).
 *
 * Append-only. No UPDATE, no DELETE, except the two the retention policy performs
 * (§10): nulling the identifying columns at 90 days, and dropping whole partitions
 * at 24 months.
 *
 * ---------------------------------------------------------------------------
 *  Three decisions in this file are load-bearing. None is an aesthetic choice.
 * ---------------------------------------------------------------------------
 *
 * 1. NO FOREIGN KEY TO `users`. Everywhere else in this schema, user-scoped rows
 *    cascade from `users` and DeleteAccount stays short because of it. Here a
 *    cascade would be a bug: erasing an account would delete the accounting, and
 *    "a user asked to be forgotten" must not mean "a month of spend vanished from
 *    the P&L". So erasure NULLS `user_id` instead (COST.md §10) — the person is
 *    detached, the money stays.
 *
 *    This is exactly the shape of ROPA finding B7, where `activity_log` and
 *    `pulse_entries` survived deletion *because* they had no user FK — and the
 *    erasure test could not see them, since it enumerates information_schema for
 *    columns named `user_id`. The difference is that this table HAS a `user_id`
 *    column, so that enumeration finds it; and the test asserts it is NULLED rather
 *    than deleted. Deliberate, tested, and written down in three places, because a
 *    missing FK that is a decision looks identical to a missing FK that is a
 *    mistake.
 *
 * 2. PARTITIONED BY MONTH on `occurred_at`. Retrofitting partitioning onto a live
 *    ledger is a full table rewrite, and the whole argument of COST.md §1 is that
 *    the instrumentation you did not build cannot be reconstructed. Cheap now.
 *    Partitions are created ahead by EnsureCostPartitionsCommand; the ledger writer
 *    treats a failed insert as a logged error and never as a failed request, so a
 *    missing partition can cost us a row of accounting but can never cost a user
 *    their feed.
 *
 * 3. MONEY IS INTEGER USD MICROS. Never a float, never a decimal cast in PHP. Both
 *    Google and Gemini bill in USD; EUR conversion is a report-time concern with a
 *    dated rate (COST.md §2.4).
 */
return new class extends Migration
{
    /** Months of partitions created up front. The ensure-command keeps this window rolling. */
    private const MONTHS_AHEAD = 18;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE cost_events (
                id                            bigserial   NOT NULL,

                -- When the money was SPENT, not when the row landed. Cost is
                -- asynchronous (the voice job bills long after the feed was served),
                -- so these two differ and only the first one is a fact about spend.
                occurred_at                   timestamptz NOT NULL,

                actor_kind                    varchar(32) NOT NULL,
                category                      varchar(16) NOT NULL,
                vendor                        varchar(64) NOT NULL,
                resource                      varchar(64) NOT NULL,

                -- HOST ONLY. Never a URI, never a query string: ours carry
                -- coordinates, and writing one here would re-open ROPA finding B1
                -- (Pulse logging the Open-Meteo URL) in a table we designed ourselves.
                host                          varchar(255),

                -- Nullable, all of them. Cost ACCRETES to correlation ids after the
                -- fact — the tokens for a feed are spent in another process minutes
                -- later — so a row that knows only "some user, some session" is
                -- normal and honest, and a schema that demanded more would just make
                -- the writer invent it.
                user_id                       bigint,
                trip_id                       uuid,
                session_id                    uuid,
                recommendation_id             uuid,
                opportunity_id                uuid,

                -- Reserved for the possible Phase 3 per-user chat (COST.md §5.1).
                -- Nothing writes it today. A spare nullable column is free; a
                -- backfill across a partitioned ledger is not.
                conversation_id               uuid,

                -- Regions are string-keyed reference data in code, not a table.
                region_key                    varchar(64),

                h3_cell                       varchar(20),

                model                         varchar(64),
                prompt_version                varchar(64),

                -- The token SPLIT, which is the entire point. Gemini prices input,
                -- output and cached input at three different rates, so a summed
                -- token count cannot be turned back into money. GeminiClient used to
                -- add them together one line after extracting them.
                input_tokens                  integer     NOT NULL DEFAULT 0,
                output_tokens                 integer     NOT NULL DEFAULT 0,
                cached_input_tokens           integer     NOT NULL DEFAULT 0,

                calls                         integer     NOT NULL DEFAULT 0,
                cpu_ms                        integer     NOT NULL DEFAULT 0,
                peak_mem_kb                   integer     NOT NULL DEFAULT 0,

                billed_usd_micros             bigint      NOT NULL DEFAULT 0,

                -- The counterfactual: what this WOULD have cost had the cache missed.
                -- Σ(would_have_billed − billed) is the number conventions/12 calls a
                -- product metric — whether shared caching is actually paying for
                -- itself. It is unknowable later, so it is recorded now.
                would_have_billed_usd_micros  bigint      NOT NULL DEFAULT 0,

                cached                        boolean     NOT NULL DEFAULT false,

                -- Which dated sheet in config/pricing.php priced this row. Without it
                -- a price change silently re-prices history (COST.md §2.4).
                price_version                 varchar(16) NOT NULL,

                created_at                    timestamptz NOT NULL DEFAULT now(),

                -- Postgres requires the partition key in every unique index.
                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at);
        SQL);

        DB::statement('CREATE INDEX cost_events_occurred_at_index ON cost_events (occurred_at)');
        DB::statement('CREATE INDEX cost_events_user_id_occurred_at_index ON cost_events (user_id, occurred_at)');
        DB::statement('CREATE INDEX cost_events_category_occurred_at_index ON cost_events (category, occurred_at)');
        DB::statement('CREATE INDEX cost_events_vendor_resource_occurred_at_index ON cost_events (vendor, resource, occurred_at)');
        DB::statement('CREATE INDEX cost_events_actor_kind_occurred_at_index ON cost_events (actor_kind, occurred_at)');

        // The rolling window starts at the beginning of the current month, so the
        // migration is safe to run mid-month on a database that already has traffic.
        $month = now()->startOfMonth();

        for ($i = 0; $i < self::MONTHS_AHEAD; $i++) {
            $this->createPartition($month->copy()->addMonths($i));
        }
    }

    public function down(): void
    {
        // Partitions go with the parent.
        Schema::dropIfExists('cost_events');
    }

    /**
     * One month, [start, next), named for the month it holds.
     *
     * Public-ish shape on purpose: EnsureCostPartitionsCommand runs the identical
     * statement. `IF NOT EXISTS` makes both idempotent, which is what lets the
     * command run on every deploy without anyone thinking about it.
     */
    private function createPartition(Carbon $month): void
    {
        $name = 'cost_events_'.$month->format('Y_m');
        $from = $month->format('Y-m-d');
        $to = $month->copy()->addMonth()->format('Y-m-d');

        DB::statement(
            "CREATE TABLE IF NOT EXISTS {$name} PARTITION OF cost_events FOR VALUES FROM ('{$from}') TO ('{$to}')"
        );
    }
};
