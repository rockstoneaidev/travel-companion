<?php

declare(strict_types=1);

namespace App\Jobs\Ingest;

use App\Domain\Places\Services\FetchPlaceImages;
use App\Enums\QueueLane;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The photos backfill as a deploy-durable queued chain (E50).
 *
 * `photos:fetch` runs the same FetchPlaceImages loop in the FOREGROUND, and a deploy kills
 * it: deploying restarts the app container and takes every foreground process down with it
 * (and wipes its log under /tmp). A 50k-place backfill that dies on every merge never
 * finishes — twice now it stalled that way. This runs ONE batch per job on the serial
 * `ingest` lane and re-dispatches itself while any candidate remains, so Horizon carries it
 * across restarts and it drains to zero on its own. Same self-continuing shape the region
 * build's photos phase uses, minus the region coupling — the fetch is global.
 *
 * ShouldBeUniqueUntilProcessing, not ShouldBeUnique: the lock releases when a batch STARTS
 * processing, so the tail-call re-dispatch is never swallowed by its own lock, and two
 * chains can never run at once (a second `--queue` while one is draining is a no-op).
 */
final class BackfillPhotosJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onQueue(QueueLane::Ingest->value);
        $this->onConnection(QueueLane::Ingest->connection());
    }

    public function handle(FetchPlaceImages $fetch): void
    {
        $result = $fetch->fetchBatch();
        Log::info('photos backfill batch', $result);

        // Keep going while places still need examining; stop when a whole pass finds none —
        // every remaining place then has an image or a "found nothing" sentinel, so the
        // candidate count has genuinely drained rather than looping on the imageless tail.
        if ($result['candidates'] > 0) {
            self::dispatch();
        }
    }
}
