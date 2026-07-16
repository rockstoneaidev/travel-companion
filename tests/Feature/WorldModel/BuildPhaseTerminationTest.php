<?php

declare(strict_types=1);

use App\Jobs\Ingest\BuildRegionWorldModelJob;

/*
|--------------------------------------------------------------------------
| A build phase must be able to finish
|--------------------------------------------------------------------------
|
| `evidence` and `photos` re-dispatched themselves while `candidates > 0` —
| which reads like a work queue and is in fact an infinite loop. `candidates`
| means "rows that still have no extract/image", and a place whose Wikipedia
| article has no intro, or which simply has no Commons photo, can never acquire
| one. It stays a candidate for ever, so the phase re-queues itself for ever.
|
| Lyon did exactly that: `world-model evidence lyon`, candidates 26, 126 runs in
| ninety seconds, indefinitely — a job that could not finish, filling the ingest
| queue and hammering Wikipedia on every lap. It is why the queue had to be
| cleared by hand.
|
| The condition that terminates is PROGRESS, not remaining work.
|
*/

it('moves on when a batch stores nothing, however many candidates remain', function () {
    // The Lyon shape exactly: 26 rows that will never yield an extract.
    [$next, $delay] = BuildRegionWorldModelJob::afterEvidence([
        'candidates' => 26, 'extracts' => 0, 'throttled' => false,
    ]);

    expect($next)->toBe('photos')->and($delay)->toBe(0);
});

it('keeps going while it is actually storing extracts', function () {
    [$next, $delay] = BuildRegionWorldModelJob::afterEvidence([
        'candidates' => 26, 'extracts' => 12, 'throttled' => false,
    ]);

    // Progress was made, so the next batch has reason to make more.
    expect($next)->toBe('evidence')->and($delay)->toBe(0);
});

it('comes back later when Wikipedia says slow down, rather than at once', function () {
    // "We were told to slow down" is NOT "there is nothing here" — FetchWikipediaExtracts
    // is careful to distinguish them, and conflating them once silently emptied
    // Stockholm's evidence (4,326 linked articles, 20 stored). A throttled pass stores
    // nothing but WILL succeed, so it must return — after a delay, because re-asking a
    // rate limiter immediately is not slowing down.
    [$next, $delay] = BuildRegionWorldModelJob::afterEvidence([
        'candidates' => 26, 'extracts' => 0, 'throttled' => true,
    ]);

    expect($next)->toBe('evidence')->and($delay)->toBeGreaterThan(0);
});

it('keeps going while candidates remain, even if a batch fills none', function () {
    // The stall bug: a batch of places NO free source can photograph fills zero images —
    // but there are still candidates to examine downstream, so the phase must NOT stop.
    // Keying off images stalled real backfills at ~15% coverage.
    expect(BuildRegionWorldModelJob::afterPhotos(['candidates' => 40, 'images' => 0]))->toBe('photos')
        ->and(BuildRegionWorldModelJob::afterPhotos(['candidates' => 40, 'images' => 7]))->toBe('photos');

    // It finishes only when every place has been examined (each source having written a
    // photo or a "found nothing" marker), so no candidates are left.
    expect(BuildRegionWorldModelJob::afterPhotos(['candidates' => 0, 'images' => 0]))->toBe('warm');
});
