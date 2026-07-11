<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Actions;

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Feedback\Services\FeedbackLedger;
use App\Domain\Profiles\Services\TasteProfiles;
use App\Domain\Recommendations\Models\Recommendation;

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

    /** @param array<string, mixed> $metadata */
    public function __invoke(Recommendation $recommendation, FeedbackEvent $event, array $metadata = []): void
    {
        $this->ledger->record($recommendation->id, $event, $metadata, now());

        $facets = $recommendation->score_inputs['candidate']['facets'] ?? [];
        $this->profiles->learnFromFeedback($recommendation->user_id, $event, $facets);
    }
}
