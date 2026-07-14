<?php

declare(strict_types=1);

namespace App\Domain\Context\Queries;

use App\Domain\Context\Contracts\SessionPositions;
use App\Domain\Context\Data\PositionFix;
use App\Domain\Context\Models\ContextEvent;
use Carbon\CarbonImmutable;

final class LatestSessionPosition implements SessionPositions
{
    public function latestFor(string $sessionId, int $maxAgeSeconds): ?PositionFix
    {
        /** @var ContextEvent|null $event */
        $event = ContextEvent::query()
            ->where('explore_session_id', $sessionId)
            // `whereNotNull` is doing privacy work, not just null-handling: an event
            // recorded inside the home zone has its coordinate stripped and keeps only
            // its H3 cell, so it is skipped here and the last position OUTSIDE the home
            // zone stands. Walking home does not re-anchor the feed onto your street.
            ->whereNotNull('location')
            ->where('occurred_at', '>=', CarbonImmutable::now()->subSeconds($maxAgeSeconds))
            ->orderByDesc('occurred_at')
            ->first();

        if ($event === null || $event->location === null) {
            return null;
        }

        return new PositionFix(
            at: $event->location,
            occurredAt: $event->occurred_at,
            accuracyMeters: $event->accuracy_meters,
        );
    }
}
