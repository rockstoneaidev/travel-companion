<?php

declare(strict_types=1);

namespace App\Domain\Sources\Data;

use App\Domain\Sources\Enums\LocalAlertKind;
use Carbon\CarbonImmutable;

/**
 * One disruption a local feed reported (E39) — after classification, before we know where
 * it is. The geolocation happens later, against the world model, and may fail (in which
 * case the alert is dropped, never guessed).
 */
final readonly class LocalAlert
{
    public function __construct(
        public string $title,
        public ?string $summary,
        public string $url,
        public LocalAlertKind $kind,
        public string $sourceKey,
        public string $attribution,
        public ?CarbonImmutable $publishedAt,
    ) {}

    /** The text we search for a place name in — headline plus summary, if any. */
    public function searchableText(): string
    {
        return trim($this->title.' '.($this->summary ?? ''));
    }
}
