<?php

declare(strict_types=1);

namespace App\Jobs\Ingest;

use App\Domain\Opportunities\Services\MaterializeAlertOpportunities;
use App\Domain\Sources\Services\NewsFeedReader;
use App\Enums\QueueLane;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Read a region's local feeds and turn the disruptions into located, cited, expiring
 * opportunities (E39). Thin wrapper (conventions/08).
 *
 * Runs on a schedule per region that HAS feeds configured — most do not, and for those this
 * job never dispatches. Unique per region for a few minutes: a closure does not change
 * faster than that, and hammering a local paper's RSS is exactly the discourtesy that gets
 * an IP blocked.
 */
final class PollLocalAlertsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $regionKey,
        public readonly string $locale,
    ) {
        $this->onQueue(QueueLane::Default->value);
    }

    public function uniqueId(): string
    {
        return $this->regionKey;
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    public function handle(NewsFeedReader $reader, MaterializeAlertOpportunities $materialize): void
    {
        $feeds = config("sources.news_feeds.{$this->regionKey}", []);

        if ($feeds === []) {
            return;   // no local layer here, and that is a fine and quiet state
        }

        $alerts = [];

        foreach ($feeds as $feed) {
            $alerts = [
                ...$alerts,
                ...$reader->fetch($feed['url'], $feed['source'], $feed['attribution'], $this->locale),
            ];
        }

        $ids = $materialize($alerts, $this->regionKey);

        Log::info('Local alerts polled.', [
            'region' => $this->regionKey,
            'alerts_found' => count($alerts),
            'opportunities' => count($ids),
        ]);
    }
}
