<?php

use App\Jobs\Cost\RollUpCostsJob;
use App\Jobs\Ingest\ReapExpiredOpportunitiesJob;
use App\Jobs\Privacy\EnforceRetentionJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Storage limitation, on a schedule (PRD §16, GDPR Art. 5).
 *
 * Nightly, at a quiet hour, and NOT dependent on anyone remembering to run it —
 * a retention policy that needs a human in the loop is not a policy, it is an
 * intention. `withoutOverlapping` because a long pass on a big table must not be
 * started again on top of itself.
 */
Schedule::job(new EnforceRetentionJob)
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * The opportunity reap: archive-on-expiry, never plain delete (VISION.md §2).
 * Expired time-bound opportunities move their license-storable subset to the
 * archive before the row is deleted — history that is not archived tonight
 * cannot be recovered in three years. After the retention pass, same lane.
 */
Schedule::job(new ReapExpiredOpportunitiesJob)
    ->dailyAt('04:10')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * The cost ledger's partitions, kept ahead of the clock (COST.md §5).
 *
 * A partitioned table with no partition for today rejects the insert — and the ledger
 * writer swallows failures by design (an accounting bug must never become an outage),
 * so the symptom would be silence, which is the one symptom a cost system must not
 * have. Monthly, with six months of headroom, and idempotent enough to run on deploy.
 */
Schedule::command('cost:partitions')
    ->monthlyOn(1, '02:00')
    ->onOneServer();

/*
 * The daily rollup (COST.md §7.1): the ledger's causal truth becomes the amortised and
 * capex-allocated numbers a human actually asks for. Rolls YESTERDAY — a day that is
 * still happening produces a partial row the next run has to correct, and the /admin
 * strip reads the live ledger for "today" precisely so this job never has to.
 *
 * After the retention pass, deliberately: the rollup should see the day as retention
 * left it, not race it.
 */
Schedule::job(new RollUpCostsJob)
    ->dailyAt('04:40')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Does the world still charge what our price sheet says? (COST.md §6.1)
 *
 * It never writes a price — a ledger repriced by a feed that moved underneath it is not
 * an audit trail. It compares, and shouts; a human lands a new dated sheet.
 */
Schedule::command('cost:price-drift')
    ->weeklyOn(1, '05:00')
    ->onOneServer();
