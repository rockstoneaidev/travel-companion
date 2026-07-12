<?php

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
