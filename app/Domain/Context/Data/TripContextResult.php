<?php

declare(strict_types=1);

namespace App\Domain\Context\Data;

use App\Domain\Context\Models\ContextEvent;

/**
 * What we did with a background ping — and, when we did nothing, WHY.
 *
 * The honesty matters more than it looks. A client that is told "202, fine" while the
 * server quietly bins its events will keep sending them forever, at whatever interval its
 * summariser fancies, burning a battery it thinks it is saving. Telling it "not_meaningful"
 * is how it learns to stop.
 *
 * And "home_zone" is deliberately reported too. It is not a secret from the user's own
 * phone that we refuse to track them at home — it is the promise, working.
 */
final readonly class TripContextResult
{
    private function __construct(
        public bool $recorded,
        public ?string $reason = null,
        public ?ContextEvent $event = null,
    ) {}

    public static function recorded(ContextEvent $event): self
    {
        return new self(recorded: true, event: $event);
    }

    /** @param 'trip_mode_off'|'home_zone'|'not_meaningful' $reason */
    public static function refused(string $reason): self
    {
        return new self(recorded: false, reason: $reason);
    }
}
