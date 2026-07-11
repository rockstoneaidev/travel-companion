<?php

declare(strict_types=1);

namespace App\Domain\Sources\Data;

/** Per-source request budget; enforced by SourceRegistry, never by adapters (conventions/09). */
final readonly class RateLimit
{
    public function __construct(
        public int $perMinute,
    ) {}
}
