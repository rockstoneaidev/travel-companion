<?php

declare(strict_types=1);

namespace App\Jobs\Ingest;

use App\Domain\Places\Services\RefreshMapillaryImageUrls;
use App\Enums\QueueLane;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Refresh Mapillary photo URLs before their signed links expire (E50). Thin wrapper.
 */
final class RefreshMapillaryUrlsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue(QueueLane::Default->value);
    }

    public function handle(RefreshMapillaryImageUrls $refresh): void
    {
        $result = $refresh->refreshBatch();

        if ($result['refreshed'] + $result['cleared'] > 0) {
            Log::info('Mapillary URLs refreshed.', $result);
        }
    }
}
