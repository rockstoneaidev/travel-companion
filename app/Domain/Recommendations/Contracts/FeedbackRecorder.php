<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Contracts;

use App\Domain\Feedback\Enums\FeedbackEvent;
use Carbon\CarbonImmutable;

/**
 * "Record what they did with this recommendation" — Recommendations' published verb
 * (conventions/01).
 *
 * Notifications needs it, because a push receipt is feedback like any other tap and must
 * land in the SAME ledger — a separate notification-feedback table would split the learning
 * signal in half and leave the taste profile learning only from the half that happened
 * on-screen.
 *
 * It may not reach into `RecordFeedback` to do it. The contract exists so the delivery side
 * can say "they opened it" without knowing what a recommendation row looks like — and so
 * that the retraction logic, the consent gate and the learner all stay on the far side of
 * one door.
 */
interface FeedbackRecorder
{
    /** @param array<string, mixed> $metadata */
    public function record(string $recommendationId, FeedbackEvent $event, array $metadata = [], ?CarbonImmutable $at = null): void;
}
