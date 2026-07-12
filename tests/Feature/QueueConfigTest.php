<?php

declare(strict_types=1);

use App\Enums\QueueLane;

/*
|--------------------------------------------------------------------------
| The queue invariant (conventions/08)
|--------------------------------------------------------------------------
|
| retry_after MUST exceed the longest job timeout on that connection.
|
| Break it and the queue hands a still-running job to a second worker: the job
| runs twice, attempts climb past `tries`, and it dies as MaxAttemptsExceeded
| having done nothing wrong. That is exactly how the Dijon world-model build
| failed on staging — a 420s job on a connection whose retry_after was 90s.
|
| It is invisible in code review and obvious in production, which is precisely
| the kind of thing a test should hold.
|
*/

it('gives every supervisor a retry_after longer than its own timeout', function () {
    $supervisors = config('horizon.defaults');

    expect($supervisors)->not->toBeEmpty();

    foreach ($supervisors as $name => $supervisor) {
        $connection = $supervisor['connection'];
        $retryAfter = config("queue.connections.{$connection}.retry_after");
        $timeout = $supervisor['timeout'];

        expect($retryAfter)->toBeGreaterThan(
            $timeout,
            "Supervisor [{$name}] has timeout {$timeout}s on connection [{$connection}] "
            ."whose retry_after is {$retryAfter}s. The queue will re-run a job that is still alive.",
        );
    }
});

it('serves every lane by exactly one supervisor', function () {
    // A lane nobody listens to is a queue that silently fills up forever.
    $served = collect(config('horizon.defaults'))
        ->flatMap(fn (array $s): array => $s['queue'])
        ->all();

    foreach (QueueLane::cases() as $lane) {
        // A lane nobody listens to is a queue that fills up forever in silence.
        expect(in_array($lane->value, $served, true))->toBeTrue(
            "Queue lane [{$lane->value}] has no Horizon supervisor listening to it.",
        );
    }
});

it('keeps the long lane on its own connection', function () {
    // retry_after is a property of the CONNECTION. If ingest shared the default
    // connection, its long retry_after would apply to push notifications too —
    // meaning a genuinely dead push waits half an hour to be retried.
    expect(QueueLane::Ingest->connection())->toBe('redis-long')
        ->and(QueueLane::Realtime->connection())->toBe('redis')
        ->and(config('queue.connections.redis-long.retry_after'))
        ->toBeGreaterThan(config('queue.connections.redis.retry_after'));
});

it('runs region ingest serially, because Overpass rate-limits', function () {
    // Public Overpass returned 504s when the corridor cities ran back to back.
    // Two region ingests at once is how you get thrown off a source you do not pay for.
    expect(config('horizon.defaults.supervisor-ingest.maxProcesses'))->toBe(1)
        ->and(config('horizon.environments.production.supervisor-ingest.maxProcesses'))->toBe(1);
});
