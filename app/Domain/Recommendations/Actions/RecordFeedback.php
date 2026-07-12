<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Actions;

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Feedback\Services\FeedbackLedger;
use App\Domain\Profiles\Services\TasteProfiles;
use App\Domain\Recommendations\Models\Recommendation;
use DateTimeInterface;

/**
 * One feedback event, end to end (PRD §14.5, SCREENS action table): append to
 * the ledger, teach the taste profile from the served candidate's facets.
 * The ~5 s dismiss undo is client-side (SCREENS S1) — by the time this runs,
 * the user meant it.
 */
final class RecordFeedback
{
    public function __construct(
        private readonly FeedbackLedger $ledger,
        private readonly TasteProfiles $profiles,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @param  ?DateTimeInterface  $occurredAt  When the user actually tapped, which is
     *                                          NOT when we hear about it: S11 queues feedback in a dead zone
     *                                          and flushes it on reconnect. Recording the flush time would put
     *                                          a lie in the moat — and "Were you there?" reasons about elapsed
     *                                          time, so it would be a lie with consequences. Untrusted input;
     *                                          the caller clamps it.
     */
    public function __invoke(Recommendation $recommendation, FeedbackEvent $event, array $metadata = [], ?DateTimeInterface $occurredAt = null): void
    {
        $this->ledger->record($recommendation->id, $event, $metadata, $occurredAt ?? now());

        $facets = $recommendation->score_inputs['candidate']['facets'] ?? [];
        $this->profiles->learnFromFeedback($recommendation->user_id, $event, $facets);
    }
}
