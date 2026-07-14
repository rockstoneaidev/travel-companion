<?php

declare(strict_types=1);

namespace App\Providers;

use App\Cost\Services\CostLedger;
use App\Cost\Services\CostMeter;
use App\Cost\Services\PriceBook;
use App\Enums\CostActorKind;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the cost ledger to the two places money is actually spent (COST.md §5).
 *
 * ---------------------------------------------------------------------------
 *  Why the queue side is a global listener and not a job middleware
 * ---------------------------------------------------------------------------
 *
 * COST.md §5 proposed a `MetersCost` job middleware. Building it, the flaw showed:
 * a middleware is OPT-IN, so the first job whose author has not read this file
 * spends money invisibly — and it will be a job, because the expensive things here
 * (voice, pack drafting, ingest) are all jobs. The point of metering is to survive
 * the person who does not know it exists.
 *
 * So the queue flush is a listener on JobProcessed/JobFailed, exactly mirroring the
 * global HTTP hook the codebase already had and for exactly the reason its comment
 * gives: "a paid API added here shows up in the trace whether or not whoever added it
 * remembered to instrument it." Coverage by default beats correctness by convention.
 * (COST.md §5 has been amended to say so.)
 *
 * A job that knows whose behalf it acts still says so — GenerateOpportunityVoiceJob
 * calls `actingAs()` with the user whose feed lit the fuse. A job that says nothing
 * is booked as `system`, which is the honest answer for ingest and pack drafting: it
 * is capex, spent on nobody's behalf, and attributing it to whichever admin ran the
 * command is how one operator comes to "cost" €400 (COST.md §2.1).
 */
final class CostServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * SCOPED, not singleton — this is bug 1 from COST.md §9.
         *
         * The old meter was a singleton with a `reset()` nobody called. In a web
         * request that is harmless (the process dies). In a Horizon worker, which is a
         * long-lived process, the meter accumulated across every job it ever ran: the
         * tenth job's trace carried the first nine jobs' costs.
         *
         * Laravel flushes scoped instances between queued jobs, which fixes it — and
         * the ledger drains the meter on every flush besides, so there are two
         * independent reasons a job cannot inherit its predecessor's spend. Belt and
         * braces, because the failure is silent and the data it corrupts is the data
         * we would use to notice.
         */
        $this->app->scoped(CostMeter::class);
        $this->app->singleton(PriceBook::class);
    }

    public function boot(): void
    {
        $this->meterOutboundHttp();
        $this->flushOnConsoleCommands();
        $this->flushOnQueuedJobs();
    }

    /**
     * Every outbound HTTP call, wherever it is made from.
     *
     * Instrumenting at the client rather than at each call site is the point: a paid
     * API added on the serve path shows up without anyone having to remember to meter
     * it. The host is all this hook gets — and all it should get. The full URI carries
     * query strings, and ours carry coordinates; writing one into `cost_events` would
     * re-open ROPA finding B1 in a table we designed ourselves.
     */
    private function meterOutboundHttp(): void
    {
        Event::listen(function (ResponseReceived $event): void {
            $host = (string) $event->request->toPsrRequest()->getUri()->getHost();

            $prices = $this->app->make(PriceBook::class);

            $this->app->make(CostMeter::class)->recordApiCall(
                host: $host,
                vendor: $prices->vendorForHost($host),
                // null for the Gemini host: GeminiClient records those with their real
                // token counts, and pricing them here as well would double-count every
                // generation on the product's only paid path.
                resource: $prices->resourceForHost($host),
            );
        });
    }

    /**
     * A CONSOLE COMMAND IS A UNIT OF WORK TOO, and it was the one that got away.
     *
     * The ledger flushed on a terminating HTTP request and on JobProcessed. An artisan
     * command is neither — so `curation:draft-pack` called Gemini once per candidate,
     * spent real money, and wrote NOTHING to cost_events. Drafting the eight local packs
     * ran ~130 capable-tier generations and the ledger did not move a cent: it still read
     * $0.0250, exactly what it had said before.
     *
     * That is the same failure this provider's own comment argues against — "coverage by
     * default beats correctness by convention". The queue seam was built precisely because
     * an opt-in middleware would miss the job whose author had not read the file. Then the
     * console, which is where the most expensive thing in the product is actually
     * triggered, was left opt-in by omission.
     *
     * The cap never fired either, which is the sharper end of it: SpendGuard is booked from
     * the ledger flush, so an unflushed command cannot trip the kill-switch. An admin
     * looping a pack draft could have spent the daily cap several times over and the guard
     * would have gone on reporting $0.00.
     *
     * Booked as `system`: a command is spent on nobody's behalf. `region` and the rest are
     * set by whatever the command itself calls (DraftPackFromWorldModel and friends).
     */
    private function flushOnConsoleCommands(): void
    {
        Event::listen(function (CommandStarting $event): void {
            $this->app->make(CostMeter::class)->actingAs(CostActorKind::System);
        });

        Event::listen(function (CommandFinished $event): void {
            $meter = $this->app->make(CostMeter::class);

            $meter->recordCompute(
                resource: 'command:'.($event->command ?? 'unknown'),
                cpuMs: $this->cpuMs(),
                peakMemKb: (int) round(memory_get_peak_usage(true) / 1024),
            );

            $this->app->make(CostLedger::class)->flush($meter);
        });
    }

    private function flushOnQueuedJobs(): void
    {
        Event::listen(function (JobProcessing $event): void {
            if ($this->isSync($event->connectionName)) {
                return;
            }

            // Default for queued work: nobody's usage. A job that knows better says so.
            $this->app->make(CostMeter::class)->actingAs(CostActorKind::System);
        });

        // Both terminal outcomes flush. A job that failed AFTER calling Gemini spent
        // the money regardless of how it ended, and a ledger that only records
        // successes would under-report exactly the runaway-retry case it exists to catch.
        Event::listen(function (JobProcessed|JobFailed $event): void {
            if ($this->isSync($event->connectionName)) {
                return;
            }

            $meter = $this->app->make(CostMeter::class);

            $meter->recordCompute(
                resource: 'job:'.$event->job->resolveName(),
                cpuMs: $this->cpuMs(),
                peakMemKb: (int) round(memory_get_peak_usage(true) / 1024),
            );

            $this->app->make(CostLedger::class)->flush($meter);
        });
    }

    /**
     * A SYNC job is not a unit of work of its own — it runs inside one.
     *
     * `dispatchSync` (and the sync connection the test suite uses) executes the job in
     * the middle of the enclosing request, on the SAME scoped meter. So the queue
     * listeners must keep their hands off it: they would otherwise re-stamp the actor as
     * `system` mid-request and drain the request's entries into a job-shaped ledger row
     * — the feed's weather call would be booked against nobody, and the request's own
     * flush would find an empty meter.
     *
     * This is not a test-only concern. It is what "one flush per unit of work" actually
     * means: for a sync job the unit of work is the REQUEST, and the request's context
     * (this user, this trip, this session) is the correct attribution anyway — better
     * than anything the job could have reconstructed. So we let the request own it.
     *
     * The test suite caught this, which is the only reason it is not a live mis-billing.
     */
    private function isSync(?string $connection): bool
    {
        return ($connection ?? config('queue.default')) === 'sync';
    }

    /**
     * Worker CPU is cumulative across the process, so this is the process total, not
     * this job's slice. Honest limitation, and the reason compute rows are units for a
     * report-time allocation rather than a per-job bill (COST.md §2.1) — a number that
     * would be wrong as money is fine as a relative weight.
     */
    private function cpuMs(): int
    {
        $usage = getrusage();

        if ($usage === false) {
            return 0;
        }

        return (int) round(
            ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000
            + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1000
        );
    }
}
