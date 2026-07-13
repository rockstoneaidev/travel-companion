<?php

declare(strict_types=1);

namespace App\Domain\Sources\Data;

use App\Domain\Sources\Enums\ScoutRange;
use App\Enums\CredibilityTier;
use App\Enums\SourceLicense;
use App\Enums\StoragePolicy;
use DateInterval;

/**
 * License metadata the rest of the system reads at runtime to decide what it
 * is allowed to do (conventions/09). Every adapter registers one.
 */
final readonly class SourceDescriptor
{
    public function __construct(
        public string $key,
        public SourceLicense $license,
        public StoragePolicy $storage,
        public string $attribution,
        public DateInterval $ttl,
        public string $adapterVersion,
        public RateLimit $rateLimit,
        public CredibilityTier $credibilityTier,
        public ScoutRange $scoutRange,
        /**
         * May this source's data be kept INDEFINITELY (the opportunity archive,
         * VISION.md §2)? Orthogonal to `ttl`, which is about freshness, and to
         * `storage`, which is about where a live row may land: a source can be
         * storable-with-TTL because its terms allow caching but forbid retention
         * (many event APIs). `EdgeOnly` sources are never archivable; beyond
         * that, only the source's actual terms decide — when in doubt, false.
         */
        public bool $archivable = false,
    ) {}
}
