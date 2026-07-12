<?php

declare(strict_types=1);

namespace App\Domain\Opportunities\Services;

use App\Domain\Opportunities\Data\SessionOpportunityData;
use Carbon\CarbonImmutable;

/**
 * The GO NOW slot (SCREENS S1): "the server guarantees at most one urgent item
 * per feed", and card order is server order — the client never decides which
 * card is urgent, and never re-sorts.
 *
 * Urgent means: the opportunity's window is open right now and it closes inside
 * the attention horizon. Of those, the one closing soonest wins the slot and is
 * promoted to the top; every other item keeps its ranked position and renders
 * as a standard card, however tight its own window.
 */
final readonly class UrgentSlot
{
    public function __construct(private int $horizonMinutes) {}

    public static function fromConfig(): self
    {
        return new self((int) config('trips.feed.urgent_horizon_minutes'));
    }

    /**
     * @param  list<SessionOpportunityData>  $feed  in ranked (server) order
     * @return list<SessionOpportunityData>
     */
    public function apply(array $feed, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $winner = null;
        foreach ($feed as $item) {
            if (! $this->isUrgent($item, $now)) {
                continue;
            }

            // Soonest close wins. windowEndsAt is non-null here by isUrgent().
            if ($winner === null || $item->windowEndsAt < $winner->windowEndsAt) {
                $winner = $item;
            }
        }

        if ($winner === null) {
            return $feed;
        }

        $promoted = $winner->asUrgent();
        $rest = array_values(array_filter($feed, static fn (SessionOpportunityData $i): bool => $i->id !== $winner->id));

        return [$promoted, ...$rest];
    }

    private function isUrgent(SessionOpportunityData $item, CarbonImmutable $now): bool
    {
        if ($item->windowEndsAt === null) {
            return false;   // evergreen: no window, never urgent by construction
        }

        if ($item->windowStartsAt !== null && $item->windowStartsAt > $now) {
            return false;   // hasn't opened yet — "go now" would be a lie
        }

        if ($item->windowEndsAt <= $now) {
            return false;   // already closed
        }

        return $item->windowEndsAt <= $now->addMinutes($this->horizonMinutes);
    }
}
