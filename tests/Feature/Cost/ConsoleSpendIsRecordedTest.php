<?php

declare(strict_types=1);

use App\Cost\Services\CostMeter;
use App\Cost\Services\SpendGuard;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| A console command is a unit of work too — and it was the one that got away
|--------------------------------------------------------------------------
|
| The ledger flushed on a terminating HTTP request and on JobProcessed. An
| artisan command is neither. So `curation:draft-pack` called Gemini once per
| candidate, spent real money, and wrote NOTHING: drafting the eight local packs
| ran ~130 capable-tier generations and the ledger still read $0.0250 — exactly
| what it had said before they ran.
|
| That is the failure CostServiceProvider's own docblock argues against. The queue
| seam exists because an opt-in middleware would miss the job whose author had not
| read the file. Then the CONSOLE — where the most expensive thing in this product
| is actually triggered — was left opt-in by omission.
|
| The sharper end: SpendGuard is booked from the ledger flush, so an unflushed
| command cannot trip the kill-switch. A looping pack draft could have spent the
| daily cap several times over while the guard went on reporting $0.00.
|
*/

beforeEach(fn () => Cache::flush());

/**
 * Finish a command the way the REAL CLI finishes one.
 *
 * `$this->artisan()` cannot be used here, and the reason is worth knowing: Laravel
 * dispatches CommandStarting/CommandFinished from `Console\Application::run()`, which is
 * the path `php artisan` takes — while the test helper goes through
 * `Application::call()`, which invokes the command directly and never fires either event.
 * A test written against `$this->artisan()` would therefore pass whether or not the
 * listener existed, which is worse than no test at all.
 *
 * So the event is dispatched as the console would dispatch it. The end-to-end wiring was
 * verified separately, by running a real `artisan` command in the container and watching
 * cost_events grow.
 */
function startCommand(string $name): void
{
    Event::dispatch(new CommandStarting($name, new ArrayInput([]), new NullOutput));
}

function finishCommand(string $name): void
{
    Event::dispatch(new CommandFinished($name, new ArrayInput([]), new NullOutput, 0));
}

it('writes a command spend to the ledger when the command finishes', function () {
    // A command that spends: the meter is filled during the run, exactly as GeminiClient
    // fills it, and the flush must happen because the COMMAND ended — nothing else here
    // is an HTTP request or a queued job.
    startCommand('curation:draft-pack');

    // What GeminiClient does during a `curation:draft-pack` run.
    app(CostMeter::class)->recordLlm(
        model: 'gemini-3.5-flash',
        inputTokens: 3_000,
        outputTokens: 400,
        promptVersion: 'curated_claim.v1',
    );

    expect(DB::table('cost_events')->where('category', 'llm')->count())->toBe(0);

    finishCommand('curation:draft-pack');

    $row = DB::table('cost_events')->where('category', 'llm')->sole();

    // 3,000 × $1.50/M + 400 × $9.00/M = 4,500 + 3,600 = 8,100 micros
    expect((int) $row->billed_usd_micros)->toBe(8_100)
        // Spent on nobody's behalf. Attributing a pack draft to whoever typed the command
        // is how one operator comes to "cost" €400 (COST.md §2.1).
        ->and($row->actor_kind)->toBe('system')
        ->and($row->user_id)->toBeNull();
});

it('lets a command trip the spend cap, which it could not do before', function () {
    // The kill-switch is booked from the flush. No flush, no counter, no cap — the guard
    // would have reported $0.00 through a runaway command indefinitely.
    config()->set('cost.caps.daily_usd', 0.005);   // 5,000 micros
    config()->set('cost.alerts.enabled', false);

    expect(app(SpendGuard::class)->blocked())->toBeFalse();

    startCommand('curation:draft-pack');
    app(CostMeter::class)->recordLlm(model: 'gemini-3.5-flash', inputTokens: 3_000, outputTokens: 400);

    finishCommand('curation:draft-pack');

    // 8,100 micros against a 5,000 cap.
    expect(app(SpendGuard::class)->spentTodayMicros())->toBe(8_100)
        ->and(app(SpendGuard::class)->blocked())->toBeTrue();
});

it('records what a command cost the machine, even when it spends nothing', function () {
    // Compute is measured, never priced (COST.md §2.1) — but a command that bought
    // nothing still burned CPU, and the row says which command it was.
    startCommand('cost:partitions');
    finishCommand('cost:partitions');

    $row = DB::table('cost_events')
        ->where('category', 'compute')
        ->where('resource', 'command:cost:partitions')
        ->sole();

    expect((int) $row->billed_usd_micros)->toBe(0)
        ->and($row->actor_kind)->toBe('system');
});
