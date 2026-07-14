<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Recommendations\Contracts\FeedbackRecorder;
use Carbon\CarbonImmutable;

/**
 * What they did with the interruption (E31; PRD §12, §7.1).
 *
 * This is the moat closing. An opened push and an ignored one are the two most honest
 * labels this product will ever get about *interruption quality* — not "was the place
 * good", which the feed already measures, but "was it worth being interrupted for", which
 * nothing else can measure at all.
 *
 * It routes into the SAME feedback ledger as every tap in the app, deliberately. A
 * separate notification-feedback table would have split the learning signal in half and
 * left the taste profile learning from only the half that happened on-screen.
 */
final class RecordNotificationReceipt
{
    public function __construct(
        // Through Recommendations' published contract, never its Action or its model
        // (conventions/01 — the arch test caught me holding both).
        private readonly FeedbackRecorder $feedback,
    ) {}

    public function opened(Notification $notification, ?CarbonImmutable $at = null): void
    {
        $at ??= CarbonImmutable::now();

        if ($notification->opened_at !== null) {
            return;   // a receipt is a fact, not a counter; the first one stands
        }

        $notification->forceFill(['opened_at' => $at])->save();

        /*
         * Opened ⇒ `accepted`. A weak positive, and correctly so: they looked. Whether they
         * went is answered later, by the visit prompt, which is the golden label (§7.1).
         */
        $this->record($notification, FeedbackEvent::Accepted, $at, ['from_push' => true]);
    }

    public function dismissed(Notification $notification, ?CarbonImmutable $at = null): void
    {
        $at ??= CarbonImmutable::now();

        if ($notification->dismissed_at !== null) {
            return;
        }

        $notification->forceFill(['dismissed_at' => $at])->save();

        /*
         * Swiped away ⇒ `ignored`, NOT `dismissed`.
         *
         * This distinction is the whole subtlety of the receipt, and getting it backwards
         * would poison the taste profile. `dismissed` is "not my thing" — the strongest
         * negative the Phase 1 learner has (η .25). Swiping away a push says almost nothing
         * about the PLACE: it says the moment was wrong, they were busy, they were driving,
         * they were mid-conversation.
         *
         * Punishing a museum because someone was in a meeting is how a companion slowly
         * learns to recommend nothing.
         */
        $this->record($notification, FeedbackEvent::Ignored, $at, ['from_push' => true]);
    }

    /** @param array<string, mixed> $metadata */
    private function record(Notification $notification, FeedbackEvent $event, CarbonImmutable $at, array $metadata): void
    {
        if ($notification->recommendation_id === null) {
            return;
        }

        $this->feedback->record($notification->recommendation_id, $event, $metadata, $at);
    }
}
