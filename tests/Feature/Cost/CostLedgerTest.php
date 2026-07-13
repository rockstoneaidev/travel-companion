<?php

declare(strict_types=1);

use App\Cost\Mail\SpendCapAlert;
use App\Cost\Services\CostLedger;
use App\Cost\Services\CostMeter;
use App\Cost\Services\SpendGuard;
use App\Enums\CostActorKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E24 — the cost ledger (docs/COST.md)
|--------------------------------------------------------------------------
|
| These tests exist because the previous cost instrumentation was confidently
| wrong in three ways at once, and every one of them was invisible: a meter that
| accumulated across a worker's lifetime, a token count summed at the moment of
| capture so money could never be computed from it, and a trace field that was
| structurally always zero. None of it was caught, because nothing asserted the
| numbers were TRUE — only that they were present.
|
| So each test below pins one of those failures.
|
*/

beforeEach(function () {
    Cache::flush();   // the guard's counters are Redis-shaped; a stale day would gate the next test
});

function ledger(): CostLedger
{
    return app(CostLedger::class);
}

it('prices a generation from the token split, not from a summed count', function () {
    $meter = app(CostMeter::class);
    $meter->actingAs(CostActorKind::User, userId: 7);

    // flash-lite: $0.25/M in, $1.50/M out (config/pricing.php, 2026-07).
    // 1,500 in  → 1500 × 250_000 / 1e6 =   375 micros
    //   150 out →  150 × 1_500_000 / 1e6 = 225 micros
    //                                    = 600 micros = $0.0006
    $meter->recordLlm(
        model: 'gemini-3.1-flash-lite',
        inputTokens: 1_500,
        outputTokens: 150,
        promptVersion: 'opportunity_summary.v1',
    );

    expect(ledger()->flush($meter))->toBe(1);

    $row = DB::table('cost_events')->where('category', 'llm')->first();

    expect((int) $row->billed_usd_micros)->toBe(600)
        ->and((int) $row->input_tokens)->toBe(1_500)
        ->and((int) $row->output_tokens)->toBe(150)
        ->and($row->user_id)->toBe(7)
        ->and($row->price_version)->toBe('2026-07');

    // The summed count (1,650 tokens) cannot produce 600 micros at any single rate.
    // That is the whole argument for keeping the split, made arithmetically.
});

it('bills a cache hit at zero and still records what it would have cost', function () {
    $meter = app(CostMeter::class);

    $meter->recordLlm(
        model: 'gemini-3.1-flash-lite',
        inputTokens: 1_500,
        outputTokens: 150,
        cached: true,
    );

    ledger()->flush($meter);

    $row = DB::table('cost_events')->first();

    // Σ(would_have_billed − billed) is the number that says whether shared caching is
    // paying for itself (conventions/12). A hit that recorded silence would be
    // indistinguishable from a request that never happened — and the difference between
    // those two IS the saving.
    expect((int) $row->billed_usd_micros)->toBe(0)
        ->and((int) $row->would_have_billed_usd_micros)->toBe(600)
        ->and($row->cached)->toBeTrue();
});

it('does not let one job inherit the spend of the job before it', function () {
    // Bug 1 (COST.md §9). The old meter was a singleton whose reset() had no callers, so
    // in a long-lived Horizon worker it accumulated forever: the tenth job's trace
    // carried the first nine jobs' costs. This is the regression test, and it asserts
    // the property that actually matters — not "the binding is scoped", but "the second
    // flush cannot see the first job's tokens".
    $meter = app(CostMeter::class);

    $meter->recordLlm(model: 'gemini-3.1-flash-lite', inputTokens: 1_000, outputTokens: 100);
    ledger()->flush($meter);

    // Same in-process meter instance, as a worker would hand it to the next job.
    $meter->recordLlm(model: 'gemini-3.1-flash-lite', inputTokens: 200, outputTokens: 20);
    ledger()->flush($meter);

    $rows = DB::table('cost_events')->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and((int) $rows[1]->input_tokens)->toBe(200)   // NOT 1,200
        ->and((int) $rows[1]->output_tokens)->toBe(20);
});

it('books ingest spend as system capex, never against the admin who triggered it', function () {
    // Attribute a €12 region build to whoever clicked the button and that operator
    // "costs" more than every real user combined — and every per-user number in the
    // product becomes garbage (COST.md §2.1).
    $meter = app(CostMeter::class);
    $meter->actingAs(CostActorKind::System)->onRegion('stockholm');

    $meter->recordLlm(model: 'gemini-3.5-flash', inputTokens: 3_000, outputTokens: 400);

    ledger()->flush($meter);

    $row = DB::table('cost_events')->first();

    expect($row->actor_kind)->toBe('system')
        ->and($row->user_id)->toBeNull()
        ->and($row->region_key)->toBe('stockholm')
        // 3,000 × $1.50/M + 400 × $9.00/M = 4,500 + 3,600 = 8,100 micros
        ->and((int) $row->billed_usd_micros)->toBe(8_100);
});

it('never lets a ledger failure take down the request that spent the money', function () {
    // Rule 2 of CostLedger, and the reason the whole class is wrapped in a try/catch: an
    // accounting bug must not become an outage. The day a missing partition can 500 a
    // feed is the day someone rips the metering out entirely.
    //
    // The savepoint is a TEST artefact, not a production concern, and it is worth
    // knowing why: Postgres aborts the surrounding transaction as soon as any statement
    // in it fails, so under RefreshDatabase (which wraps each test in one) the swallowed
    // insert would poison every query after it. In production the flush runs from a
    // terminating middleware or a JobProcessed listener — never inside a transaction —
    // so the failure is genuinely contained. Here we contain it by hand.
    DB::beginTransaction();

    DB::statement('ALTER TABLE cost_events RENAME TO cost_events_hidden');

    $meter = app(CostMeter::class);
    $meter->recordLlm(model: 'gemini-3.1-flash-lite', inputTokens: 10, outputTokens: 1);

    // Does not throw. Logs loudly, returns 0 rows written, and the caller — a user
    // standing on a street corner waiting for a feed — never learns that anything
    // happened at all.
    expect(ledger()->flush($meter))->toBe(0);

    DB::rollBack();

    // The ledger is intact and usable afterwards.
    expect(DB::table('cost_events')->count())->toBe(0);
});

it('records only the host of an outbound call, never the URI', function () {
    // ROPA finding B1: Pulse logged the full Open-Meteo URL, whose query string carries
    // the user's precise coordinates. We are not re-opening that hole in a table we
    // designed ourselves.
    $meter = app(CostMeter::class);
    $meter->recordApiCall('api.open-meteo.com', 'api.open-meteo.com', 'free');

    ledger()->flush($meter);

    $row = DB::table('cost_events')->where('category', 'api')->first();

    expect($row->host)->toBe('api.open-meteo.com')
        ->and((int) $row->billed_usd_micros)->toBe(0);   // free, and still counted

    // No column can hold a URI: the schema itself is the guarantee.
    $columns = array_map(
        fn ($c): string => $c->column_name,
        DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'cost_events'"),
    );

    expect($columns)->not->toContain('uri')->and($columns)->not->toContain('url');
});

/*
|--------------------------------------------------------------------------
| The kill-switch (COST.md §8)
|--------------------------------------------------------------------------
*/

it('blocks paid calls once the daily cap is reached, and degrades rather than failing', function () {
    config()->set('cost.caps.daily_usd', 0.001);   // 1,000 micros
    Mail::fake();

    $guard = app(SpendGuard::class);

    expect($guard->blocked())->toBeFalse();

    $meter = app(CostMeter::class);
    $meter->recordLlm(model: 'gemini-3.1-flash-lite', inputTokens: 1_500, outputTokens: 150);   // 600
    ledger()->flush($meter);

    expect($guard->blocked())->toBeFalse();   // 600 of 1,000

    $meter->recordLlm(model: 'gemini-3.1-flash-lite', inputTokens: 1_500, outputTokens: 150);   // 1,200
    ledger()->flush($meter);

    expect($guard->blocked())->toBeTrue();

    // And the operator hears about it before they read it on a dashboard. The alert is a
    // Mailable precisely so this line can exist: MailFake::raw() records nothing, so the
    // first draft of the alert was unassertable — an alert you cannot test is an alert
    // you find out about on the day it does not arrive.
    Mail::assertSent(SpendCapAlert::class, fn (SpendCapAlert $m): bool => $m->percent === 100);

    // Three, not two: the second flush jumps 600 → 1,200 micros and crosses BOTH the 80%
    // and the 100% thresholds in one go. A guard that only fired the highest threshold it
    // passed would be tidier and would quietly drop the 80% warning on exactly the spike
    // that most deserves one.
    Mail::assertSentCount(3);   // 50% on the first flush; 80% and 100% on the second
});

it('warns an operator once per threshold, not once per call', function () {
    // A cap alert that fires on every subsequent paid call is an alert that gets a mail
    // rule, and a mail rule is how you stop seeing the one that matters.
    config()->set('cost.caps.daily_usd', 0.001);
    Mail::fake();

    $meter = app(CostMeter::class);

    foreach (range(1, 4) as $_) {
        $meter->recordLlm(model: 'gemini-3.1-flash-lite', inputTokens: 1_500, outputTokens: 150);
        ledger()->flush($meter);
    }

    Mail::assertSentCount(3);   // still just 50/80/100, four flushes later
});

it('caps one runaway user without taking the fleet down with them', function () {
    config()->set('cost.caps.daily_usd', 1_000.0);        // fleet: effectively unlimited
    config()->set('cost.caps.per_user_daily_usd', 0.0005); // this user: 500 micros
    config()->set('cost.alerts.enabled', false);

    $meter = app(CostMeter::class);
    $meter->actingAs(CostActorKind::User, userId: 42);
    $meter->recordLlm(model: 'gemini-3.1-flash-lite', inputTokens: 1_500, outputTokens: 150);   // 600
    ledger()->flush($meter);

    $guard = app(SpendGuard::class);

    expect($guard->blocked(userId: 42))->toBeTrue()      // the looping client is stopped
        ->and($guard->blocked(userId: 43))->toBeFalse()  // ...and nobody else notices
        ->and($guard->blocked())->toBeFalse();
});

it('lets an operator pause every paid call by hand', function () {
    $guard = app(SpendGuard::class);

    expect($guard->blocked())->toBeFalse();

    $guard->pause();
    expect($guard->blocked())->toBeTrue();

    $guard->resume();
    expect($guard->blocked())->toBeFalse();
});
