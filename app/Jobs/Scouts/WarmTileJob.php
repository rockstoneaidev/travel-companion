<?php

declare(strict_types=1);

namespace App\Jobs\Scouts;

use App\Domain\Places\Contracts\TileScout;
use App\Domain\Places\Services\TileCache;
use App\Enums\QueueLane;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Thin wrapper (conventions/08): warms one (tile, scout) cache entry.
 * ShouldBeUnique per (tile, scout) is the second stampede mechanism next to
 * the TileCache lock (conventions/12).
 */
final class WarmTileJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $scoutClass,
        public readonly string $h3Index,
    ) {
        // Thousands of tiny DB jobs; they get their own wide, short lane so they
        // never queue behind a world-model build (conventions/08).
        $this->onQueue(QueueLane::Scouts->value);
    }

    public function uniqueId(): string
    {
        return "{$this->scoutClass}:{$this->h3Index}";
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    public function handle(TileCache $cache): void
    {
        /** @var TileScout $scout */
        $scout = app($this->scoutClass);

        $cache->remember(
            $scout->key(), $this->h3Index, $scout->version(), $scout->ttl(),
            fn (): array => $scout->candidatesForTile($this->h3Index),
        );
    }
}
